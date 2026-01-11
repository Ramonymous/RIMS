<?php

use App\Models\Request;
use App\Models\RequestList;
use App\Models\Singlepart;
use App\Models\Outgoing;
use App\Models\Movement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Layout('components.layouts.app')] #[Title('List Permintaan Part')] class extends Component {
    use Toast;

    public Collection $rows;

    public array $seenItemIds = [];
    public array $announcedDelayedItemIds = [];

    public int $totalWaiting = 0;
    public int $totalDelayed = 0;
    public ?string $oldestRequest = null;

    public bool $showSupplyModal = false;
    public ?int $supplyingItemId = null;
    public ?RequestList $supplyingItem = null;
    public string $supplyStep = 'scan';
    public string $supplyScannedCode = '';
    public int $supplyQtyInput = 0;
    public int $supplyMaxQty = 0;
    public string $supplyError = '';
    public bool $showManualInput = false;

    public function mount(): void
    {
        $this->rows = collect();
        $this->refreshRows();
    }
    
    public function enableManualInput()
    {
        $this->showManualInput = true;
    }


    #[On('refreshRows')]
    public function refreshRows(): void
    {
        try {
            $now = now();

            $items = RequestList::query()
                ->select('id', 'request_id', 'part_id', 'quantity', 'is_urgent', 'status')
                ->with([
                    'part:id,part_number,part_name,address,stock',
                    'request:id,destination,requested_at,status',
                ])
                ->where('status', 'pending')
                ->orderByDesc('is_urgent')
                ->orderBy(
                    Request::select('requested_at')
                        ->whereColumn('requests.id', 'request_lists.request_id')
                )
                ->limit(50)
                ->get();

            $rows = [];
            $newAnnouncements = [];
            $delayedAnnouncements = [];
            $waitingCount = 0;
            $delayedCount = 0;

            $currentItemIds = $items->pluck('id')->toArray();
            $this->seenItemIds = array_intersect($this->seenItemIds, $currentItemIds);
            $this->announcedDelayedItemIds = array_intersect(
                $this->announcedDelayedItemIds,
                $currentItemIds
            );

            foreach ($items as $item) {
                if (!$item->request || !$item->part) {
                    continue;
                }

                $requestedAt = $item->request->requested_at;
                $isDelayed = $requestedAt->lt($now->copy()->subMinutes(15));
                $isNew = !in_array($item->id, $this->seenItemIds);

                if ($isNew) {
                    $newAnnouncements[] = [
                        'key' => $item->id . '|' . $requestedAt->timestamp,
                        'part_number' => $item->part->part_number,
                        'is_urgent' => (bool) $item->is_urgent,
                    ];
                }

                if ($isDelayed && !in_array($item->id, $this->announcedDelayedItemIds)) {
                    $delayedAnnouncements[] = [
                        'key' => $item->id . '|' . $requestedAt->timestamp,
                        'part_number' => $item->part->part_number,
                        'is_urgent' => (bool) $item->is_urgent,
                    ];
                    $this->announcedDelayedItemIds[] = $item->id;
                }

                if ($isDelayed) {
                    $urgency = 'delayed';
                    $delayedCount++;
                } elseif ($requestedAt->gt($now->copy()->subMinutes(5))) {
                    $urgency = 'new';
                    $waitingCount++;
                } else {
                    $urgency = 'waiting';
                    $waitingCount++;
                }

                $rows[] = [
                    'item_id' => $item->id,
                    'part_number' => $item->part->part_number,
                    'part_name' => $item->part->part_name,
                    'quantity' => $item->quantity,
                    'destination' => $item->request->destination,
                    'address' => $item->part->address,
                    'requested_at' => $requestedAt,
                    'is_urgent' => (bool) $item->is_urgent,
                    'urgency' => $urgency,
                    'stock' => $item->part->stock,
                    'can_supply' => auth()->user()->can('manage') || auth()->user()->can('outgoer'),
                ];
            }

            $this->seenItemIds = array_unique(array_merge(
                $this->seenItemIds,
                $items->pluck('id')->toArray()
            ));

            $this->rows = collect($rows);
            $this->totalWaiting = $waitingCount;
            $this->totalDelayed = $delayedCount;

            $oldestRequestedAt = $items->pluck('request.requested_at')->filter()->sort()->first();
            $this->oldestRequest = $oldestRequestedAt?->diffForHumans();

            if ($newAnnouncements) {
                $this->dispatch('announce-new-parts', announcements: $newAnnouncements);
            }
            if ($delayedAnnouncements) {
                $this->dispatch('announce-delayed-parts', announcements: $delayedAnnouncements);
            }
        } catch (\Throwable $e) {
            Log::error('refreshRows error', ['message' => $e->getMessage()]);
        }
    }

    public function openSupplyModal(int $itemId): void
    {
        $this->supplyingItem = RequestList::with('part')->find($itemId);

        if (!$this->supplyingItem || !$this->supplyingItem->part) {
            $this->error('Item tidak ditemukan.');
            return;
        }

        $this->supplyingItemId = $itemId;
        $this->supplyStep = 'scan';
        $this->supplyScannedCode = '';
        $this->supplyQtyInput = 0;
        $this->supplyError = '';
        $this->showSupplyModal = true;

        $this->dispatch('supply-scanner-start');
    }

    #[On('supply-scan-success')]
    public function handleSupplyScan(string $code): void
    {
        if (!$this->showSupplyModal || $this->supplyStep !== 'scan') {
            return;
        }

        $code = trim($code);
        $targetPartNumber = $this->supplyingItem->part->part_number;

        if (strcasecmp($code, $targetPartNumber) === 0) {
            $this->supplyScannedCode = $code;
            $this->supplyStep = 'quantity';

            $stock = $this->supplyingItem->part->stock;
            $requested = $this->supplyingItem->quantity;
            $this->supplyMaxQty = $stock;
            $this->supplyQtyInput = $stock > 0 ? min($stock, $requested) : 0;
            $this->supplyError = '';

            if ($this->supplyMaxQty <= 0) {
                $this->supplyError = "Stok kosong (Stok: $stock).";
            } else {
                $this->success('Part Valid!');
            }

            $this->dispatch('supply-scanner-stop');
        } else {
            $this->supplyError = "Part salah! Scan: $code";
        }
    }

    public function checkManualSupply(): void
    {
        if (empty($this->supplyScannedCode)) {
            $this->supplyError = 'Harap isi Part Number.';
            return;
        }
        $this->handleSupplyScan($this->supplyScannedCode);
    }

    public function processSupply(): void
    {
        $this->validate([
            'supplyQtyInput' => 'required|integer|min:1|max:' . ($this->supplyingItem->part->stock ?? 0),
        ]);

        if (!$this->supplyingItem) {
            return;
        }

        DB::beginTransaction();
        try {
            $part = Singlepart::lockForUpdate()->find($this->supplyingItem->part_id);
            if (!$part || $part->stock < $this->supplyQtyInput) {
                throw new \Exception('Stok tidak mencukupi.');
            }

            $outgoingNumber = $this->generateOutgoingNumber();

            Outgoing::create([
                'outgoing_number' => $outgoingNumber,
                'part_id' => $part->id,
                'quantity' => $this->supplyQtyInput,
                'dispatched_by' => Auth::id(),
                'dispatched_at' => now(),
            ]);

            $oldStock = $part->stock;
            $part->decrement('stock', $this->supplyQtyInput);

            Movement::create([
                'part_id' => $part->id,
                'type' => 'out',
                'pic' => Auth::id(),
                'qty' => $this->supplyQtyInput,
                'final_qty' => $oldStock - $this->supplyQtyInput,
            ]);

            $reqList = RequestList::lockForUpdate()->find($this->supplyingItem->id);
            if ($this->supplyQtyInput >= $reqList->quantity) {
                $reqList->update(['status' => 'fulfilled']);
            }

            $this->updateRequestStatus($reqList->request_id);

            DB::commit();

            $this->success("Supply berhasil! $outgoingNumber");
            $this->closeSupplyModal();
            $this->refreshRows();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Gagal: ' . $e->getMessage());
        }
    }

    protected function generateOutgoingNumber(): string
    {
        $date = now()->format('Ymd');
        $cacheKey = "outgoing_last_number_{$date}";

        $lastNumber = Cache::remember($cacheKey, 60, function () use ($date) {
            $last = Outgoing::where('outgoing_number', 'LIKE', "OUT-{$date}-%")
                ->orderByDesc('outgoing_number')
                ->value('outgoing_number');
            return $last ? (int) substr($last, -4) : 0;
        });

        $newNumber = $lastNumber + 1;
        Cache::put($cacheKey, $newNumber, 60);
        return sprintf('OUT-%s-%04d', $date, $newNumber);
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

    public function closeSupplyModal(): void
    {
        $this->showSupplyModal = false;
        $this->dispatch('supply-scanner-stop');
    }
};
?>

<div wire:poll.5s="refreshRows" class="min-h-screen bg-base-100 dark:bg-base-900 text-base-content pb-20 sm:pb-4">
    <x-header title="Permintaan Part" separator progress-indicator class="sticky top-0 z-30 bg-base-100/90 dark:bg-base-900/90 backdrop-blur-sm border-b border-base-200 dark:border-base-800">
        <x-slot:subtitle>
            <div class="flex items-center gap-2 text-xs sm:text-sm">
                <span class="w-2 h-2 rounded-full bg-success animate-pulse"></span> Real-time Monitoring
            </div>
        </x-slot:subtitle>
        <x-slot:actions>
            <x-button 
                icon="o-arrow-path" 
                @click="$wire.refreshRows()" 
                spinner="refreshRows" 
                class="btn-sm btn-ghost touch-manipulation h-10" 
                tooltip-bottom="Refresh"
            />
        </x-slot:actions>
    </x-header>

    <div class="max-w-6xl mx-auto space-y-4 sm:space-y-6 px-3 sm:px-4 safe-bottom">
        <!-- Stats Cards - Horizontal Scroll for Mobile -->
        <div class="flex overflow-x-auto gap-3 pb-2 no-scrollbar snap-x">
            @foreach([
                ['title' => 'Menunggu', 'value' => $totalWaiting, 'icon' => 'o-clock', 'color' => 'text-warning dark:text-warning/80'],
                ['title' => 'Terlambat', 'value' => $totalDelayed, 'icon' => 'o-exclamation-triangle', 'color' => 'text-error dark:text-error/80'],
                ['title' => 'Antrian Lama', 'value' => $oldestRequest ?? '-', 'icon' => 'o-calendar-days'],
            ] as $stat)
            <div class="snap-center shrink-0 w-[85%] sm:w-auto sm:flex-1">
                <x-stat 
                    :title="$stat['title']"
                    :value="$stat['value']"
                    :icon="$stat['icon']"
                    :color="$stat['color'] ?? ''"
                    class="bg-base-50 dark:bg-base-800/50 backdrop-blur border border-base-200 dark:border-base-700 rounded-2xl shadow-sm h-full"
                />
            </div>
            @endforeach
        </div>

        <!-- Request List -->
        <div class="space-y-2 sm:space-y-3">
            @forelse($rows as $row)
            @php
                $isUrgent = $row['is_urgent'];
                $urgencyType = $row['urgency'];
                
                $borderColor = match($urgencyType) {
                    'new' => 'border-l-primary',
                    'delayed' => 'border-l-error',
                    default => 'border-l-warning',
                };
                
                $bgColor = $urgencyType === 'delayed' 
                    ? 'bg-gradient-to-r from-error/10 to-transparent dark:from-error/20' 
                    : 'bg-gradient-to-r from-base-50 to-transparent dark:from-base-800/50';
            @endphp

            <div 
                wire:key="item-{{ $row['item_id'] }}"
                class="group relative rounded-r-xl overflow-hidden border-l-[6px] {{ $borderColor }} {{ $bgColor }} border-y border-r border-base-200 dark:border-base-700 shadow-sm hover:shadow-md transition-all duration-300 touch-manipulation active:scale-[0.98]"
            >
                <div class="p-4">
                    <div class="flex flex-col gap-3">
                        <!-- Header Row -->
                        <div class="flex items-start justify-between">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <h2 class="text-lg font-bold font-mono truncate text-base-content dark:text-base-100">
                                        {{ $row['part_number'] }}
                                    </h2>
                                    @if($isUrgent)
                                    <x-badge value="URGENT" class="badge-error badge-sm animate-pulse" />
                                    @endif
                                </div>
                                <p class="text-sm text-base-content/70 dark:text-base-400 truncate">
                                    {{ $row['part_name'] }}
                                </p>
                            </div>                            
                        </div>

                        <!-- Info Row -->
                        <div class="flex items-center justify-between text-xs text-base-content/60 dark:text-base-500">
                            <div class="flex items-center gap-3">
                                <span class="flex items-center gap-1">
                                    <x-icon name="o-clock" class="w-3.5 h-3.5" />
                                    {{ $row['requested_at']->format('H:i') }}
                                </span>
                                @if(!empty($row['address']))
                                <span class="flex items-center gap-1">
                                    <x-icon name="o-map-pin" class="w-3.5 h-3.5" />
                                    <span class="truncate max-w-[120px]">{{ $row['address'] }}</span>
                                </span>
                                @endif
                            </div>
                            
                            <div class="flex items-center gap-2">
                                <span class="flex items-center gap-1">
                                    <x-icon name="o-archive-box" class="w-3.5 h-3.5" />
                                    {{ $row['stock'] }}
                                </span>
                                
                                @if($row['can_supply'])
                                <x-button 
                                    wire:click="openSupplyModal({{ $row['item_id'] }})"
                                    icon="o-qr-code"
                                    label="Supply"
                                    class="btn-primary btn-sm shadow-lg shadow-primary/20 h-9 touch-manipulation"
                                    spinner="openSupplyModal"
                                />
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @empty
            <x-card class="text-center py-12 border-dashed border-base-200 dark:border-base-700">
                <div class="flex flex-col items-center gap-4">
                    <x-icon name="o-check-circle" class="w-16 h-16 text-success" />
                    <div>
                        <h3 class="font-bold text-lg text-base-content dark:text-base-100">Semua Aman!</h3>
                        <p class="text-sm text-base-content/60 dark:text-base-400">Tidak ada permintaan part saat ini.</p>
                    </div>
                </div>
            </x-card>
            @endforelse
        </div>
    </div>

    <!-- Supply Modal -->
    <x-modal 
        wire:model="showSupplyModal" 
        title="Supply Part" 
        separator 
        class="backdrop-blur-sm"
        persistent
        :close-by-escape="false"
        :close-by-clicking-away="false"
    >
        @if($supplyingItem)
            <!-- Header Info -->
            <div class="mb-6 p-4 bg-base-100 dark:bg-base-800 rounded-xl border border-base-200 dark:border-base-700">
                <div class="flex justify-between items-center">
                    <div>
                        <div class="text-xs text-base-content/50 dark:text-base-500 uppercase font-semibold tracking-wider">Target Part</div>
                        <div class="text-2xl font-black font-mono text-primary dark:text-primary/80 mt-1">{{ $supplyingItem->part->part_number }}</div>
                    </div>
                </div>
            </div>

            <!-- Step Content -->
            <div class="relative min-h-[400px] overflow-hidden">
                <!-- Scan Step -->
                <div 
                    x-show="$wire.supplyStep === 'scan'" 
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 -translate-x-10"
                    x-transition:enter-end="opacity-100 translate-x-0"
                    x-transition:leave="transition ease-in duration-300"
                    x-transition:leave-start="opacity-100 translate-x-0"
                    x-transition:leave-end="opacity-0 -translate-x-10"
                    class="space-y-4 absolute inset-0"
                >
                    <!-- Scanner Area -->
                    <div 
                        wire:ignore 
                        x-data="supplyScanner()" 
                        x-init="init()"
                        @supply-scanner-start.window="start()"
                        @supply-scanner-stop.window="stop()"
                        x-ref="scannerContainer"
                        class="relative overflow-hidden rounded-2xl bg-base-900 dark:bg-base-950 aspect-square max-h-[280px] mx-auto shadow-2xl ring-1 ring-base-300 dark:ring-base-700"
                    >
                        <video id="supply-qr-video" class="absolute inset-0 w-full h-full object-cover opacity-90"></video>
                        <canvas id="supply-qr-canvas" class="hidden"></canvas>
                        
                        <!-- Loading State -->
                        <div x-show="!isScanning" class="absolute inset-0 flex flex-col items-center justify-center bg-base-900/90 text-base-100 z-10">
                            <x-icon name="o-camera" class="w-12 h-12 text-primary mb-2" />
                            <span class="text-sm">Menyiapkan kamera...</span>
                        </div>
                        
                        <!-- Scanning Overlay -->
                        <div x-show="isScanning" class="absolute inset-0 z-20 pointer-events-none">
                            <div class="absolute inset-0 bg-black/30"></div>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <div class="relative w-[70%] h-[70%] border-2 border-primary/50 rounded-xl overflow-hidden shadow-[0_0_0_9999px_rgba(0,0,0,0.5)]">
                                    <div class="absolute inset-x-0 h-0.5 bg-primary shadow-[0_0_15px_currentColor] animate-scan"></div>
                                </div>
                            </div>
                            <div class="absolute bottom-4 inset-x-0 text-center">
                                <x-badge value="Scanning..." class="bg-base-900/60 text-base-100 dark:text-white border-base-300/20" />
                            </div>
                        </div>
                    </div>

                    <!-- Error Message -->
                    @if($supplyError)
                    <x-alert icon="o-exclamation-triangle" :description="$supplyError" class="bg-error/10 border-error/20 text-error" />
                    @endif

                    <!-- Manual Input -->
                    <div class="space-y-2">
                        <x-button
                            icon="o-pencil-square"
                            label="Manual Input"
                            wire:click="enableManualInput"
                            class="btn-outline w-full h-12 min-h-12 touch-manipulation"
                            x-show="!$wire.showManualInput"
                        />
                    @if ($showManualInput)
                        <x-input 
                            placeholder="Atau input manual part number..." 
                            wire:model="supplyScannedCode" 
                            class="w-full bg-base-100 dark:bg-base-700 border-base-300 dark:border-base-600"
                            icon="o-pencil-square"
                        />
                        <x-button 
                            icon="o-check" 
                            label="Verifikasi" 
                            wire:click="checkManualSupply" 
                            class="btn-primary w-full h-12 min-h-12 touch-manipulation"
                            spinner="checkManualSupply"
                        />
                    @endif
                    </div>
                </div>

                <!-- Quantity Step -->
                <div 
                    x-show="$wire.supplyStep === 'quantity'" 
                    x-transition:enter="transition ease-out duration-300 delay-150"
                    x-transition:enter-start="opacity-0 translate-x-10"
                    x-transition:enter-end="opacity-100 translate-x-0"
                    class="space-y-6 absolute inset-0 flex flex-col justify-center"
                >
                    <!-- Success Indicator -->
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-success/10 text-success dark:text-success/80 mb-4 shadow-lg ring-4 ring-success/10 dark:ring-success/5">
                            <x-icon name="o-check" class="w-10 h-10" />
                        </div>
                        <h3 class="text-xl font-bold text-base-content dark:text-base-100">Terverifikasi!</h3>
                        <p class="text-sm text-base-content/60 dark:text-base-400">Masukkan jumlah supply</p>
                    </div>

                    <!-- Stock Info -->
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <x-stat 
                            title="Stok Tersedia"
                            :value="$supplyingItem->part->stock"
                            class="bg-base-100 dark:bg-base-700 rounded-lg p-3"
                        />
                        <x-stat 
                            title="Diminta"
                            :value="$supplyingItem->quantity"
                            class="bg-base-100 dark:bg-base-700 rounded-lg p-3"
                        />
                        <x-stat 
                            title="Maksimal"
                            :value="$supplyMaxQty"
                            class="bg-base-100 dark:bg-base-700 rounded-lg p-3"
                        />
                    </div>

                    <!-- Quantity Input -->
                    <div class="space-y-2">
                        <x-input 
                            label="Jumlah Supply" 
                            type="number" 
                            wire:model="supplyQtyInput" 
                            min="1" 
                            :max="$supplyMaxQty" 
                            class="text-center font-bold text-2xl h-14 bg-base-100 dark:bg-base-700 border-base-300 dark:border-base-600" 
                            autofocus 
                            :hint="$supplyMaxQty == 0 ? 'Stok kosong' : 'Masukkan jumlah antara 1 dan ' . $supplyMaxQty"
                            :error="$supplyMaxQty == 0"
                        />
                        
                        <!-- Quick Select Buttons -->
                        @if($supplyMaxQty > 0)
                        <div class="flex gap-2">
                            @foreach([$supplyingItem->quantity, min($supplyMaxQty, $supplyingItem->quantity), $supplyMaxQty] as $quickQty)
                            <x-button 
                                :label="$quickQty"
                                wire:click="$set('supplyQtyInput', {{ $quickQty }})"
                                class="btn-outline btn-sm flex-1 h-10 touch-manipulation"
                                :disabled="$quickQty > $supplyMaxQty"
                            />
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
        
        <x-slot:actions>
            <div class="flex gap-2 w-full sm:w-auto">
                <x-button 
                    label="Kembali" 
                    wire:click="closeSupplyModal" 
                    class="btn-ghost flex-1 h-12"
                    :disabled="$supplyStep === 'quantity'"
                />
                @if($supplyStep === 'quantity' && $supplyMaxQty > 0)
                <x-button 
                    label="Kirim Supply" 
                    wire:click="processSupply" 
                    class="btn-primary flex-1 h-12 min-h-12 touch-manipulation"
                    icon="o-paper-airplane" 
                    spinner="processSupply"
                />
                @endif
            </div>
        </x-slot:actions>
    </x-modal>
</div>

@push('scripts')
<script>
/* =====================================================
   HOURLY CACHE RESET (SAFE)
===================================================== */
(function () {
    const now = new Date();
    const hourKey =
        now.getFullYear() +
        String(now.getMonth() + 1).padStart(2, '0') +
        String(now.getDate()).padStart(2, '0') +
        String(now.getHours()).padStart(2, '0');

    const last = localStorage.getItem('voice_cache_hour');

    if (last !== hourKey) {
        localStorage.removeItem('ann_new');
        localStorage.removeItem('ann_delayed');
        localStorage.setItem('voice_cache_hour', hourKey);
        console.log('🔄 Voice cache reset (hourly)');
    }
})();
</script>

<script>
/* =====================================================
   GLOBAL STATE (ANTI LIVEWIRE COLLISION)
===================================================== */
window.__PART_VOICE_STATE__ ??= {
    interacted: false,
    initialized: false,
    speaking: false,
    voice: null,

    // priority queue: { priority, utterance }
    queue: [],

    pageReady: false,

    announcedNew: JSON.parse(localStorage.getItem('ann_new') || '[]'),
    announcedDelayed: JSON.parse(localStorage.getItem('ann_delayed') || '{}'),
};

function S() {
    return window.__PART_VOICE_STATE__;
}

/* =====================================================
   STORAGE
===================================================== */
function saveState() {
    localStorage.setItem('ann_new', JSON.stringify(S().announcedNew));
    localStorage.setItem('ann_delayed', JSON.stringify(S().announcedDelayed));
}

/* =====================================================
   QUEUE HELPER (PRIORITY BASED)
===================================================== */
function enqueue(priority, text, options = {}) {
    const u = new SpeechSynthesisUtterance(text);

    u.rate  = options.rate  ?? 1;
    u.pitch = options.pitch ?? 1;

    S().queue.push({ priority, utterance: u });

    // priority kecil = didahulukan
    S().queue.sort((a, b) => a.priority - b.priority);
}

/* =====================================================
   SOUND MODAL (ONE TIME)
===================================================== */
function showSoundModal() {
    if (document.getElementById('sound-modal')) return;

    const modal = document.createElement('div');
    modal.id = 'sound-modal';
    modal.innerHTML = `
        <div style="position:fixed;inset:0;background:rgba(0,0,0,.6);
            display:flex;align-items:center;justify-content:center;z-index:9999">
            <div style="background:#020617;color:white;padding:20px;border-radius:12px;width:360px;text-align:center">
                <h3>Aktifkan Suara</h3>
                <p style="color:#94a3b8;margin:10px 0">
                    Browser memerlukan interaksi untuk memutar suara.
                </p>
                <button id="sound-ok"
                    style="background:#22c55e;color:black;padding:10px 16px;border-radius:8px;font-weight:bold">
                    Aktifkan
                </button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    modal.querySelector('#sound-ok').onclick = () => {
        S().interacted = true;
        initSpeech(true);
        processQueue();
        modal.remove();
    };
}

/* =====================================================
   SPEECH INIT
===================================================== */
function initSpeech(force = false) {
    if (S().initialized && !force) return;
    if (!('speechSynthesis' in window)) return;

    const loadVoices = () => {
        const voices = speechSynthesis.getVoices();
        if (!voices.length) return false;

        S().voice =
            voices.find(v => v.lang?.startsWith('id')) ||
            voices.find(v => v.lang?.startsWith('en')) ||
            voices[0];

        if (!S().voice) return false;

        S().initialized = true;
        processQueue();
        return true;
    };

    if (!loadVoices()) {
        speechSynthesis.onvoiceschanged = loadVoices;
    }
}

/* =====================================================
   QUEUE PROCESSOR
===================================================== */
function processQueue() {
    if (S().speaking || !S().queue.length || !S().initialized) return;

    const item = S().queue.shift();
    const u = item.utterance;

    S().speaking = true;
    u.voice = S().voice;

    u.onend = () => {
        S().speaking = false;
        setTimeout(processQueue, 200);
    };

    speechSynthesis.speak(u);
}

/* =====================================================
   LIVEWIRE EVENTS (REGISTER ONCE)
===================================================== */
function registerLivewireVoiceEvents() {
    if (!window.Livewire || window.__PART_VOICE_EVENTS__) return;
    window.__PART_VOICE_EVENTS__ = true;

    /* ---------- NEW PART ---------- */
    Livewire.on('announce-new-parts', ({ announcements }) => {
        announcements.forEach(a => {
            if (S().announcedNew.includes(a.key)) return;

            S().announcedNew.push(a.key);
            saveState();

            if (a.is_urgent) {
                enqueue(
                    1,
                    `Perhatian. Permintaan arjen untuk part ${a.part_number.split('').join(' ')}`,
                    { rate: 1.15, pitch: 1.1 }
                );
            } else {
                enqueue(
                    3,
                    `Part baru diminta ${a.part_number.split('').join(' ')}`,
                    { rate: 0.95 }
                );
            }
        });

        if (!S().interacted) showSoundModal();
        processQueue();
    });

    /* ---------- DELAYED ---------- */
    Livewire.on('announce-delayed-parts', ({ announcements }) => {
        // cleanup > 1 jam
        Object.entries(S().announcedDelayed).forEach(([k, t]) => {
            if (Date.now() - t > 60 * 60 * 1000) delete S().announcedDelayed[k];
        });

        if (!S().pageReady) return;

        const now = Date.now();
        const REMIND_INTERVAL = 10 * 60 * 1000;

        announcements.forEach(a => {
            const last = S().announcedDelayed[a.key] || 0;
            if (now - last < REMIND_INTERVAL) return;

            S().announcedDelayed[a.key] = now;
            saveState();

            enqueue(
                a.is_urgent ? 1 : 2,
                a.is_urgent
                    ? `Perhatian. Permintaan arjen untuk part ${a.part_number.split('').join(' ')} masih terlambat`
                    : `Pengingat. Permintaan part ${a.part_number.split('').join(' ')} masih terlambat`,
                a.is_urgent
                    ? { rate: 1.15, pitch: 1.1 }
                    : { rate: 1 }
            );
        });

        if (!S().interacted) showSoundModal();
        processQueue();
    });
}

/* =====================================================
   LIVEWIRE SAFE HOOKS
===================================================== */
document.addEventListener('livewire:load', registerLivewireVoiceEvents);
document.addEventListener('livewire:navigated', registerLivewireVoiceEvents);

/* =====================================================
   USER INTERACTION FALLBACK
===================================================== */
window.addEventListener('pointerdown', () => {
    S().interacted = true;
    initSpeech(true);
}, { once: true });

setTimeout(() => S().pageReady = true, 600);

// --- SUPPLY SCANNER LOGIC (Dedicated to avoid conflict) ---
function supplyScanner() {
    return {
        isScanning: false,
        video: null,
        canvas: null,
        ctx: null,
        stream: null,
        interval: null,

        init() {
            this.video = document.getElementById('supply-qr-video');
            this.canvas = document.getElementById('supply-qr-canvas');
            if(this.canvas) this.ctx = this.canvas.getContext('2d', { willReadFrequently: true });
        },

        start() {
            if (this.isScanning) return;
            if (typeof jsQR === 'undefined') { console.error('jsQR missing'); return; }
            
            // UX: Set focus to scanner container to prevent keyboard popup
            if(this.$refs.scannerContainer) {
                 this.$refs.scannerContainer.focus();
            }

            // OPTIMIZATION: Small delay to let modal animation finish before hitting hardware
            setTimeout(() => {
                // OPTIMIZATION: Use lower resolution constraints for mobile performance
                const constraints = { 
                    video: { 
                        facingMode: 'environment',
                        width: { ideal: 480 }, // Lower res is faster on mobile
                        height: { ideal: 480 } 
                    } 
                };

                navigator.mediaDevices.getUserMedia(constraints)
                    .then(stream => {
                        // Double check if we still need to scan (user might have closed modal)
                        if (!document.getElementById('supply-qr-video')) {
                            stream.getTracks().forEach(t => t.stop());
                            return;
                        }
                        
                        this.stream = stream;
                        this.video.srcObject = stream;
                        this.video.play();
                        this.isScanning = true;
                        
                        // OPTIMIZATION: Reduce interval check slightly if needed, but 200ms is ok
                        this.interval = setInterval(() => this.tick(), 200);
                    })
                    .catch(err => {
                        console.error(err);
                        this.isScanning = false; 
                    });
            }, 300); // 300ms delay
        },

        tick() {
            if (!this.video || !this.video.videoWidth) return;
            
            this.canvas.width = this.video.videoWidth;
            this.canvas.height = this.video.videoHeight;
            this.ctx.drawImage(this.video, 0, 0);
            
            const imageData = this.ctx.getImageData(0, 0, this.canvas.width, this.canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });
            
            if (code && code.data) {
                // SUCCESS
                Livewire.dispatch('supply-scan-success', { code: code.data });
                // Don't stop immediately to allow retry if wrong, but throttle handled by backend logic/modal UI
            }
        },

        stop() {
            this.isScanning = false;
            clearInterval(this.interval);
            if (this.stream) {
                this.stream.getTracks().forEach(t => t.stop());
                this.stream = null;
            }
            if(this.video) this.video.srcObject = null;
        }
    }
}
</script>
@endpush
