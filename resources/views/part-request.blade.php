<?php

use App\Models\Request as StockRequest;
use App\Models\RequestList;
use App\Models\Singlepart;
use App\Models\User;
use App\Notifications\NewPartRequestNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Carbon\Carbon;

new
#[Layout('components.layouts.app')]
#[Title('Buat Permintaan')]
class extends Component
{
    use Toast;

    public const SCANNED_ITEMS_SESSION_KEY = 'scanned_items';

    // --- COMPONENT STATE ---

    #[Validate('required|string')]
    public string $destination;

    public ?int $child_part_id = null;
    public array $partsSearchable = [];
    public array $items = [];
    public bool $showConfirmModal = false;

    // --- LIFECYCLE HOOKS ---

    public function mount(): void
    {
        if (!auth()->user()->can('manage') && !auth()->user()->can('receiver')) {
            redirect('/')->with('error', 'Anda tidak memiliki izin untuk mengakses halaman ini.');
            return;
        }

        $this->destination = $this->destinationOptions()[0]['name'] ?? '';
        $this->loadItemsFromSession();
    }

    // --- EVENT LISTENERS ---

    #[On('scan-success')]
    public function scanSuccess(string $partNumber): void
    {
        $partNumber = trim($partNumber);
        if (empty($partNumber)) {
            return;
        }
        $this->addItemByPartNumber($partNumber);
    }

    // --- DATA METHODS ---

    public function search(string $query = ''): void
    {
        // Optimasi: Hash key lebih pendek dan trim query di awal
        $q = trim($query);
        if (strlen($q) < 2) {
            $this->partsSearchable = [];
            return;
        }

        $cacheKey = 'part_search_' . md5(strtolower($q));
        
        $this->partsSearchable = Cache::tags(['part_search'])->remember($cacheKey, now()->addHours(6), function () use ($q) {
            return Singlepart::query()
                ->where('part_number', 'like', "%$q%")
                ->select('id', 'part_number as name') // Select only needed columns
                ->orderBy('name')
                ->limit(10) // Limit increased slightly, but strict
                ->get()
                ->toArray();
        });
    }

    public function addManualItem(): void
    {
        $this->validate([
            'child_part_id' => 'required|exists:singleparts,id',
        ]);

        // Eager load tidak diperlukan di sini karena hanya butuh part_number
        $part = Singlepart::select('id', 'part_number')->find($this->child_part_id);
        if ($part) {
            $this->addItemByPartNumber($part->part_number);
        } else {
            $this->error('Part tidak ditemukan.');
        }
    }

    public function clearAllItems(): void
    {
        session()->forget(self::SCANNED_ITEMS_SESSION_KEY);
        $this->loadItemsFromSession();
        $this->info('Semua item telah dihapus.');
    }

    public function removeItem(string $part_number): void
    {
        $items = session(self::SCANNED_ITEMS_SESSION_KEY, []);
        if (isset($items[$part_number])) {
            unset($items[$part_number]);
            session([self::SCANNED_ITEMS_SESSION_KEY => $items]);
            $this->loadItemsFromSession();
            $this->success("Item {$part_number} dihapus.");
        }
    }

    public function setUrgent(string $partNumber, bool $isUrgent): void
    {
        $items = session(self::SCANNED_ITEMS_SESSION_KEY, []);
        if (! isset($items[$partNumber])) {
            return;
        }

        // Optimasi: Hanya update session jika value berubah
        if (($items[$partNumber]['is_urgent'] ?? false) !== $isUrgent) {
            $items[$partNumber]['is_urgent'] = $isUrgent;
            session([self::SCANNED_ITEMS_SESSION_KEY => $items]);
            $this->loadItemsFromSession();
        }
    }

    // --- SUBMISSION LOGIC ---

    public function confirmSubmit(): void
    {
        if (empty($this->items)) {
            $this->error('Minimal 1 item harus ditambahkan.');
            return;
        }
        $this->validate();
        $this->showConfirmModal = true;
    }

    public function submitRequest(): void
    {
        $items = session(self::SCANNED_ITEMS_SESSION_KEY, []);
        if (empty($items)) {
            $this->error('Tidak ada item untuk disimpan.');
            return;
        }

        try {
            $request = null;
            $itemCount = count($items);
            $totalQuantity = array_sum(array_column($items, 'quantity'));

            DB::transaction(function () use ($items, &$request): void {
                $request = StockRequest::create([
                    'requested_by' => Auth::id(),
                    'requested_at' => now(),
                    'destination' => $this->destination,
                    'status' => 'pending',
                ]);

                // Optimasi: Prepare data for bulk insert if needed, 
                // but keep loop for now as per "no logic change" strictness.
                foreach ($items as $item) {
                    RequestList::create([
                        'request_id' => $request->id,
                        'part_id' => $item['child_part_id'],
                        'quantity' => $item['quantity'],
                        'is_urgent' => $item['is_urgent'] ?? false,
                        'status' => 'pending',
                    ]);
                }
            });

            // Send notifications (Optimized query)
            try {
                // Optimasi: Select ID saja untuk User, eager load tidak diperlukan untuk notifikasi massal
                $notifiableUsers = User::notifiable()
                    ->select('id', 'telegram_user_id')
                    ->with('pushSubscriptions')
                    ->get();

                if ($notifiableUsers->isNotEmpty() && $request) {
                    Notification::send(
                        $notifiableUsers,
                        new NewPartRequestNotification($request, $itemCount, $totalQuantity)
                    );
                }
            } catch (\Exception $notifError) {
                \Illuminate\Support\Facades\Log::warning('Push notification failed', [
                    'request_id' => $request?->id,
                    'error' => $notifError->getMessage(),
                ]);
            }

            session()->forget(self::SCANNED_ITEMS_SESSION_KEY);
            $this->loadItemsFromSession();
            $this->showConfirmModal = false;
            $this->success('Permintaan berhasil dikirim!');

        } catch (\Exception $e) {
            $this->error('Gagal menyimpan permintaan: ' . $e->getMessage());
        }
    }

    // --- HELPER & UTILITY METHODS ---

    protected function addItemByPartNumber(string $partNumber): void
    {
        $cacheKey = 'part_details_' . md5(strtolower($partNumber));
        
        // OPTIMIZED: Cache part lookup with scope
        $part = Cache::tags(['part_search'])->remember($cacheKey, now()->addDay(), function () use ($partNumber) {
            return Singlepart::searchByCode($partNumber)->first();
        });

        if (!$part) {
            $this->error("Part `{$partNumber}` tidak ditemukan di master data.");
            return;
        }

        // OPTIMIZED: Efficient exists check with composite condition
        $isAlreadyRequested = RequestList::where('part_id', $part->id)
            ->where('status', 'pending')
            ->whereHas('request', function ($query) {
                $query->where('destination', $this->destination)
                    ->whereIn('status', ['pending', 'partial']);
            })
            ->exists();

        if ($isAlreadyRequested) {
            $this->toast(
                type: 'warning',
                title: 'Part sudah diminta',
                description: "Part ini sudah diminta untuk {$this->destination} dan sedang menunggu supply.",
                position: 'toast-top toast-end',
                timeout: 4000
            );
            return;
        }

        $items = session(self::SCANNED_ITEMS_SESSION_KEY, []);

        $items[$part->part_number] = [
            'child_part_id' => $part->id,
            'part_number' => $part->part_number,
            'quantity' => ($items[$part->part_number]['quantity'] ?? 0) + 1,
            'is_urgent' => $items[$part->part_number]['is_urgent'] ?? false,
        ];

        // OPTIMIZED: Only write session if data changed
        $current = session(self::SCANNED_ITEMS_SESSION_KEY, []);
        if ($current !== $items) {
            session([self::SCANNED_ITEMS_SESSION_KEY => $items]);
        }

        $this->success("{$part->part_number} ditambahkan (Total: {$items[$part->part_number]['quantity']} KBN).");
        $this->loadItemsFromSession();
        $this->reset('child_part_id');
    }

    public function loadItemsFromSession(): void
    {
        $this->items = array_values(session(self::SCANNED_ITEMS_SESSION_KEY, []));
    }

    public function destinationOptions(): array
    {
        return [
            ['id' => 'Line KS', 'name' => 'Line KS'],
            ['id' => 'Line SU', 'name' => 'Line SU'],
        ];
    }

    public function totalItems(): int
    {
        return array_sum(array_column($this->items, 'quantity'));
    }

    public function hasItems(): bool
    {
        return !empty($this->items);
    }
};
?>

<div class="min-h-screen text-gray-900 dark:text-gray-100/90">
        <x-header title="Buat Permintaan" subtitle="Form permintaan single parts" icon="o-clipboard-document-list" separator />
    <div class="max-w-6xl mx-auto space-y-8">

    <!-- Row 1: Scan Part & Input Manual | Detail Permintaan -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
        <!-- Left Side: Scan Part & Input Manual (2/3 width) -->
        <div class="xl:col-span-2 grid grid-cols-1 lg:grid-cols-2 gap-6">
            
            <!-- Scan Part Card - OPTIMIZED: wire:ignore added to prevent re-render glitches -->
            <div wire:ignore x-data="scanner()" x-init="init()">
                <x-card title="Scan Part" shadow class="h-full bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10">
                    <x-slot:menu>
                        <div class="flex items-center gap-2 px-3 py-1 rounded-full backdrop-blur bg-white/10 border border-white/10 shadow-md">
                            <div class="w-2 h-2 rounded-full shadow-lg shadow-green-500/40" :class="isScanning ? 'bg-emerald-400 animate-pulse' : 'bg-gray-400'"></div>
                            <span class="text-xs text-gray-900 dark:text-white font-semibold" x-text="isScanning ? 'Memindai' : 'Nonaktif'"></span>
                        </div>
                    </x-slot:menu>

                    <!-- Scanner Viewport -->
                    <div class="mb-6 relative w-full aspect-square bg-slate-900/40 rounded-2xl border border-white/10 flex items-center justify-center overflow-hidden shadow-inner">
                        <span class="absolute inset-3 border border-white/10 rounded-xl"></span>
                        <span class="absolute inset-6 border border-indigo-500/40 rounded-lg"></span>
                        <span class="absolute left-4 top-4 h-10 w-10 border-t-2 border-l-2 border-indigo-300/80 rounded-tl-xl"></span>
                        <span class="absolute right-4 top-4 h-10 w-10 border-t-2 border-r-2 border-indigo-300/80 rounded-tr-xl"></span>
                        <span class="absolute left-4 bottom-4 h-10 w-10 border-b-2 border-l-2 border-indigo-300/80 rounded-bl-xl"></span>
                        <span class="absolute right-4 bottom-4 h-10 w-10 border-b-2 border-r-2 border-indigo-300/80 rounded-br-xl"></span>
                        <video id="qr-video" playsinline class="w-full h-full object-cover" :class="{'opacity-100': isScanning, 'opacity-0': !isScanning}"></video>
                        <canvas id="detection-canvas" class="absolute top-0 left-0 w-full h-full"></canvas>
                        <canvas id="qr-canvas" class="hidden"></canvas>
                        <div x-show="!isScanning && !cameraError" class="absolute text-center p-6">
                            <template x-if="!isPaused">
                                <div class="space-y-3">
                                    <div class="mx-auto w-20 h-20 bg-gradient-to-br from-blue-500 to-purple-600 rounded-2xl flex items-center justify-center">
                                        <x-icon name="o-qr-code" class="w-10 h-10 text-white" />
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-700 dark:text-gray-300">Arahkan kamera ke QR Code</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Setiap scan menambah 1 KBN</p>
                                    </div>
                                </div>
                            </template>
                            <template x-if="isPaused">
                                <div class="space-y-3">
                                    <div class="mx-auto w-20 h-20 bg-green-500 rounded-2xl flex items-center justify-center">
                                        <x-icon name="o-check-circle" class="w-10 h-10 text-white" />
                                    </div>
                                    <div>
                                        <p class="font-semibold text-green-600 dark:text-green-400">Scan Berhasil!</p>
                                        <p class="text-sm text-gray-200/80">Aktif lagi dalam <span x-text="countdown" class="text-2xl font-bold text-blue-200"></span> detik</p>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div x-show="cameraError" class="absolute text-center p-4">
                            <div class="mx-auto w-20 h-20 bg-red-500 rounded-2xl flex items-center justify-center mb-3">
                                <x-icon name="o-exclamation-triangle" class="w-10 h-10 text-white" />
                            </div>
                            <p class="text-red-600 dark:text-red-400" x-text="cameraError"></p>
                        </div>
                    </div>

                    <x-slot:actions>
                        <x-button x-show="!isScanning && !isPaused" @click="start()" icon="o-camera" class="btn-primary w-full shadow-lg shadow-blue-500/30" label="Mulai Scanner" />
                        <x-button x-show="isPaused" icon="o-clock" class="btn-outline w-full text-blue-200 border-blue-400/50" x-bind:label="`Cooldown... ${countdown}s`" />
                        <x-button x-show="isScanning" @click="stop()" icon="o-stop-circle" class="btn-warning w-full shadow-lg shadow-orange-500/30" label="Hentikan Scanner" />
                    </x-slot:actions>
                </x-card>
            </div>

            <!-- Input Manual Card -->
            <x-card title="Input Manual" shadow class="relative z-30 h-full bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10">
                <x-slot:menu>
                    <div class="flex items-center gap-2 px-2 py-1 rounded-full bg-gradient-to-r from-sky-500/30 to-indigo-500/30 text-sky-100">
                        <x-icon name="o-pencil-square" class="w-4 h-4" />
                        <span class="text-xs font-semibold">Manual</span>
                    </div>
                </x-slot:menu>
                <div class="space-y-6 relative z-[60]">
                    <div>
                        {{-- Optimasi: debounce 300ms untuk mengurangi request ke server saat mengetik --}}
                        <x-choices
                            label="Pilih Part Number"
                            wire:model.live.debounce.300ms="child_part_id"
                            :options="$partsSearchable"
                            placeholder="Ketik untuk mencari..."
                            wire:search="search"
                            searchable
                            single
                            min-chars="2"
                            class="select-bordered"
                        />
                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            <x-icon name="o-information-circle" class="w-3 h-3 inline mr-1" />
                            Ketik minimal 2 karakter untuk pencarian
                        </div>
                    </div>
                    <x-button wire:click="addManualItem" icon="o-plus" class="btn-primary w-full" :disabled="!$child_part_id" wire:loading.attr="disabled" spinner="addManualItem" label="Tambah ke Daftar" />
                </div>
            </x-card>
        </div>

        <!-- Right Side: Detail Permintaan -->
        <div class="xl:col-span-1">
            <x-card title="Detail Permintaan" shadow class="h-full bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 sticky top-4">
                <x-slot:menu>
                    <div class="flex items-center gap-2 px-2 py-1 rounded-full bg-gradient-to-r from-purple-500/30 to-indigo-500/30 text-purple-100">
                        <div class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></div>
                        <span class="text-xs font-semibold">Wajib diisi</span>
                    </div>
                </x-slot:menu>
                <div class="space-y-6">
                    <div>
                        <x-select label="Pilih Tujuan" :options="$this->destinationOptions()" wire:model="destination" placeholder="Pilih tujuan pengiriman" class="select-bordered" />
                    </div>
                    @php($urgentCount = collect($items)->where('is_urgent', true)->count())
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-blue-200 dark:border-white/10 bg-blue-50 dark:bg-white/5 p-3">
                            <div class="text-xs text-blue-700 dark:text-gray-300">Total Item</div>
                            <div class="text-2xl font-bold text-blue-900 dark:text-white">{{ $this->totalItems() }}</div>
                            <div class="text-[11px] text-emerald-700 dark:text-emerald-300/80">Siap dikirim</div>
                        </div>
                        <div class="rounded-xl border border-rose-200 dark:border-white/10 bg-rose-50 dark:bg-white/5 p-3">
                            <div class="text-xs text-rose-700 dark:text-gray-300">Urgent</div>
                            <div class="text-2xl font-bold text-rose-700 dark:text-rose-200">{{ $urgentCount }}</div>
                            <div class="text-[11px] text-rose-700 dark:text-rose-200/80">Menunggu prioritas</div>
                        </div>
                    </div>
                    <div class="rounded-lg p-4 border border-blue-200/60 dark:border-blue-800/80 bg-gradient-to-r from-indigo-100 dark:from-indigo-900/40 via-blue-100 dark:via-blue-900/30 to-sky-100 dark:to-sky-800/30">
                        <div class="flex items-center justify-between text-sm text-blue-900 dark:text-white">
                            <div class="flex items-center gap-2">
                                <x-icon name="o-information-circle" class="w-4 h-4 text-sky-700 dark:text-sky-200" />
                                <span class="text-sky-800 dark:text-sky-100">Status Form</span>
                            </div>
                            @if($this->hasItems())
                                <span class="px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-800 dark:text-emerald-100 border border-emerald-400/40">Siap Kirim</span>
                            @else
                                <span class="px-3 py-1 rounded-full bg-amber-500/20 text-amber-800 dark:text-amber-100 border border-amber-400/40">Perlu Item</span>
                            @endif
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>

    <!-- Row 2: Item Diminta -->
    <div class="mb-8">
        <x-card shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950">
            <x-slot:title>
                <div class="flex items-center justify-between w-full">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-primary/10 rounded-lg"><x-icon name="o-cube" class="w-5 h-5 text-primary" /></div>
                        <div>
                            <h3 class="font-bold text-lg">Item Diminta</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ count($items) }} jenis part</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        @if($this->hasItems())
                            <div class="text-right">
                                <div class="text-2xl font-bold text-primary">{{ $this->totalItems() }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">Total KBN</div>
                            </div>
                        @endif
                    </div>
                </div>
            </x-slot:title>
            <x-slot:menu>
                @if($this->hasItems())
                    <x-button icon="o-trash" wire:click="clearAllItems" class="btn-ghost btn-sm text-error hover:bg-error/10" wire:confirm="Yakin ingin menghapus SEMUA item?" label="Hapus Semua" spinner />
                @endif
            </x-slot:menu>
            @if($this->hasItems())
                <div class="space-y-3 max-h-[60vh] overflow-y-auto pr-1">
                    @foreach($items as $index => $item)
                        {{-- Optimasi: wire:key unik untuk performa diffing Livewire --}}
                        <div class="relative flex items-start justify-between p-5 rounded-2xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 backdrop-blur hover:-translate-y-0.5 hover:shadow-2xl transition-all duration-200" wire:key="item-{{ $item['part_number'] }}">
                            <span class="absolute left-0 top-0 h-full w-1 bg-gradient-to-b from-blue-400 via-indigo-400 to-purple-500"></span>
                            <div class="flex items-start gap-3 pl-2">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-500 flex items-center justify-center text-sm font-semibold text-white shadow-lg shadow-blue-500/30">{{ $index + 1 }}</div>
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-semibold text-base text-gray-900 dark:text-white">{{ $item['part_number'] }}</span>
                                        @if($item['is_urgent'] ?? false)
                                            <x-badge value="Urgent" class="badge-error badge-outline animate-pulse" />
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600 dark:text-gray-200/80">
                                        <span class="flex items-center gap-1"><x-icon name="o-cube" class="w-4 h-4" /> {{ $item['quantity'] }} KBN</span>
                                        <span class="flex items-center gap-1"><x-icon name="o-clock" class="w-4 h-4" /> Ditambahkan {{ now()->diffForHumans() }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 pl-4 border-l border-gray-200 dark:border-white/10">
                                <x-checkbox
                                    label="Tandai Urgent"
                                    :checked="$item['is_urgent'] ?? false"
                                    wire:change="setUrgent('{{ $item['part_number'] }}', $event.target.checked)"
                                    right
                                    aria-label="Tandai urgent untuk {{ $item['part_number'] }}"
                                    class="text-gray-900 dark:text-white"
                                />
                                <x-button
                                    icon="o-trash"
                                    wire:click="removeItem('{{ $item['part_number'] }}')"
                                    class="btn-circle btn-sm bg-rose-500/20 border border-rose-400/40 text-rose-700 dark:text-rose-100 hover:bg-rose-500/40"
                                    wire:confirm="Yakin ingin menghapus item ini?"
                                    spinner
                                    aria-label="Hapus {{ $item['part_number'] }}"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-16">
                    <div class="mx-auto w-24 h-24 bg-gradient-to-br from-gray-200 to-gray-300 dark:from-gray-700 dark:to-gray-800 rounded-3xl flex items-center justify-center mb-6"><x-icon name="o-inbox" class="w-12 h-12 text-gray-500 dark:text-gray-400" /></div>
                    <div class="max-w-sm mx-auto">
                        <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-300 mb-2">Belum Ada Item</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-4">Scan QR code atau pilih manual untuk menambahkan part ke daftar permintaan.</p>
                        <div class="flex items-center justify-center gap-4 text-sm text-gray-600 dark:text-gray-400">
                            <div class="flex items-center gap-2"><x-icon name="o-qr-code" class="w-4 h-4" /><span>Scan QR</span></div>
                            <div class="w-px h-4 bg-gray-400 dark:bg-gray-300"></div>
                            <div class="flex items-center gap-2"><x-icon name="o-pencil-square" class="w-4 h-4" /><span>Input Manual</span></div>
                        </div>
                        <div class="mt-6 flex justify-center">
                            <x-button icon="o-camera" class="btn-primary" x-on:click="window.dispatchEvent(new CustomEvent('scan-start'))" label="Mulai Scan" />
                        </div>
                    </div>
                </div>
            @endif
        </x-card>
    </div>

    <!-- Form Actions -->
    <form wire:submit="confirmSubmit">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-6 bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 rounded-xl border border-gray-200 dark:border-gray-700 shadow-lg">
            <div class="flex items-center gap-3">
                <x-icon name="o-information-circle" class="w-5 h-5 text-blue-500" />
                <div>
                    <div class="font-medium text-gray-700 dark:text-gray-300">
                        @if($this->hasItems())
                            Siap untuk mengirim {{ count($items) }} jenis part
                        @else
                            Tambahkan minimal 1 item untuk melanjutkan
                        @endif
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        @if($this->hasItems())
                            Total {{ $this->totalItems() }} KBN akan dikirim ke {{ $destination ?? 'tujuan yang dipilih' }}
                        @else
                            Gunakan scanner atau input manual di atas
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex gap-3 flex-col sm:flex-row w-full sm:w-auto">
                <x-button label="Kembali" icon="o-arrow-left" class="btn-outline" link="{{ url()->previous() }}" />
                <x-button type="submit" label="Kirim Permintaan" icon-right="o-paper-airplane" class="btn-primary w-full sm:w-auto" :disabled="!$this->hasItems()" spinner="confirmSubmit" />
            </div>
        </div>
    </form>

    <!-- Confirmation Modal -->
    <x-modal wire:model="showConfirmModal" title="Konfirmasi Pengiriman" persistent separator class="backdrop-blur-sm">
        <div class="space-y-4">
            <div class="bg-blue-50 dark:bg-blue-950/30 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                <div class="flex items-start gap-3">
                    <x-icon name="o-information-circle" class="w-5 h-5 text-blue-600 mt-0.5" />
                    <div>
                        <h4 class="font-semibold text-blue-900 dark:text-blue-100">Detail Permintaan</h4>
                        <div class="mt-2 space-y-1 text-sm text-blue-800 dark:text-blue-200">
                            <div>• <strong>{{ count($items) }} jenis part</strong> dengan total <strong>{{ $this->totalItems() }} KBN</strong></div>
                            <div>• Tujuan: <strong>{{ $destination ?? '-' }}</strong></div>
                            <div>• Tanggal: <strong>{{ now()->translatedFormat('d F Y, H:i') ?? '-' }}</strong></div>
                        </div>
                    </div>
                </div>
            </div>
            <p class="text-gray-700 dark:text-gray-300">Apakah semua data sudah benar dan Anda yakin ingin mengirim permintaan ini?</p>
        </div>
        <x-slot:actions>
            <x-button label="Periksa Ulang" @click="$wire.showConfirmModal = false" class="btn-outline" icon="o-arrow-left" />
            <x-button label="Ya, Kirim Sekarang" class="btn-primary" wire:click="submitRequest" spinner="submitRequest" icon="o-paper-airplane" />
        </x-slot:actions>
    </x-modal>
    </div>
</div>

@push('scripts')
<script>
    // OPTIMASI: SCANNER PERFORMANCE FIX
    // Mengganti frame-based loop (requestAnimationFrame) dengan interval-based (setInterval)
    // untuk mengurangi beban CPU dan panas pada device.
    function scanner() {
        return {
            isScanning: false,
            isPaused: false,
            cooldownSeconds: 3,
            countdown: 0,
            cameraError: null,
            video: null,
            canvas: null,
            canvasCtx: null,
            detectionCanvas: null,
            detectionCanvasCtx: null,
            stream: null,
            scanInterval: null, // Diganti dari animationFrameId
            lastDetectionTime: 0,

            init() {
                if (typeof jsQR === 'undefined') {
                    this.cameraError = 'Scanner library (jsQR) not loaded. Please import it.';
                    return;
                }
                this.video = document.getElementById('qr-video');
                this.canvas = document.getElementById('qr-canvas');
                this.detectionCanvas = document.getElementById('detection-canvas');
                // Optimasi: willReadFrequently untuk akses pixel lebih cepat
                this.canvasCtx = this.canvas.getContext('2d', { willReadFrequently: true });
                this.detectionCanvasCtx = this.detectionCanvas.getContext('2d');
                
                document.addEventListener('livewire:navigating', () => {
                    this.stop();
                });

                window.addEventListener('scan-start', () => {
                    this.start();
                });
            },
            
            drawLine(begin, end, color) {
                if(!this.detectionCanvasCtx) return;
                this.detectionCanvasCtx.beginPath();
                this.detectionCanvasCtx.moveTo(begin.x, begin.y);
                this.detectionCanvasCtx.lineTo(end.x, end.y);
                this.detectionCanvasCtx.lineWidth = 4;
                this.detectionCanvasCtx.strokeStyle = color;
                this.detectionCanvasCtx.stroke();
            },

            start() {
                if (this.isPaused) return;
                this.cameraError = null;
                // Bersihkan canvas
                if (this.detectionCanvasCtx) {
                    this.detectionCanvasCtx.clearRect(0, 0, this.detectionCanvas.width, this.detectionCanvas.height);
                }

                navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        facingMode: "environment",
                        width: { ideal: 480 }, // Resolusi moderat cukup untuk QR
                        height: { ideal: 640 }
                    } 
                })
                    .then((stream) => {
                        this.isScanning = true;
                        this.stream = stream;
                        this.video.srcObject = stream;
                        // Tunggu video ready state
                        this.video.onloadedmetadata = () => {
                            this.video.play();
                            // Optimasi: Jalankan scan loop setiap 150ms ( ~6-7 FPS) cukup untuk QR
                            // Jauh lebih ringan daripada 60 FPS requestAnimationFrame
                            this.scanInterval = setInterval(this.tick.bind(this), 150);
                        };
                    })
                    .catch((err) => {
                        this.isScanning = false;
                        this.cameraError = `Gagal memulai kamera: ${err.message}. Pastikan izin kamera aktif.`;
                    });
            },

            tick() {
                if (!this.isScanning || !this.video || this.video.readyState !== this.video.HAVE_ENOUGH_DATA) {
                    return;
                }

                // Sync canvas size
                if (this.canvas.width !== this.video.videoWidth) {
                    this.canvas.width = this.video.videoWidth;
                    this.canvas.height = this.video.videoHeight;
                    this.detectionCanvas.width = this.video.videoWidth;
                    this.detectionCanvas.height = this.video.videoHeight;
                }

                // Draw frame
                this.canvasCtx.drawImage(this.video, 0, 0, this.canvas.width, this.canvas.height);
                
                // Get image data
                const imageData = this.canvasCtx.getImageData(0, 0, this.canvas.width, this.canvas.height);
                
                // Scan
                const code = jsQR(imageData.data, imageData.width, imageData.height, {
                    inversionAttempts: "dontInvert",
                });

                if (code && code.data) {
                    this.drawLine(code.location.topLeftCorner, code.location.topRightCorner, "#FF3B58");
                    this.drawLine(code.location.topRightCorner, code.location.bottomRightCorner, "#FF3B58");
                    this.drawLine(code.location.bottomRightCorner, code.location.bottomLeftCorner, "#FF3B58");
                    this.drawLine(code.location.bottomLeftCorner, code.location.topLeftCorner, "#FF3B58");
                    
                    // Throttle success dispatch
                    const now = performance.now();
                    if (now - this.lastDetectionTime > 1000) { // Hanya kirim 1x per detik max
                         this.lastDetectionTime = now;
                         Livewire.dispatch('scan-success', { partNumber: code.data });
                         this.pauseScanner();
                    }
                }
            },

            stop() {
                this.isScanning = false;
                if (this.scanInterval) {
                    clearInterval(this.scanInterval);
                    this.scanInterval = null;
                }
                if (this.stream) {
                    this.stream.getTracks().forEach(track => track.stop());
                    this.stream = null;
                }
                if (this.video) {
                    this.video.srcObject = null;
                }
                if (this.detectionCanvasCtx && this.detectionCanvas) {
                    this.detectionCanvasCtx.clearRect(0, 0, this.detectionCanvas.width, this.detectionCanvas.height);
                }
            },

            pauseScanner() {
                this.stop(); 
                this.isPaused = true;
                this.countdown = this.cooldownSeconds;
                this.countdownInterval = setInterval(() => {
                    this.countdown--;
                    if (this.countdown <= 0) {
                        clearInterval(this.countdownInterval);
                        this.isPaused = false;
                        this.start();
                    }
                }, 1000);
            }
        };
    }
</script>
@endpush