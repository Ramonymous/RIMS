<?php

use App\Models\Request;
use App\Models\RequestList;
use App\Models\Singlepart; // Added for stock check
use App\Models\Outgoing;   // Added for processing
use App\Models\Movement;   // Added for processing
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;   // Added for transaction
use Illuminate\Support\Facades\Auth; // Added for user id
use Illuminate\Support\Facades\Cache; // Added for outgoing number generation
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new
#[Layout('components.layouts.app')]
#[Title('List Permintaan Part')]
class extends Component {
    use Toast;

    public Collection $rows;

    // server-side memory (per tab)
    public array $seenItemIds = [];
    public array $announcedDelayedItemIds = [];

    public int $totalWaiting = 0;
    public int $totalDelayed = 0;
    public ?string $oldestRequest = null;

    // --- SUPPLY FEATURE STATE ---
    public bool $showSupplyModal = false;
    public ?int $supplyingItemId = null;
    public ?RequestList $supplyingItem = null;
    public string $supplyStep = 'scan'; // 'scan' | 'quantity'
    public string $supplyScannedCode = '';
    public int $supplyQtyInput = 0;
    public int $supplyMaxQty = 0;
    public string $supplyError = '';

    public function mount(): void
    {
        $this->rows = collect();
        $this->refreshRows();
    }

    #[On('refreshRows')]
    public function refreshRows(): void
    {
        try {
            $now = now(); // ✅ FIX: define once

            $items = RequestList::query()
                ->select('id', 'request_id', 'part_id', 'quantity', 'is_urgent', 'status')
                ->with([
                    'part:id,part_number,part_name,address,stock', // Added stock
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

            // 🔒 keep only visible item IDs (prevent memory leak)
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

                // =========================
                // 🔔 ANNOUNCEMENTS
                // =========================
                if ($isNew) {
                    $newAnnouncements[] = [
                        'key'         => $item->id . '|' . $requestedAt->timestamp,
                        'part_number'=> $item->part->part_number,
                        'is_urgent'  => (bool) $item->is_urgent, // ✅ PENTING
                    ];
                }

                if ($isDelayed && !in_array($item->id, $this->announcedDelayedItemIds)) {
                    $delayedAnnouncements[] = [
                        'key'         => $item->id . '|' . $requestedAt->timestamp,
                        'part_number'=> $item->part->part_number,
                        'is_urgent'  => (bool) $item->is_urgent, // ✅ PENTING
                    ];
                    $this->announcedDelayedItemIds[] = $item->id;
                }

                // =========================
                // 🧠 URGENCY
                // =========================
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

                // =========================
                // 📋 ROW DATA
                // =========================
                $rows[] = [
                    'item_id'      => $item->id,
                    'part_number'  => $item->part->part_number,
                    'part_name'    => $item->part->part_name,
                    'quantity'     => $item->quantity,
                    'destination'  => $item->request->destination,
                    'address'      => $item->part->address,
                    'requested_at' => $requestedAt,
                    'is_urgent'    => (bool) $item->is_urgent,
                    'urgency'      => $urgency,
                    // Supply feature needs these
                    'stock'        => $item->part->stock,
                    'can_supply'   => auth()->user()->can('manage') || auth()->user()->can('outgoer'),
                ];
            }

            // =========================
            // 🧠 UPDATE MEMORY
            // =========================
            $this->seenItemIds = array_unique(array_merge(
                $this->seenItemIds,
                $items->pluck('id')->toArray()
            ));

            // =========================
            // 📊 STATS
            // =========================
            $this->rows = collect($rows);
            $this->totalWaiting = $waitingCount;
            $this->totalDelayed = $delayedCount;

            $oldestRequestedAt = $items
                ->pluck('request.requested_at')
                ->filter()
                ->sort()
                ->first();

            $this->oldestRequest = $oldestRequestedAt?->diffForHumans();

            // =========================
            // 🔔 DISPATCH EVENTS
            // =========================
            if ($newAnnouncements) {
                $this->dispatch('announce-new-parts', announcements: $newAnnouncements);
            }

            if ($delayedAnnouncements) {
                $this->dispatch('announce-delayed-parts', announcements: $delayedAnnouncements);
            }

        } catch (\Throwable $e) {
            Log::error('refreshRows error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }

    // ==========================================
    // 🚀 SUPPLY FEATURE METHODS
    // ==========================================

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
        
        // Dispatch event to start scanner
        $this->dispatch('supply-scanner-start');
    }

    #[On('supply-scan-success')]
    public function handleSupplyScan(string $code): void
    {
        // Only process if modal is open and in scan step
        if (!$this->showSupplyModal || $this->supplyStep !== 'scan') {
            return;
        }

        $code = trim($code);
        $targetPartNumber = $this->supplyingItem->part->part_number;

        if (strcasecmp($code, $targetPartNumber) === 0) {
            // Valid Scan
            $this->supplyScannedCode = $code;
            $this->supplyStep = 'quantity';
            
            // Set max quantity (stock only, ignore request quantity limit)
            $stock = $this->supplyingItem->part->stock;
            $requested = $this->supplyingItem->quantity;
            
            // UPDATE: Supply max quantity hanya dibatasi oleh stock
            $this->supplyMaxQty = $stock; 
            
            // Default input to requested qty (or stock if stock is lower)
            $this->supplyQtyInput = ($stock > 0) ? min($stock, $requested) : 0;
            
            $this->supplyError = '';
            
            if ($this->supplyMaxQty <= 0) {
                $this->supplyError = "Stok kosong atau habis (Stok: $stock).";
            }

            $this->success('Part terverifikasi!');
        } else {
            // Invalid Scan
            $this->supplyError = "Part salah! Discan: $code, Diminta: $targetPartNumber";
            // Optional: Play error sound or shake UI
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
        // Validation keeps checking against stock to prevent negative inventory
        $this->validate([
            'supplyQtyInput' => 'required|integer|min:1|max:' . ($this->supplyingItem->part->stock ?? 0)
        ]);

        if (!$this->supplyingItem) return;

        DB::beginTransaction();
        try {
            // 1. Lock Part & Re-check Stock
            $part = Singlepart::lockForUpdate()->find($this->supplyingItem->part_id);
            if (!$part || $part->stock < $this->supplyQtyInput) {
                throw new \Exception("Stok tidak mencukupi saat proses akhir.");
            }

            // 2. Generate Outgoing Number (Local logic reused from outgoing module)
            $outgoingNumber = $this->generateOutgoingNumber();

            // 3. Create Outgoing Record
            Outgoing::create([
                'outgoing_number' => $outgoingNumber,
                'part_id'         => $part->id,
                'quantity'        => $this->supplyQtyInput,
                'dispatched_by'   => Auth::id(),
                'dispatched_at'   => now(),
            ]);

            // 4. Create Movement & Update Stock
            $oldStock = $part->stock;
            $part->decrement('stock', $this->supplyQtyInput);

            Movement::create([
                'part_id' => $part->id,
                'type' => 'out',
                'pic' => Auth::id(),
                'qty' => $this->supplyQtyInput,
                'final_qty' => $oldStock - $this->supplyQtyInput,
            ]);

            // 5. Update RequestList Status
            $reqList = RequestList::lockForUpdate()->find($this->supplyingItem->id);
            // Logic: if supply >= requested OR current logic implies 'done'
            // For now, let's assume one-time supply closes the item line if matches quantity
            // Or partial logic:
            
            // Note: Since the table doesn't have 'supplied_qty' column explicitly in the provided context,
            // we assume simplistic status update. If supplied >= requested, fulfilled.
            if ($this->supplyQtyInput >= $reqList->quantity) {
                $reqList->update(['status' => 'fulfilled']);
            } 
            // If partial support is needed in DB schema (e.g. supplied_quantity column), add it here.
            // For now, adhering to strict "don't break existing", we just set fulfilled if qty matches.
            // If partial, it might remain pending or strict logic. 
            // Let's assume strict fulfillment for the requested amount in this context 
            // OR if partial is allowed, we might need to split the request? 
            // To be safe and simple: Update status based on logic.
            
            // 6. Update Parent Request Status
            $this->updateRequestStatus($reqList->request_id);

            DB::commit();

            $this->success("Supply berhasil! $outgoingNumber diterbitkan.");
            $this->showSupplyModal = false;
            $this->refreshRows(); // Refresh UI

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Gagal memproses: " . $e->getMessage());
        }
    }

    // Helper reused from PartOutgoing logic
    protected function generateOutgoingNumber(): string
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
        
        return sprintf('OUT-%s-%04d', $date, $newNumber);
    }

    protected function updateRequestStatus(int $requestId): void
    {
        $request = Request::with('items')->find($requestId);
        if (!$request) return;

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

<div wire:poll.5s="refreshRows" class="min-h-screen text-gray-900 dark:text-gray-100/90">
    <style>
        @keyframes scan-vertical {
            0% { top: 0%; opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { top: 100%; opacity: 0; }
        }
        .animate-scan {
            animation: scan-vertical 2s cubic-bezier(0.4, 0, 0.2, 1) infinite;
        }
    </style>

<x-header title="Permintaan Part Real-time"
          subtitle="Monitor permintaan part dari lini produksi secara langsung."
          separator
          progress-indicator />

<div class="max-w-6xl mx-auto space-y-6">

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <x-stat
        class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 rounded-2xl"
        title="Item Menunggu"
        :value="$totalWaiting"
        icon="o-clock"
        description="Total item yang belum terpenuhi" />
    <x-stat
        class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 rounded-2xl"
        title="Permintaan Terlambat"
        :value="$totalDelayed"
        icon="o-exclamation-triangle"
        description="Menunggu lebih dari 15 menit" />
    <x-stat
        class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 rounded-2xl"
        title="Permintaan Tertua"
        :value="$oldestRequest ?? '-'"
        icon="o-calendar-days"
        description="Waktu permintaan paling lama" />
    </div>

    <div class="space-y-3">
    @forelse($rows as $row)
    @php
        $urgencyClasses = match($row['urgency']) {
            'new' => 'border-l-4 border-l-sky-400/80 bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5',
            'delayed' => 'border-l-4 border-l-rose-400/80 bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 animate-pulse',
            default => 'border-l-4 border-l-amber-300/80 bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5',
        };
    @endphp
    <div
        wire:key="item-{{ $row['item_id'] }}"
        class="card card-side shadow-lg border border-gray-300 dark:border-white/10 rounded-2xl backdrop-blur-xl text-gray-900 dark:text-gray-100 {{ $urgencyClasses }} transition-all duration-300 hover:-translate-y-0.5 hover:shadow-xl group"
    >
        <div class="card-body p-5 flex-col sm:flex-row justify-between items-center gap-6">
            <div class="flex-1 w-full sm:w-auto">
                <div class="flex items-center gap-3">
                    <h2 class="card-title text-xl font-bold text-gray-900 dark:text-white">{{ $row['part_number'] }}</h2>
                    @if($row['is_urgent'] ?? false)
                        <x-badge value="URGENT" class="badge-error badge-sm animate-pulse" />
                    @endif
                </div>
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $row['part_name'] }}</p>
                <div class="text-xs text-gray-600 dark:text-gray-400 mt-2 flex items-center gap-2">
                    <x-icon name="o-calendar" class="w-4 h-4 inline-block text-indigo-600 dark:text-indigo-200" />
                    <span>{{ $row['requested_at']->diffForHumans() }} ({{ $row['requested_at']->format('H:i') }})</span>
                </div>
                @if(!empty($row['address']))
                    <div class="text-xs text-gray-600 dark:text-gray-400 mt-1 flex items-center gap-2">
                        <x-icon name="o-map-pin" class="w-4 h-4 inline-block text-amber-600 dark:text-amber-200" />
                        <span class="line-clamp-1">{{ $row['address'] }}</span>
                    </div>
                @endif
            </div>
            
            <div class="flex items-center gap-6 w-full sm:w-auto justify-between sm:justify-end">
                <div class="text-center min-w-[60px]">
                    <div class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-200">{{ (int)$row['quantity'] }}</div>
                    <div class="text-xs text-gray-700 dark:text-gray-300">KBN</div>
                </div>
                
                <div class="text-right flex flex-col items-end gap-2">
                    <div class="font-semibold text-gray-900 dark:text-white">{{ $row['destination'] }}</div>
                    
                    <div class="flex items-center gap-2">
                        @if($row['can_supply'])
                            <x-button 
                                label="SUPPLY" 
                                icon="o-qr-code" 
                                class="btn-primary btn-sm shadow-md" 
                                wire:click="openSupplyModal({{ $row['item_id'] }})"
                            />
                        @endif
                        
                        @if($row['urgency'] == 'delayed')
                            <x-badge value="TERLAMBAT" class="badge-error badge-outline" />
                        @elseif($row['urgency'] == 'new')
                            <x-badge value="BARU" class="badge-info badge-outline" />
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@empty
    <div class="text-center py-16 text-gray-600 dark:text-gray-300 bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 rounded-2xl">
        <x-icon name="o-check-circle" class="w-16 h-16 mx-auto mb-4 text-emerald-600 dark:text-emerald-300" />
        <h3 class="text-2xl font-bold text-gray-900 dark:text-white">Semua Permintaan Terpenuhi</h3>
        <p class="text-gray-600 dark:text-gray-400">Tidak ada item yang sedang menunggu saat ini.</p>
    </div>
    @endforelse
    </div>

</div>

    <!-- SUPPLY MODAL -->
    <x-modal wire:model="showSupplyModal" title="Supply Part" persistent separator class="backdrop-blur-sm">
        @if($supplyingItem)
            <div class="mb-4 p-3 bg-gray-50 dark:bg-white/5 rounded-lg border border-gray-200 dark:border-white/10">
                <div class="flex justify-between items-start">
                    <div>
                        <div class="text-xs text-gray-500 uppercase font-bold">Part Number</div>
                        <div class="text-lg font-bold text-primary">{{ $supplyingItem->part->part_number }}</div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">{{ $supplyingItem->part->part_name }}</div>
                    </div>
                    <div class="text-right">
                         <div class="text-xs text-gray-500 uppercase font-bold">Permintaan</div>
                         <div class="text-lg font-bold">{{ $supplyingItem->quantity }} <span class="text-xs font-normal">KBN</span></div>
                    </div>
                </div>
            </div>

            <!-- STEP 1: SCANNER -->
            <div x-show="$wire.supplyStep === 'scan'" class="space-y-4">
                <div wire:ignore x-data="supplyScanner()" x-init="init()" 
                     @supply-scanner-start.window="start()"
                     @supply-scanner-stop.window="stop()"
                     x-ref="scannerContainer"
                     tabindex="0" 
                     class="relative overflow-hidden rounded-3xl bg-black aspect-square max-h-[320px] mx-auto shadow-2xl ring-1 ring-white/10 flex items-center justify-center focus:outline-none select-none">
                    
                    <!-- Video Feed -->
                    <video id="supply-qr-video" class="absolute inset-0 w-full h-full object-cover opacity-90"></video>
                    <canvas id="supply-qr-canvas" class="hidden"></canvas>
                    
                    <!-- Loading State -->
                    <div x-show="!isScanning" class="absolute inset-0 flex flex-col items-center justify-center bg-gray-900 text-white p-4 text-center z-10">
                        <div class="loading loading-spinner loading-lg text-primary mb-3"></div>
                        <p class="text-xs font-medium uppercase tracking-wider animate-pulse text-gray-400">Inisialisasi Kamera...</p>
                    </div>
                    
                    <!-- Professional Scanner Overlay -->
                    <div x-show="isScanning" class="absolute inset-0 z-20 pointer-events-none">
                         <!-- Dark Overlay Outside Viewfinder -->
                         <div class="absolute inset-0 bg-black/40"></div>
                         
                         <!-- Active Viewfinder Box -->
                         <div class="absolute inset-0 flex items-center justify-center">
                             <div class="relative w-[70%] h-[70%] border border-white/20 rounded-xl overflow-hidden backdrop-blur-[1px] shadow-[0_0_0_9999px_rgba(0,0,0,0.4)]">
                                  
                                  <!-- Corner Markers -->
                                  <div class="absolute top-0 left-0 w-8 h-8 border-t-4 border-l-4 border-primary rounded-tl-lg shadow-sm"></div>
                                  <div class="absolute top-0 right-0 w-8 h-8 border-t-4 border-r-4 border-primary rounded-tr-lg shadow-sm"></div>
                                  <div class="absolute bottom-0 left-0 w-8 h-8 border-b-4 border-l-4 border-primary rounded-bl-lg shadow-sm"></div>
                                  <div class="absolute bottom-0 right-0 w-8 h-8 border-b-4 border-r-4 border-primary rounded-br-lg shadow-sm"></div>
                                  
                                  <!-- Laser Scan Animation -->
                                  <div class="absolute left-0 right-0 h-0.5 bg-primary/80 shadow-[0_0_15px_rgba(var(--primary-rgb),1)] animate-scan"></div>
                             </div>
                         </div>
                         
                         <!-- Status Text Overlay -->
                         <div class="absolute bottom-6 left-0 right-0 flex justify-center">
                              <div class="px-4 py-1.5 rounded-full bg-black/60 border border-white/10 backdrop-blur-md flex items-center gap-2">
                                  <div class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></div>
                                  <span class="text-white text-[10px] font-bold uppercase tracking-wider">Mencari QR Code</span>
                              </div>
                         </div>
                    </div>
                </div>

                <div class="text-center text-sm text-gray-500">
                    Arahkan kamera ke label part <b>{{ $supplyingItem->part->part_number }}</b>
                </div>

                @if($supplyError)
                    <div class="p-3 rounded-lg bg-red-100 text-red-700 text-sm font-semibold flex items-center gap-2 animate-bounce border border-red-200 shadow-sm">
                        <x-icon name="o-exclamation-circle" class="w-5 h-5 shrink-0" />
                        <span>{{ $supplyError }}</span>
                    </div>
                @endif
                
                <div class="divider text-xs font-medium text-gray-400">OPSI LAIN</div>
                
                <div class="flex gap-2 relative">
                     <!-- Dummy focusable element to prevent keyboard auto-popup -->
                     <div tabindex="-1" class="absolute w-0 h-0 overflow-hidden"></div>
                     
                     <x-input placeholder="Input Manual Part Number..." wire:model="supplyScannedCode" class="w-full" />
                     <x-button label="Verifikasi" wire:click="checkManualSupply" class="btn-primary" spinner />
                </div>
            </div>

            <!-- STEP 2: QUANTITY -->
            <div x-show="$wire.supplyStep === 'quantity'" class="space-y-6">
                <div class="text-center py-4">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-green-100 text-green-600 mb-3 shadow-inner ring-4 ring-green-50">
                        <x-icon name="o-check" class="w-8 h-8" />
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">Part Terverifikasi!</h3>
                    <p class="text-sm text-gray-500">Silakan masukkan jumlah yang akan disupply.</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="p-4 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 text-center">
                        <div class="text-[10px] text-blue-600 dark:text-blue-300 font-bold uppercase tracking-wide mb-1">Stok Tersedia</div>
                        <div class="text-2xl font-black text-blue-800 dark:text-blue-100">{{ $supplyingItem->part->stock }}</div>
                    </div>
                    <div class="p-4 rounded-xl bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-center">
                        <div class="text-[10px] text-gray-500 font-bold uppercase tracking-wide mb-1">Maksimal Supply</div>
                        <div class="text-2xl font-black text-gray-800 dark:text-gray-200">{{ $supplyMaxQty }}</div>
                    </div>
                </div>

                <x-input label="Quantity Supply" type="number" wire:model="supplyQtyInput" min="1" :max="$supplyMaxQty" class="input-lg text-center font-black text-2xl" />
                
                @if($supplyMaxQty == 0)
                     <div class="text-error text-sm text-center font-medium bg-red-50 p-2 rounded-lg">Stok habis, tidak dapat melakukan supply.</div>
                @endif
            </div>
        @endif
        
        <x-slot:actions>
            <x-button label="Batal" wire:click="closeSupplyModal" class="btn-ghost" />
            @if($supplyStep === 'quantity')
                <x-button label="Proses Supply" wire:click="processSupply" class="btn-primary" icon="o-paper-airplane" spinner="processSupply" :disabled="$supplyMaxQty == 0" />
            @endif
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