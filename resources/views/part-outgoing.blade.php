<?php

use App\Models\Outgoing;
use App\Models\Request;
use App\Models\RequestList;
use App\Models\Singlepart;
use App\Models\Movement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new
#[Layout('components.layouts.app')]
#[Title('Pengeluaran Barang')]
class extends Component
{
    use Toast;

    public const BATCH_SESSION_KEY = 'outgoing_batch';

    public string $scannedCode = '';
    public ?int $child_part_id = null;
    public array $partsSearchable = [];
    public array $batchItems = [];
    public bool $showConfirmModal = false;
    public string $outgoingNumber = '';

    // --- LIFECYCLE HOOKS ---

    public function mount(): void
    {
        if (!auth()->user()->can('manage') && !auth()->user()->can('outgoer')) {
            redirect('/')->with('error', 'Anda tidak memiliki izin untuk mengakses halaman ini.');
            return;
        }

        $this->loadBatchFromSession();
        $this->generateOutgoingNumber();
    }

    // --- EVENT LISTENERS ---

    #[On('scan-success')]
    public function handleScan(string $code): void
    {
        $this->addToBatch($code);
    }

    // --- BATCH MANAGEMENT ---

    public function search(string $query = ''): void
    {
        // Optimasi search logic
        $q = trim($query);
        if (strlen($q) < 2) {
            $this->partsSearchable = [];
            return;
        }

        $cacheKey = 'part_search_' . md5(strtolower($q));
        
        $this->partsSearchable = Cache::tags(['part_search'])->remember($cacheKey, now()->addHours(6), function () use ($q) {
            return Singlepart::query()
                ->where('part_number', 'like', "%$q%")
                ->select('id', 'part_number as name')
                ->orderBy('name')
                ->limit(10)
                ->get()
                ->toArray();
        });
    }

    public function addManualItem(): void
    {
        $this->validate([
            'child_part_id' => 'required|exists:singleparts,id',
        ]);

        $part = Singlepart::select('id', 'part_number')->find($this->child_part_id);
        if ($part) {
            $this->addToBatch($part->part_number);
            $this->reset('child_part_id');
        } else {
            $this->error('Part tidak ditemukan.');
        }
    }

    public function addToBatch(string $code): void
    {
        $scannedCode = trim($code);
        if (empty($scannedCode)) {
            return;
        }

        if (isset($this->batchItems[$scannedCode])) {
            $this->warning("Part `{$scannedCode}` sudah ada di dalam batch.");
            return;
        }

        // OPTIMIZED: Using scopes for cleaner query
        $part = Singlepart::query()
            ->select('id', 'part_number', 'part_name', 'stock', 'is_active', 'standard_packing')
            ->active()
            ->inStock()
            ->searchByCode($scannedCode)
            ->first();

        if (!$part) {
            $this->error("Part dengan kode `{$scannedCode}` tidak ditemukan atau stok habis.");
            return;
        }

        // OPTIMIZED: Improved request item query
        $requestItem = RequestList::query()
            ->select('request_lists.id', 'request_lists.request_id')
            ->pending()
            ->forPart($part->id)
            ->activeRequests() // Uses whereHas with Request::active() scope
            ->with(['request' => function($q) {
                $q->select('id', 'destination', 'requested_at')
                ->orderBy('requested_at', 'asc');
            }])
            ->first();
            
        $qtyToIssue = min($part->standard_packing ?? 1, $part->stock);

        $this->batchItems[$scannedCode] = [
            'part_id'         => $part->id,
            'request_list_id' => $requestItem?->id,
            'part_number'     => $part->part_number,
            'part_name'       => $part->part_name,
            'stock'           => $part->stock,
            'qty_to_issue'    => $qtyToIssue,
            'has_request'     => (bool)$requestItem,
            'destination'     => $requestItem?->request->destination,
            'request_id'      => $requestItem?->request_id,
        ];

        $this->updateSession();
        $this->success("Part `{$part->part_number}` ditambahkan ke batch.");
        $this->reset('scannedCode');
    }

    public function updateQuantity(string $code, $newQuantity): void
    {
        if (!isset($this->batchItems[$code])) {
            return;
        }

        $item = $this->batchItems[$code];
        $validatedQty = filter_var($newQuantity, FILTER_VALIDATE_INT);

        if ($validatedQty === false || $validatedQty <= 0) {
            $this->batchItems[$code]['qty_to_issue'] = 1;
        } elseif ($validatedQty > $item['stock']) {
            $this->batchItems[$code]['qty_to_issue'] = $item['stock'];
            $this->warning("Kuantitas tidak boleh melebihi stok tersedia ({$item['stock']}).");
        } else {
            $this->batchItems[$code]['qty_to_issue'] = $validatedQty;
        }

        $this->updateSession();
    }

    public function removeFromBatch(string $code): void
    {
        unset($this->batchItems[$code]);
        $this->updateSession();
        $this->info("Item telah dihapus dari batch.");
    }

    public function clearBatch(): void
    {
        $this->batchItems = [];
        $this->updateSession();
        $this->warning("Semua item dalam batch telah dibersihkan.");
    }

    // --- SUBMISSION ---

    public function confirmSubmit(): void
    {
        if (empty($this->batchItems)) {
            $this->error("Batch kosong, tidak ada yang bisa disubmit.");
            return;
        }
        $this->showConfirmModal = true;
    }

    public function submitBatch(): void
    {
        $batch = $this->batchItems;
        if (empty($batch)) {
            $this->error('Batch kosong, tidak ada yang bisa disubmit.');
            return;
        }

        DB::beginTransaction();
        try {
            foreach ($batch as $itemData) {
                // Lock for update to prevent race conditions
                $part = Singlepart::lockForUpdate()->find($itemData['part_id']);
                
                if (!$part) {
                    throw new \Exception("Part dengan ID {$itemData['part_id']} tidak ditemukan.");
                }

                if ($part->stock < $itemData['qty_to_issue']) {
                    throw new \Exception("Stok tidak cukup untuk part {$part->part_number}. Tersedia: {$part->stock}, diminta: {$itemData['qty_to_issue']}.");
                }

                Outgoing::create([
                    'outgoing_number' => $this->outgoingNumber,
                    'part_id'         => $part->id,
                    'quantity'        => $itemData['qty_to_issue'],
                    'dispatched_by'   => Auth::id(),
                    'dispatched_at'   => now(),
                ]);

                $oldStock = $part->stock;
                $part->decrement('stock', $itemData['qty_to_issue']);

                Movement::create([
                    'part_id' => $part->id,
                    'type' => 'out',
                    'pic' => Auth::id(),
                    'qty' => $itemData['qty_to_issue'],
                    'final_qty' => $oldStock - $itemData['qty_to_issue'],
                ]);

                if ($itemData['has_request'] && $itemData['request_list_id']) {
                    $requestItem = RequestList::find($itemData['request_list_id']);
                    if ($requestItem) {
                        $newFulfilledQty = min(
                            $requestItem->quantity,
                            $itemData['qty_to_issue']
                        );

                        if ($newFulfilledQty >= $requestItem->quantity) {
                            $requestItem->update(['status' => 'fulfilled']);
                        }

                        $this->updateRequestStatus($requestItem->request_id);
                    }
                }
            }

            DB::commit();

            $totalQty = $this->totalBatchQuantity();
            $totalItems = $this->totalBatchItems();
            $this->success("Batch #{$this->outgoingNumber} berhasil diproses! {$totalQty} Pcs dari {$totalItems} jenis part telah dikeluarkan.");
            $this->generateOutgoingNumber();
            $this->clearBatch();
            $this->showConfirmModal = false;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Batch submission failed', [
                'user_id' => Auth::id(),
                'outgoing_number' => $this->outgoingNumber,
                'error' => $e->getMessage(),
            ]);
            $this->error('Terjadi kesalahan sistem saat memproses batch.');
            $this->showConfirmModal = false;
        }
    }

    protected function updateRequestStatus(int $requestId): void
    {
        $request = Request::with('items')->find($requestId);
        if (!$request) {
            return;
        }

        $allFulfilled = $request->items->every(fn($item) => $item->status === 'fulfilled');
        $anyFulfilled = $request->items->contains(fn($item) => $item->status === 'fulfilled');

        if ($allFulfilled) {
            $request->update(['status' => 'fulfilled']);
        } elseif ($anyFulfilled) {
            $request->update(['status' => 'partial']);
        }
    }

    protected function generateOutgoingNumber(): void
    {
        $date = now()->format('Ymd');
        $cacheKey = "outgoing_last_number_{$date}";
        
        $lastNumber = Cache::remember($cacheKey, 60, function() use ($date) {
            $last = Outgoing::where('outgoing_number', 'LIKE', "OUT-{$date}-%")
                ->orderByDesc('outgoing_number')
                ->value('outgoing_number');
            
            return $last ? (int) substr($last, -4) : 0;
        });
        
        $newNumber = $lastNumber + 1;
        Cache::put($cacheKey, $newNumber, 60);
        
        $this->outgoingNumber = sprintf('OUT-%s-%04d', $date, $newNumber);
    }

    protected function loadBatchFromSession(): void
    {
        $this->batchItems = session(self::BATCH_SESSION_KEY, []);
    }

    protected function updateSession(): void
    {
        $current = session(self::BATCH_SESSION_KEY, []);
        // Only write to session if data has actually changed
        if ($current !== $this->batchItems) {
            session([self::BATCH_SESSION_KEY => $this->batchItems]);
        }
    }

    public function totalBatchItems(): int
    {
        return count($this->batchItems);
    }

    public function totalBatchQuantity(): int
    {
        return array_sum(array_column($this->batchItems, 'qty_to_issue'));
    }
};
?>

<div class="min-h-screen text-gray-900 dark:text-gray-100/90">
    <x-header title="Pengeluaran Barang" subtitle="Proses pengeluaran dan dispatch parts" icon="o-arrow-up-tray" separator />
    <div class="max-w-6xl mx-auto space-y-8">

    <!-- Section 1: Scanner & Details -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
        <!-- Left Side: Scanner & Manual Input (2/3 width) -->
        <div class="xl:col-span-2 grid grid-cols-1 lg:grid-cols-2 gap-6">
            
            <!-- Scanner Card - OPTIMIZED: wire:ignore -->
            <div wire:ignore x-data="scanner()" x-init="init()">
                <x-card title="Scan Part" shadow class="h-full bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10">
                    <x-slot:menu>
                        <div class="flex items-center gap-2 px-3 py-1 rounded-full backdrop-blur bg-white/10 border border-white/10 shadow-md">
                            <div class="w-2 h-2 rounded-full shadow-lg shadow-green-500/40" :class="isScanning ? 'bg-emerald-400 animate-pulse' : 'bg-gray-400'"></div>
                            <span class="text-xs text-gray-900 dark:text-white font-semibold" x-text="isScanning ? 'Memindai' : 'Nonaktif'"></span>
                        </div>
                    </x-slot:menu>

                    <!-- Scanner Viewport -->
                    <div class="mb-6 relative w-full aspect-square bg-gray-100 dark:bg-slate-900/40 rounded-2xl border border-gray-300 dark:border-white/10 flex items-center justify-center overflow-hidden shadow-inner">
                        <span class="absolute inset-3 border border-gray-200 dark:border-white/10 rounded-xl"></span>
                        <span class="absolute inset-6 border border-indigo-400 dark:border-indigo-500/40 rounded-lg"></span>
                        <span class="absolute left-4 top-4 h-10 w-10 border-t-2 border-l-2 border-indigo-600 dark:border-indigo-300/80 rounded-tl-xl"></span>
                        <span class="absolute right-4 top-4 h-10 w-10 border-t-2 border-r-2 border-indigo-600 dark:border-indigo-300/80 rounded-tr-xl"></span>
                        <span class="absolute left-4 bottom-4 h-10 w-10 border-b-2 border-l-2 border-indigo-600 dark:border-indigo-300/80 rounded-bl-xl"></span>
                        <span class="absolute right-4 bottom-4 h-10 w-10 border-b-2 border-r-2 border-indigo-600 dark:border-indigo-300/80 rounded-br-xl"></span>
                        <video id="qr-video" playsinline class="w-full h-full object-cover" :class="{'opacity-100': isScanning, 'opacity-0': !isScanning}"></video>
                        <canvas id="detection-canvas" class="absolute top-0 left-0 w-full h-full"></canvas>
                        <canvas id="qr-canvas" class="hidden"></canvas>

                        <!-- Scanner State Messages -->
                        <div x-show="!isScanning && !cameraError" class="absolute text-center p-6">
                            <template x-if="!isPaused">
                                <div class="space-y-3">
                                    <div class="mx-auto w-20 h-20 bg-gradient-to-br from-indigo-500/20 to-purple-500/20 rounded-2xl flex items-center justify-center mb-3 border border-indigo-400/30">
                                        <x-icon name="o-qr-code" class="w-10 h-10 text-indigo-600 dark:text-indigo-300" />
                                    </div>
                                    <p class="font-semibold text-gray-900 dark:text-white">Arahkan kamera ke QR Code</p>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Scanner siap digunakan</p>
                                </div>
                            </template>
                             <template x-if="isPaused">
                                <div class="space-y-3">
                                    <div class="mx-auto w-20 h-20 bg-gradient-to-br from-green-500/20 to-emerald-500/20 rounded-2xl flex items-center justify-center mb-3 border border-green-400/30">
                                        <x-icon name="o-check-circle" class="w-10 h-10 text-green-600 dark:text-green-300" />
                                    </div>
                                    <p class="font-semibold text-green-600 dark:text-green-300">Scan Berhasil!</p>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Aktif lagi dalam <span x-text="countdown" class="font-bold text-gray-900 dark:text-white"></span> detik</p>
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
            <x-card title="Input Manual" shadow class="relative z-30 h-full bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10">
                <x-slot:menu>
                    <div class="flex items-center gap-2 px-2 py-1 rounded-full bg-gradient-to-r from-sky-500/30 to-indigo-500/30 text-sky-100">
                        <x-icon name="o-pencil-square" class="w-4 h-4" />
                        <span class="text-xs font-semibold">Manual</span>
                    </div>
                </x-slot:menu>
                <div class="space-y-6">
                    <div>
                        {{-- Optimasi: debounce 300ms --}}
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
                    <x-button wire:click="addManualItem" icon="o-plus" class="btn-primary w-full" :disabled="!$child_part_id" wire:loading.attr="disabled" spinner="addManualItem" label="Tambah ke Batch" />
                </div>
            </x-card>
        </div>

        <!-- Right Side: Detail Batch -->
        <div class="xl:col-span-1">
            <x-card title="Detail Batch" shadow class="h-full bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 sticky top-4">
                <x-slot:menu>
                    <div class="flex items-center gap-2 px-2 py-1 rounded-full bg-gradient-to-r from-purple-500/30 to-indigo-500/30 text-purple-100">
                        <div class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></div>
                        <span class="text-xs font-semibold">Info Batch</span>
                    </div>
                </x-slot:menu>
                <div class="space-y-6">
                    @if($this->totalBatchItems() > 0)
                        <div>
                            <div class="text-xs text-gray-400 mb-1">Outgoing Number</div>
                            <div class="font-mono font-bold text-lg text-primary">{{ $outgoingNumber }}</div>
                        </div>
                    @endif
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-gray-300 dark:border-white/10 bg-gray-100 dark:bg-white/5 p-3">
                            <div class="text-xs text-gray-700 dark:text-gray-300">Total Jenis</div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $this->totalBatchItems() }}</div>
                            <div class="text-[11px] text-emerald-600 dark:text-emerald-300/80">Item berbeda</div>
                        </div>
                        <div class="rounded-xl border border-gray-300 dark:border-white/10 bg-gray-100 dark:bg-white/5 p-3">
                            <div class="text-xs text-gray-700 dark:text-gray-300">Total Qty</div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $this->totalBatchQuantity() }}</div>
                            <div class="text-[11px] text-blue-600 dark:text-blue-300/80">Pcs</div>
                        </div>
                    </div>
                    <div class="rounded-lg p-4 border border-blue-300 dark:border-blue-200/60 dark:border-blue-800/80 bg-gradient-to-r from-indigo-100 dark:from-indigo-900/40 via-blue-100 dark:via-blue-900/30 to-sky-100 dark:to-sky-800/30">
                        <div class="flex items-center justify-between text-sm text-gray-900 dark:text-white">
                            <div class="flex items-center gap-2">
                                <x-icon name="o-arrow-up-tray" class="w-4 h-4" />
                                <span>Status</span>
                            </div>
                            @if($this->totalBatchItems() > 0)
                                <span class="px-3 py-1 rounded-full bg-emerald-200 dark:bg-emerald-500/20 text-emerald-800 dark:text-emerald-100 border border-emerald-400 dark:border-emerald-400/40">Siap Proses</span>
                            @else
                                <span class="px-3 py-1 rounded-full bg-amber-200 dark:bg-amber-500/20 text-amber-800 dark:text-amber-100 border border-amber-400 dark:border-amber-400/40">Belum Ada</span>
                            @endif
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>

    <!-- Section 2: Batch List -->
    <div class="mb-8">
        <x-card shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950">
            <x-slot:title>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-primary/10 rounded-lg"><x-icon name="o-arrow-up-tray" class="w-5 h-5 text-primary" /></div>
                    <div>
                        <h3 class="font-bold text-lg text-gray-900 dark:text-white">Batch Pengeluaran</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Daftar part yang akan dikeluarkan</p>
                    </div>
                </div>
            </x-slot:title>
            <x-slot:menu>
                @if($this->totalBatchItems() > 0)
                    <x-button icon="o-trash" label="Bersihkan Batch" class="btn-ghost btn-sm text-error" wire:click="clearBatch" wire:confirm="Yakin ingin membersihkan semua item di batch?" />
                @endif
            </x-slot:menu>

            <div class="space-y-3 max-h-[60vh] overflow-y-auto pr-1">
                @forelse($batchItems as $code => $item)
                    {{-- Optimasi: wire:key untuk diffing --}}
                    <div wire:key="batch-{{ $code }}" class="relative flex items-start justify-between p-5 rounded-2xl border border-gray-300 dark:border-white/10 {{ !$item['has_request'] ? 'bg-yellow-100 dark:bg-warning/10' : 'bg-gray-100 dark:bg-white/5' }} backdrop-blur hover:-translate-y-0.5 hover:shadow-2xl transition-all duration-200">
                        <span class="absolute left-0 top-0 h-full w-1 bg-gradient-to-b {{ !$item['has_request'] ? 'from-yellow-400 via-orange-400 to-red-500' : 'from-blue-400 via-indigo-400 to-purple-500' }}"></span>
                        <div class="flex-1 pl-2">
                            <div class="flex items-start justify-between gap-4 mb-3">
                                <div>
                                    <div class="font-bold text-lg text-gray-900 dark:text-white">{{ $item['part_number'] }}</div>
                                    <div class="text-sm text-gray-600 dark:text-gray-400">{{ $item['part_name'] }}</div>
                                </div>
                                <x-button icon="o-x-mark" class="btn-circle btn-ghost btn-sm" wire:click="removeFromBatch('{{ $code }}')" />
                            </div>
                            <div class="flex flex-wrap gap-2 mb-4">
                                @if($item['has_request'])
                                    <x-badge value="Req #{{ $item['request_id'] }}" class="badge-info badge-outline" />
                                    <x-badge value="{{ $item['destination'] }}" class="badge-primary badge-outline" icon="o-map-pin" />
                                @else
                                    <x-badge value="Force Issue" class="badge-warning badge-outline" icon="o-exclamation-triangle" />
                                @endif
                            </div>
                            <div class="grid grid-cols-2 gap-4 items-end">
                                <div>
                                    <x-input
                                        label="Qty Keluar (Pcs)"
                                        wire:model.live.debounce.800ms="batchItems.{{ $code }}.qty_to_issue"
                                        wire:change="updateQuantity('{{ $code }}', $event.target.value)"
                                        type="number"
                                        min="1"
                                        max="{{ $item['stock'] }}"
                                        class="input-sm"
                                    />
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400 text-right">
                                    Stok tersedia: <span class="font-semibold text-gray-900 dark:text-white">{{ $item['stock'] }}</span> Pcs
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-16">
                        <div class="mx-auto w-24 h-24 bg-gradient-to-br from-gray-200 to-gray-300 dark:from-gray-700 dark:to-gray-800 rounded-3xl flex items-center justify-center mb-6">
                            <x-icon name="o-inbox" class="w-12 h-12 text-gray-500 dark:text-gray-400" />
                        </div>
                        <div class="max-w-sm mx-auto">
                            <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-300 mb-2">Belum Ada Item</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4">Scan QR code atau input manual untuk menambahkan part ke batch pengeluaran.</p>
                            <div class="flex items-center justify-center gap-4 text-sm text-gray-600 dark:text-gray-400">
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-qr-code" class="w-4 h-4" />
                                    <span>Scan QR</span>
                                </div>
                                <div class="w-px h-4 bg-gray-400 dark:bg-gray-300"></div>
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-pencil-square" class="w-4 h-4" />
                                    <span>Input Manual</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforelse
            </div>

            @if($this->totalBatchItems() > 0)
                <x-slot:actions>
                    <div class="w-full flex justify-between items-center p-4 bg-gradient-to-r from-indigo-100 dark:from-indigo-900/40 via-blue-100 dark:via-blue-900/30 to-sky-100 dark:to-sky-800/30 rounded-lg border border-blue-300 dark:border-blue-200/60 dark:border-blue-800/80">
                        <div>
                            <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $this->totalBatchItems() }} Jenis Item</div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">{{ $this->totalBatchQuantity() }} Pcs Total</div>
                        </div>
                        <x-button label="Submit Batch" icon-right="o-paper-airplane" class="btn-primary btn-lg shadow-lg shadow-blue-500/30" wire:click="confirmSubmit" spinner />
                    </div>
                </x-slot:actions>
            @endif
        </x-card>
    </div>

    <!-- Modal Konfirmasi Submit Batch -->
    <x-modal wire:model="showConfirmModal" title="Konfirmasi Pengeluaran Batch" persistent separator class="backdrop-blur-sm">
        <div class="space-y-4">
            <div class="bg-blue-50 dark:bg-blue-950/30 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                <div class="flex items-start gap-3">
                    <x-icon name="o-information-circle" class="w-5 h-5 text-blue-600 mt-0.5" />
                    <div>
                        <h4 class="font-semibold text-blue-900 dark:text-blue-100">Detail Batch</h4>
                        <div class="mt-2 space-y-1 text-sm text-blue-800 dark:text-blue-200">
                            <div>Outgoing Number: <span class="font-mono font-bold">{{ $outgoingNumber }}</span></div>
                            <div>Total Jenis: <span class="font-bold">{{ $this->totalBatchItems() }}</span> item</div>
                            <div>Total Quantity: <span class="font-bold">{{ $this->totalBatchQuantity() }}</span> Pcs</div>
                        </div>
                    </div>
                </div>
            </div>
            <p class="text-gray-700 dark:text-gray-300">Aksi ini akan mengurangi stok secara permanen dan membuat record pengeluaran. Pastikan semua data sudah benar.</p>
        </div>
        <x-slot:actions>
            <x-button label="Periksa Ulang" @click="$wire.showConfirmModal = false" class="btn-outline" icon="o-arrow-left" />
            <x-button label="Ya, Proses Sekarang" class="btn-primary" wire:click="submitBatch" spinner="submitBatch" icon="o-paper-airplane" />
        </x-slot:actions>
    </x-modal>
    </div>
</div>


@push('scripts')
<script>
    // OPTIMASI: SCANNER PERFORMANCE FIX - Sama seperti di part-request
    function scanner() {
        return {
            isScanning: false, isPaused: false, cooldownSeconds: 3, countdown: 0, cameraError: null,
            video: null, canvas: null, canvasCtx: null, detectionCanvas: null, detectionCanvasCtx: null,
            stream: null, scanInterval: null, countdownInterval: null, lastDetectionTime: 0,

            init() {
                if (typeof jsQR === 'undefined') { this.cameraError = 'Scanner library (jsQR) not loaded.'; return; }
                this.video = document.getElementById('qr-video');
                this.canvas = document.getElementById('qr-canvas');
                this.detectionCanvas = document.getElementById('detection-canvas');
                this.canvasCtx = this.canvas.getContext('2d', { willReadFrequently: true });
                this.detectionCanvasCtx = this.detectionCanvas.getContext('2d');
                document.addEventListener('livewire:navigating', () => this.stop());
            },
            drawLine(begin, end, color) {
                if(!this.detectionCanvasCtx) return;
                this.detectionCanvasCtx.beginPath(); this.detectionCanvasCtx.moveTo(begin.x, begin.y);
                this.detectionCanvasCtx.lineTo(end.x, end.y); this.detectionCanvasCtx.lineWidth = 4;
                this.detectionCanvasCtx.strokeStyle = color; this.detectionCanvasCtx.stroke();
            },
            start() {
                if (this.isPaused) return;
                this.cameraError = null;
                if(this.detectionCanvasCtx) this.detectionCanvasCtx.clearRect(0, 0, this.detectionCanvas.width, this.detectionCanvas.height);
                
                navigator.mediaDevices.getUserMedia({ 
                    video: { facingMode: "environment", width: { ideal: 480 }, height: { ideal: 640 } } 
                })
                    .then((stream) => {
                        this.isScanning = true; this.stream = stream; this.video.srcObject = stream;
                        this.video.onloadedmetadata = () => {
                            this.video.play();
                            this.scanInterval = setInterval(this.tick.bind(this), 150); // Throttle 150ms
                        };
                    })
                    .catch((err) => {
                        this.isScanning = false; this.cameraError = `Gagal memulai kamera: ${err.name}.`;
                    });
            },
            tick() {
                if (!this.isScanning || !this.video || this.video.readyState !== this.video.HAVE_ENOUGH_DATA) return;
                
                if (this.canvas.width !== this.video.videoWidth) {
                   this.canvas.width = this.video.videoWidth; this.canvas.height = this.video.videoHeight;
                   this.detectionCanvas.width = this.video.videoWidth; this.detectionCanvas.height = this.video.videoHeight;
                }

                this.canvasCtx.drawImage(this.video, 0, 0, this.canvas.width, this.canvas.height);
                const imageData = this.canvasCtx.getImageData(0, 0, this.canvas.width, this.canvas.height);
                const code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: "dontInvert" });
                
                if (code && code.data) {
                    this.drawLine(code.location.topLeftCorner, code.location.topRightCorner, "#FF3B58");
                    this.drawLine(code.location.topRightCorner, code.location.bottomRightCorner, "#FF3B58");
                    this.drawLine(code.location.bottomRightCorner, code.location.bottomLeftCorner, "#FF3B58");
                    this.drawLine(code.location.bottomLeftCorner, code.location.topLeftCorner, "#FF3B58");
                    
                    const now = performance.now();
                    if (now - this.lastDetectionTime > 1000) {
                         this.lastDetectionTime = now;
                         Livewire.dispatch('scan-success', { code: code.data });
                         this.pauseScanner();
                    }
                }
            },
            stop() {
                this.isScanning = false;
                if (this.scanInterval) { clearInterval(this.scanInterval); this.scanInterval = null; }
                if (this.stream) { this.stream.getTracks().forEach(track => track.stop()); this.stream = null; }
                if (this.video) { this.video.srcObject = null; }
                if (this.detectionCanvasCtx) this.detectionCanvasCtx.clearRect(0, 0, this.detectionCanvas.width, this.detectionCanvas.height);
            },
            pauseScanner() {
                this.stop(); this.isPaused = true; this.countdown = this.cooldownSeconds;
                this.countdownInterval = setInterval(() => {
                    this.countdown--;
                    if (this.countdown <= 0) {
                        clearInterval(this.countdownInterval); this.isPaused = false; this.start();
                    }
                }, 1000);
            }
        };
    }
</script>
@endpush