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

            try {
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
        
        $part = Cache::tags(['part_search'])->remember($cacheKey, now()->addDay(), function () use ($partNumber) {
            return Singlepart::searchByCode($partNumber)->first();
        });

        if (!$part) {
            $this->error("Part `{$partNumber}` tidak ditemukan di master data.");
            return;
        }

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

<div class="min-h-screen bg-base-100 dark:bg-base-900 text-base-content safe-bottom">
    <x-header 
        title="Buat Permintaan" 
        subtitle="Form permintaan single parts" 
        separator 
        progress-indicator
        class="sticky top-0 z-40 bg-base-100/95 dark:bg-base-900/95 backdrop-blur-sm border-b border-base-200 dark:border-base-800"
    >
        <x-slot:actions>
            <x-button 
                icon="o-arrow-left" 
                link="{{ url()->previous() }}" 
                class="btn-ghost btn-sm touch-manipulation h-10"
                tooltip-bottom="Kembali"
            />
        </x-slot:actions>
    </x-header>

    <div class="max-w-6xl mx-auto space-y-4 px-3 sm:px-4">
        <!-- Scan & Manual Input -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Scan Card -->
            <x-card 
                title="Scan Part" 
                separator
                shadow
                class="h-full border border-base-200 dark:border-base-700 bg-base-50 dark:bg-base-800/50"
            >
                <div wire:ignore x-data="scanner()" x-init="init()">
                    <!-- Scanner Container -->
                    <div 
                        x-ref="scannerContainer"
                        class="relative w-full bg-base-900 dark:bg-base-950 rounded-xl overflow-hidden mb-4"
                        :class="isScanning ? 'aspect-square' : 'h-48'"
                    >
                        <!-- Video Element -->
                        <video 
                            id="qr-video" 
                            playsinline 
                            class="absolute inset-0 w-full h-full object-cover"
                            :class="{'opacity-100': isScanning, 'opacity-0': !isScanning}"
                        ></video>
                        
                        <!-- Loading State -->
                        <div 
                            x-show="!isScanning && !cameraError" 
                            class="absolute inset-0 flex flex-col items-center justify-center bg-base-200 dark:bg-base-800 text-base-content"
                        >
                            <x-icon name="o-qr-code" class="w-16 h-16 opacity-30 mb-2" />
                            <p class="text-sm font-medium opacity-60">Arahkan kamera ke QR Code</p>
                            <p class="text-xs opacity-40 mt-1">Setiap scan menambah 1 KBN</p>
                        </div>

                        <!-- Scanning Overlay -->
                        <div x-show="isScanning" class="absolute inset-0 z-10 pointer-events-none">
                            <div class="absolute inset-0 bg-black/20 dark:bg-black/40"></div>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <div class="relative w-64 h-64 border-2 border-primary/50 rounded-lg overflow-hidden">
                                    <div class="absolute inset-x-0 h-0.5 bg-primary shadow-[0_0_10px_currentColor] animate-scan"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Error State -->
                        <div 
                            x-show="cameraError" 
                            class="absolute inset-0 flex flex-col items-center justify-center bg-error/10 text-error p-4"
                        >
                            <x-icon name="o-exclamation-triangle" class="w-12 h-12 mb-2" />
                            <p class="text-sm text-center" x-text="cameraError"></p>
                        </div>

                        <!-- Canvas Elements -->
                        <canvas id="detection-canvas" class="absolute inset-0 w-full h-full"></canvas>
                        <canvas id="qr-canvas" class="hidden"></canvas>
                    </div>

                    <!-- Scanner Controls -->
                    <div class="flex gap-2">
                        <x-button 
                            x-show="!isScanning && !isPaused" 
                            @click="start()" 
                            icon="o-camera" 
                            label="Mulai Scan" 
                            class="btn-primary flex-1 h-12 min-h-12 touch-manipulation"
                        />
                        <x-button x-show="isPaused" icon="o-clock" class="btn-outline flex-1 h-12" disabled>
                            <span x-text="'Tunggu ' + countdown + 's'"></span>
                        </x-button>
                        <x-button 
                            x-show="isScanning" 
                            @click="stop()" 
                            icon="o-stop-circle" 
                            label="Hentikan" 
                            class="btn-error flex-1 h-12 min-h-12 touch-manipulation"
                        />
                    </div>
                </div>
            </x-card>

            <!-- Manual Input Card -->
            <x-card 
                title="Input Manual" 
                separator
                shadow
                class="h-full border border-base-200 dark:border-base-700 bg-base-50 dark:bg-base-800/50"
            >
                <div class="space-y-4">
                    <x-choices
                        label="Pilih Part Number"
                        wire:model.live.debounce.300ms="child_part_id"
                        :options="$partsSearchable"
                        placeholder="Ketik untuk mencari..."
                        wire:search="search"
                        searchable
                        single
                        min-chars="2"
                        class="w-full bg-base-100 dark:bg-base-700 border-base-300 dark:border-base-600"
                    />
                    
                    <x-button 
                        wire:click="addManualItem" 
                        icon="o-plus" 
                        label="Tambah ke Daftar" 
                        class="btn-primary w-full h-12 min-h-12 touch-manipulation"
                        :disabled="!$child_part_id"
                        spinner="addManualItem"
                    />
                </div>
            </x-card>
        </div>

        <!-- Destination & Summary -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <!-- Destination Selection -->
            <x-card class="lg:col-span-2 border border-base-200 dark:border-base-700 bg-base-50 dark:bg-base-800/50">
                <x-slot:title>
                    <h3 class="font-bold text-base-content dark:text-base-100">Detail Permintaan</h3>
                </x-slot:title>
                
                <div class="space-y-4">
                    <x-select 
                        label="Tujuan Pengiriman" 
                        :options="$this->destinationOptions()" 
                        wire:model="destination" 
                        placeholder="Pilih tujuan"
                        class="w-full bg-base-100 dark:bg-base-700 border-base-300 dark:border-base-600"
                    />
                    
                    <div class="grid grid-cols-2 gap-3">
                        <div class="p-3 bg-base-100 dark:bg-base-700 rounded-lg text-center">
                            <div class="text-xs text-base-content/60 dark:text-base-400 mb-1">Total Item</div>
                            <div class="text-2xl font-bold text-base-content dark:text-base-100">{{ $this->totalItems() }}</div>
                            <div class="text-xs text-success dark:text-success/80 mt-1">KBN</div>
                        </div>
                        @php($urgentCount = collect($items)->where('is_urgent', true)->count())
                        <div class="p-3 bg-base-100 dark:bg-base-700 rounded-lg text-center">
                            <div class="text-xs text-base-content/60 dark:text-base-400 mb-1">Urgent</div>
                            <div class="text-2xl font-bold text-error dark:text-error/80">{{ $urgentCount }}</div>
                            <div class="text-xs text-error dark:text-error/80 mt-1">Prioritas</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Quick Stats -->
            <x-card class="border border-base-200 dark:border-base-700 bg-base-50 dark:bg-base-800/50">
                <x-slot:title>
                    <h3 class="font-bold text-base-content dark:text-base-100">Status Form</h3>
                </x-slot:title>
                
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-base-content/70 dark:text-base-400">Jenis Part</span>
                        <span class="font-bold text-base-content dark:text-base-100">{{ count($items) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-base-content/70 dark:text-base-400">Status</span>
                        <x-badge 
                            :value="$this->hasItems() ? 'Siap Kirim' : 'Kosong'" 
                            :class="$this->hasItems() ? 'badge-success' : 'badge-warning'"
                        />
                    </div>
                    @if($this->hasItems())
                    <div class="pt-3 border-t border-base-200 dark:border-base-700">
                        <x-button 
                            wire:click="clearAllItems" 
                            icon="o-trash" 
                            label="Hapus Semua" 
                            class="btn-error btn-sm w-full h-10 touch-manipulation"
                            wire:confirm="Yakin ingin menghapus semua item?"
                        />
                    </div>
                    @endif
                </div>
            </x-card>
        </div>

        <!-- Item List -->
        <x-card 
            title="Daftar Item" 
            separator
            shadow
            class="border border-base-200 dark:border-base-700 bg-base-50 dark:bg-base-800/50"
        >
            @if($this->hasItems())
                <div class="space-y-3 max-h-[50vh] overflow-y-auto pr-1">
                    @foreach($items as $index => $item)
                        <div 
                            wire:key="item-{{ $item['part_number'] }}"
                            class="relative p-4 rounded-lg border border-base-200 dark:border-base-700 bg-base-100 dark:bg-base-700"
                        >
                            <!-- Item Header -->
                            <div class="flex items-start justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <x-badge :value="$index + 1" class="badge-neutral badge-sm" />
                                    <div>
                                        <h4 class="font-bold text-base text-base-content dark:text-base-100">{{ $item['part_number'] }}</h4>
                                        <div class="flex items-center gap-2 text-sm text-base-content/60 dark:text-base-400">
                                            <span class="flex items-center gap-1">
                                                <x-icon name="o-cube" class="w-3 h-3" />
                                                {{ $item['quantity'] }} KBN
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                @if($item['is_urgent'] ?? false)
                                    <x-badge value="URGENT" class="badge-error animate-pulse" />
                                @endif
                            </div>

                            <!-- Item Actions -->
                            <div class="flex items-center justify-between pt-2 border-t border-base-200 dark:border-base-700">
                                <x-checkbox
                                    label="Tandai Urgent"
                                    :checked="$item['is_urgent'] ?? false"
                                    wire:change="setUrgent('{{ $item['part_number'] }}', $event.target.checked)"
                                    class="text-sm text-base-content dark:text-base-100"
                                />
                                
                                <div class="flex items-center gap-2">
                                    <x-button 
                                        icon="o-trash" 
                                        wire:click="removeItem('{{ $item['part_number'] }}')"
                                        class="btn-error btn-sm h-9 w-9 touch-manipulation"
                                        wire:confirm="Hapus item ini?"
                                        spinner
                                    />
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="py-12 text-center">
                    <x-icon name="o-inbox" class="w-16 h-16 opacity-30 mx-auto mb-4" />
                    <h3 class="font-bold text-lg mb-2 text-base-content dark:text-base-100">Belum Ada Item</h3>
                    <p class="text-base-content/60 dark:text-base-400 mb-4">Mulai dengan scan QR code atau input manual</p>
                    <div class="flex flex-col sm:flex-row gap-2 justify-center">
                        <x-button 
                            icon="o-camera" 
                            label="Mulai Scan" 
                            class="btn-primary h-12 min-h-12 touch-manipulation"
                            @click="window.dispatchEvent(new CustomEvent('scan-start'))"
                        />
                        <x-button 
                            icon="o-pencil-square" 
                            label="Input Manual" 
                            class="btn-outline h-12 min-h-12 touch-manipulation"
                            onclick="document.querySelector('[wire\\\\:model=\\\"child_part_id\\\"]').focus()"
                        />
                    </div>
                </div>
            @endif
        </x-card>

        <!-- Submit Section -->
        <div class="sticky bottom-0 bg-base-100/95 dark:bg-base-900/95 backdrop-blur-sm border-t border-base-200 dark:border-base-800 p-4 -mx-3 sm:mx-0 sm:rounded-lg shadow-lg">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex-1">
                    <div class="font-medium text-base-content dark:text-base-100">
                        @if($this->hasItems())
                            Siap mengirim {{ count($items) }} jenis part
                        @else
                            Tambahkan minimal 1 item
                        @endif
                    </div>
                    <div class="text-sm text-base-content/60 dark:text-base-400">
                        @if($this->hasItems())
                            Total {{ $this->totalItems() }} KBN ke {{ $destination }}
                        @else
                            Gunakan scanner atau input manual
                        @endif
                    </div>
                </div>
                
                <div class="flex gap-2">
                    <x-button 
                        label="Batal" 
                        icon="o-x-mark" 
                        link="{{ url()->previous() }}" 
                        class="btn-ghost h-12 touch-manipulation"
                    />
                    <x-button 
                        type="button"
                        label="Kirim Permintaan" 
                        icon-right="o-paper-airplane" 
                        wire:click="confirmSubmit"
                        class="btn-primary h-12 min-h-12 touch-manipulation"
                        :disabled="!$this->hasItems()"
                        spinner="confirmSubmit"
                    />
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <x-modal 
        wire:model="showConfirmModal" 
        title="Konfirmasi Pengiriman" 
        separator 
        persistent
        class="backdrop-blur-sm"
    >
        <div class="space-y-4">
            <x-alert 
                icon="o-information-circle"
                title="Detail Permintaan"
                description="Pastikan data berikut sudah benar sebelum mengirim"
                class="bg-info/10 border-info/20 text-base-content"
            />
            
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-base-content/70 dark:text-base-400">Jenis Part</span>
                    <span class="font-bold text-base-content dark:text-base-100">{{ count($items) }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-base-content/70 dark:text-base-400">Total KBN</span>
                    <span class="font-bold text-primary dark:text-primary/80">{{ $this->totalItems() }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-base-content/70 dark:text-base-400">Tujuan</span>
                    <span class="font-bold text-base-content dark:text-base-100">{{ $destination }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-base-content/70 dark:text-base-400">Waktu</span>
                    <span class="font-mono text-sm text-base-content dark:text-base-100">{{ now()->format('H:i') }}</span>
                </div>
            </div>
            
            <x-alert 
                icon="o-exclamation-triangle"
                description="Permintaan akan langsung dikirim ke pihak terkait"
                class="bg-warning/10 border-warning/20 text-base-content"
            />
        </div>
        
        <x-slot:actions>
            <x-button 
                label="Periksa Ulang" 
                @click="$wire.showConfirmModal = false" 
                class="btn-outline flex-1 h-12"
            />
            <x-button 
                label="Kirim Sekarang" 
                wire:click="submitRequest" 
                class="btn-primary flex-1 h-12 min-h-12"
                icon="o-paper-airplane"
                spinner="submitRequest"
            />
        </x-slot:actions>
    </x-modal>
</div>

@push('scripts')
<script>
function scanner() {
    return {
        isScanning: false,
        isPaused: false,
        cooldownSeconds: 2,
        countdown: 0,
        cameraError: null,
        video: null,
        canvas: null,
        canvasCtx: null,
        detectionCanvas: null,
        detectionCanvasCtx: null,
        stream: null,
        scanInterval: null,
        cooldownInterval: null,
        lastDetectionTime: 0,
        // Computed property
        get pauseLabel() {
            return `Tunggu ${this.countdown}s`;
        },

        init() {
            if (typeof jsQR === 'undefined') {
                this.cameraError = 'Scanner library tidak tersedia';
                return;
            }
            
            this.video = document.getElementById('qr-video');
            this.canvas = document.getElementById('qr-canvas');
            this.detectionCanvas = document.getElementById('detection-canvas');
            
            if (this.canvas) {
                this.canvasCtx = this.canvas.getContext('2d', { willReadFrequently: true });
            }
            if (this.detectionCanvas) {
                this.detectionCanvasCtx = this.detectionCanvas.getContext('2d');
            }

            // Cleanup on page navigation
            document.addEventListener('livewire:navigating', () => {
                this.stop();
            });

            // External start trigger
            window.addEventListener('scan-start', () => {
                if (!this.isScanning) this.start();
            });
        },

        async start() {
            if (this.isPaused) return;
            this.cameraError = null;

            try {
                const constraints = {
                    video: {
                        facingMode: "environment",
                        width: { ideal: 640 },
                        height: { ideal: 480 }
                    }
                };

                this.stream = await navigator.mediaDevices.getUserMedia(constraints);
                this.isScanning = true;
                this.video.srcObject = this.stream;

                await this.video.play();

                // Start scanning at 10 FPS for mobile performance
                this.scanInterval = setInterval(() => this.scanFrame(), 100);
            } catch (error) {
                this.isScanning = false;
                this.cameraError = this.getCameraErrorMessage(error);
            }
        },

        scanFrame() {
            if (!this.isScanning || !this.video || this.video.readyState !== this.video.HAVE_ENOUGH_DATA) {
                return;
            }

            // Update canvas dimensions if needed
            if (this.canvas.width !== this.video.videoWidth) {
                this.canvas.width = this.video.videoWidth;
                this.canvas.height = this.video.videoHeight;
                if (this.detectionCanvas) {
                    this.detectionCanvas.width = this.video.videoWidth;
                    this.detectionCanvas.height = this.video.videoHeight;
                }
            }

            // Draw video frame to canvas
            this.canvasCtx.drawImage(this.video, 0, 0, this.canvas.width, this.canvas.height);

            // Scan for QR code
            const imageData = this.canvasCtx.getImageData(0, 0, this.canvas.width, this.canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: "dontInvert",
            });

            // Draw detection overlay
            if (this.detectionCanvasCtx) {
                this.detectionCanvasCtx.clearRect(0, 0, this.detectionCanvas.width, this.detectionCanvas.height);
                
                if (code) {
                    this.drawDetectionFrame(code);
                    
                    // Throttle success events (max 1 per second)
                    const now = Date.now();
                    if (now - this.lastDetectionTime > 1000) {
                        this.lastDetectionTime = now;
                        Livewire.dispatch('scan-success', { partNumber: code.data });
                        this.pauseScanner();
                    }
                }
            }
        },

        drawDetectionFrame(code) {
            if (!this.detectionCanvasCtx || !code.location) return;

            const ctx = this.detectionCanvasCtx;
            
            // Draw bounding box
            ctx.beginPath();
            ctx.moveTo(code.location.topLeftCorner.x, code.location.topLeftCorner.y);
            ctx.lineTo(code.location.topRightCorner.x, code.location.topRightCorner.y);
            ctx.lineTo(code.location.bottomRightCorner.x, code.location.bottomRightCorner.y);
            ctx.lineTo(code.location.bottomLeftCorner.x, code.location.bottomLeftCorner.y);
            ctx.closePath();
            
            ctx.lineWidth = 3;
            ctx.strokeStyle = '#00ff00';
            ctx.stroke();
        },

        stop() {
            this.isScanning = false;
            
            if (this.scanInterval) {
                clearInterval(this.scanInterval);
                this.scanInterval = null;
            }
            
            if (this.cooldownInterval) {
                clearInterval(this.cooldownInterval);
                this.cooldownInterval = null;
            }
            
            if (this.stream) {
                this.stream.getTracks().forEach(track => track.stop());
                this.stream = null;
            }
            
            if (this.video) {
                this.video.srcObject = null;
            }
            
            if (this.detectionCanvasCtx) {
                this.detectionCanvasCtx.clearRect(0, 0, 
                    this.detectionCanvas?.width || 0, 
                    this.detectionCanvas?.height || 0
                );
            }
        },

        pauseScanner() {
            this.stop();
            this.isPaused = true;
            this.countdown = this.cooldownSeconds;
            
            this.cooldownInterval = setInterval(() => {
                this.countdown--;
                if (this.countdown <= 0) {
                    clearInterval(this.cooldownInterval);
                    this.isPaused = false;
                    this.start();
                }
            }, 1000);
        },

        getCameraErrorMessage(error) {
            switch(error.name) {
                case 'NotAllowedError':
                    return 'Izin kamera ditolak. Aktifkan izin kamera di pengaturan browser.';
                case 'NotFoundError':
                    return 'Kamera tidak ditemukan.';
                case 'NotSupportedError':
                    return 'Browser tidak mendukung akses kamera.';
                case 'NotReadableError':
                    return 'Kamera sedang digunakan aplikasi lain.';
                default:
                    return 'Gagal mengakses kamera: ' + error.message;
            }
        }
    };
}
</script>
@endpush