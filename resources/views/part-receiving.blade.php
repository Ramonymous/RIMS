<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Receiving;
use App\Models\Singlepart;
use App\Models\Movement;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

new #[Layout('components.layouts.app')] #[Title('Penerimaan Part')] class extends Component {
    use Toast;

    // Modal states
    public bool $showList = true;
    public bool $showConfirmationModal = false;
    public bool $viewModal = false;
    
    // Form fields for adding items
    public ?int $partId = null;
    public ?int $quantity = 1;
    public string $receivingNumber = '';
    public $receivedAt = null;
    public array $partsSearchable = [];
    
    // *** MODIFIKASI: Tambahkan properti untuk jenis sumber ***
    public string $sourceType = 'INHOUSE'; // Default ke INHOUSE
    
    // Basket/items
    public array $items = [];
    
    // Filters
    public string $filterReceivingNumber = '';
    public string $filterStatus = '';
    
    // View details
    public array $viewReceivingItems = [];
    public array $viewReceivingHeader = [];
    
    public function mount(): void
    {
        if (!auth()->user()->can('manage') && !auth()->user()->can('receiver')) {
            redirect('/')->with('error', 'Anda tidak memiliki izin untuk mengakses halaman ini.');
            return;
        }

        $this->receivedAt = now()->format('Y-m-d\TH:i');
        $this->items = session('receiving_items', []);
        $this->receivingNumber = session('receiving_number', '');
        
        // *** MODIFIKASI: Generate nomor penerimaan jika tipe default INHOUSE dan belum ada nomor ***
        if (empty($this->receivingNumber) || $this->sourceType === 'INHOUSE') {
            $this->generatingReceivingNumber();
        }
    }
    
    private function updateSession(): void
    {
        $currentItems = session('receiving_items', []);
        $currentNumber = session('receiving_number', '');
        
        if ($currentItems !== $this->items) {
            session(['receiving_items' => $this->items]);
        }
        
        if ($currentNumber !== $this->receivingNumber) {
            session(['receiving_number' => $this->receivingNumber]);
        }
    }
    
    // *** MODIFIKASI: Method untuk generate nomor penerimaan INHOUSE ***
    public function generatingReceivingNumber(): void
    {
        if ($this->sourceType === 'INHOUSE') {
            $datePrefix = 'REC-' . now()->format('ymd');
            
            // Cari nomor terakhir yang dibuat hari ini
            // Di sini saya mengasumsikan model Receiving memiliki scope query byReceivingNumber 
            // jika ada pada model Receiving yang Anda miliki, jika tidak, cukup where('receiving_number', 'like', $datePrefix . '-%')
            
            $lastReceiving = Receiving::where('receiving_number', 'like', $datePrefix . '-%')
                ->orderByDesc('receiving_number')
                ->first();
                
            $lastNumber = 0;
            if ($lastReceiving) {
                // Contoh: REC-251216-001 -> ambil 001
                $parts = explode('-', $lastReceiving->receiving_number);
                $lastNumber = (int)end($parts);
            }
            
            $newNumber = $lastNumber + 1;
            $this->receivingNumber = $datePrefix . '-' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
        } else {
            // Kosongkan jika SUBCONT
            $this->receivingNumber = '';
        }
    }

    // *** MODIFIKASI: Listener saat sourceType berubah ***
    public function updatedSourceType($value): void
    {
        // Panggil generatingReceivingNumber untuk INHOUSE atau clear untuk SUBCONT
        if ($value === 'INHOUSE') {
            $this->generatingReceivingNumber();
        } else {
            $this->receivingNumber = '';
        }
        $this->updateSession();
    }
    
    public function addItem(): void
    {
        if (!$this->partId || !$this->quantity || $this->quantity < 1) {
            $this->error('Pilih part dan masukkan quantity yang valid');
            return;
        }
        
        $part = Singlepart::find($this->partId);
        
        if (!$part) {
            $this->error('Part tidak ditemukan');
            return;
        }
        
        $existingIndex = array_search($this->partId, array_column($this->items, 'part_id'));
        
        if ($existingIndex !== false) {
            $this->items[$existingIndex]['quantity'] += $this->quantity;
            $this->success("Quantity {$part->part_number} diperbarui");
        } else {
            $this->items[] = [
                'part_id' => $part->id,
                'part_number' => $part->part_number,
                'customer_code' => $part->customer_code,
                'model' => $part->model,
                'variant' => $part->variant,
                'quantity' => $this->quantity,
            ];
            $this->success("Item {$part->part_number} ditambahkan");
        }
        
        $this->reset('partId', 'quantity');
        $this->quantity = 1;
        $this->partsSearchable = [];
        $this->updateSession();
        $this->dispatch('item-added');
    }

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
                ->orderBy('part_number')
                ->limit(10)
                ->get()
                ->toArray();
        });
    }
    
    public function removeItem(int $index): void
    {
        if (isset($this->items[$index])) {
            unset($this->items[$index]);
            $this->items = array_values($this->items);
            $this->updateSession();
        }
    }
    
    public function clearAllItems(): void
    {
        $this->items = [];
        session()->forget('receiving_items');
        $this->success('Semua item dihapus');
    }
    
    public function updatedItems($value, $key): void
    {
        $parts = explode('.', $key);
        if (count($parts) === 2 && $parts[1] === 'quantity') {
            $index = (int)$parts[0];
            if (isset($this->items[$index])) {
                if ($this->items[$index]['quantity'] < 1) {
                    $this->items[$index]['quantity'] = 1;
                    $this->error('Kuantitas minimal 1');
                }
            }
        }
        $this->updateSession();
    }
    
    public function submit(): void
    {
        if (empty($this->items)) {
            $this->error('Minimal 1 item harus ditambahkan');
            return;
        }
        
        if (!$this->receivedAt) {
            $this->error('Tanggal penerimaan harus diisi');
            return;
        }
        
        $this->showConfirmationModal = true;
    }
    
    public function saveDraft(): void
    {
        $this->saveReceiving('draft');
    }
    
    public function saveCompleted(): void
    {
        $this->saveReceiving('completed');
    }
    
    private function saveReceiving(string $status): void
    {
        if (empty($this->items)) {
            $this->error('Minimal 1 item harus ditambahkan');
            return;
        }
        
        if (!$this->receivingNumber) {
            $this->error('Nomor penerimaan harus diisi');
            return;
        }
        
        // *** MODIFIKASI: Validasi panjang nomor penerimaan untuk SUBCONT ***
        if ($this->sourceType === 'SUBCONT' && strlen($this->receivingNumber) < 10) {
            $this->error('Nomor penerimaan untuk SUBCONT minimal 10 karakter.');
            return;
        }
        
        try {
            DB::beginTransaction();
            
            // Bulk insert for Receiving records
            $receivingsData = array_map(function($item) use ($status) {
                return [
                    'receiving_number' => $this->receivingNumber,
                    'part_id' => $item['part_id'],
                    'quantity' => $item['quantity'],
                    'received_by' => auth()->id(),
                    'received_at' => $this->receivedAt,
                    'status' => $status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }, $this->items);
            Receiving::insert($receivingsData);

            // Per-item stock/movement logic for completed status
            if ($status === 'completed') {
                foreach ($this->items as $item) {
                    $singlepart = Singlepart::lockForUpdate()->find($item['part_id']);
                    if ($singlepart) {
                        $oldStock = $singlepart->stock;
                        $singlepart->increment('stock', $item['quantity']);
                        Movement::create([
                            'part_id' => $item['part_id'],
                            'type' => 'in',
                            'pic' => auth()->id(),
                            'qty' => $item['quantity'],
                            'final_qty' => $oldStock + $item['quantity'],
                        ]);
                    }
                }
            }
            
            DB::commit();
            
            $this->success('Penerimaan berhasil disimpan sebagai ' . ($status === 'draft' ? 'Draft' : 'Selesai') . ' dengan nomor ' . $this->receivingNumber);
            $this->items = [];
            $this->receivingNumber = '';
            session()->forget('receiving_items');
            session()->forget('receiving_number');
            $this->showConfirmationModal = false;
            $this->showList = true;
            $this->receivedAt = now()->format('Y-m-d\TH:i');
            
            // Generate nomor baru setelah berhasil disimpan jika INHOUSE
            if ($this->sourceType === 'INHOUSE') {
                 $this->generatingReceivingNumber();
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Penerimaan save error', [
                'status' => $status,
                'receiving_number' => $this->receivingNumber,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);
            $this->error('Terjadi kesalahan sistem. Silakan coba lagi atau hubungi administrator.');
        }
    }
    
    public function editDraft(string $receivingNumber): void
    {
        $receivings = Receiving::where('receiving_number', $receivingNumber)
            ->with('part')
            ->get();
        
        if ($receivings->isEmpty()) {
            $this->error('Data penerimaan tidak ditemukan');
            return;
        }
        
        $first = $receivings->first();
        
        if ($first->status !== 'draft') {
            $this->error('Hanya penerimaan berstatus Draft yang dapat diedit');
            return;
        }
        
        $this->items = $receivings->map(fn($r) => [
            'part_id' => $r->part_id,
            'part_number' => $r->part->part_number,
            'customer_code' => $r->part->customer_code,
            'model' => $r->part->model,
            'variant' => $r->part->variant,
            'quantity' => $r->quantity
        ])->toArray();
        
        $this->receivingNumber = $receivingNumber; // Pastikan nomor penerimaan di-load
        
        // Coba tentukan sourceType berdasarkan format nomor (sederhana)
        if (str_starts_with($receivingNumber, 'REC-')) {
            $this->sourceType = 'INHOUSE';
        } else {
            $this->sourceType = 'SUBCONT';
        }
        
        $this->receivedAt = $first->received_at->format('Y-m-d\TH:i');
        $this->updateSession();
        
        Receiving::where('receiving_number', $receivingNumber)->delete();
        
        $this->showList = false;
        $this->success('Draft dimuat. Silakan edit dan simpan kembali.');
    }
    
    public function openView(string $receivingNumber): void
    {
        // Optimasi: Gunakan Eager Loading untuk mencegah N+1
        $receivings = Receiving::where('receiving_number', $receivingNumber)
            ->with(['part', 'receivedBy'])
            ->get();
        
        if ($receivings->isEmpty()) {
            $this->error('Data penerimaan tidak ditemukan');
            return;
        }
        
        $first = $receivings->first();
        
        $this->viewReceivingHeader = [
            'receiving_number' => $first->receiving_number,
            'received_by' => $first->receivedBy?->name ?? 'N/A',
            'received_at' => $first->received_at->format('d/m/Y H:i'),
            'status' => $first->status,
        ];
        
        $this->viewReceivingItems = $receivings->map(fn($r) => [
            'part_number' => $r->part->part_number,
            'customer_code' => $r->part->customer_code,
            'model' => $r->part->model,
            'variant' => $r->part->variant,
            'quantity' => $r->quantity,
        ])->toArray();
        
        $this->viewModal = true;
    }
    
    public function delete(string $receivingNumber): void
    {
        $receivings = Receiving::where('receiving_number', $receivingNumber)->get();
        
        if ($receivings->isEmpty()) {
            $this->error('Data penerimaan tidak ditemukan');
            return;
        }
        
        $first = $receivings->first();
        
        if ($first->status !== 'draft') {
            $this->error('Hanya penerimaan berstatus Draft yang dapat dihapus');
            return;
        }
        
        try {
            Receiving::where('receiving_number', $receivingNumber)->delete();
            $this->success('Penerimaan berhasil dihapus');
        } catch (\Exception $e) {
            Log::error('Penerimaan delete error', [
                'receiving_number' => $receivingNumber,
                'error' => $e->getMessage(),
            ]);
            $this->error('Terjadi kesalahan sistem. Silakan coba lagi atau hubungi administrator.');
        }
    }
    
    public function resetFilter(): void
    {
        $this->filterReceivingNumber = '';
        $this->filterStatus = '';
    }
    
    public function totalItems(): int
    {
        return array_sum(array_column($this->items, 'quantity'));
    }
    
    public function hasItems(): bool
    {
        return !empty($this->items);
    }
    
    public function toggleView(): void
    {
        $this->showList = !$this->showList;
        
        if (!$this->showList) {
            // Load draft dari session
            $this->items = session('receiving_items', []);
            $this->receivingNumber = session('receiving_number', '');
            
            if (empty($this->items)) {
                $this->receivedAt = now()->format('Y-m-d\TH:i');
                // Pastikan generate nomor baru jika masuk ke form kosong dan jenisnya INHOUSE
                if ($this->sourceType === 'INHOUSE') {
                    $this->generatingReceivingNumber();
                }
            } else {
                 // Jika ada draft, coba tentukan sourceType
                 if (str_starts_with($this->receivingNumber, 'REC-')) {
                    $this->sourceType = 'INHOUSE';
                 } else {
                    $this->sourceType = 'SUBCONT';
                 }
            }
        }
    }
    
    public function cancelAndClearDraft(): void
    {
        $this->items = [];
        $this->receivingNumber = '';
        session()->forget('receiving_items');
        session()->forget('receiving_number');
        $this->showList = true;
        $this->receivedAt = now()->format('Y-m-d\TH:i');
        $this->success('Draft dibatalkan dan dihapus');
    }
    
    // OPTIMASI: PENTING
    // Mengubah logic fetch agar tidak mengambil ribuan row sekaligus dan membebani server
    public function getReceivingsProperty()
    {
        // Add select to only fetch needed columns
        $rawItems = Receiving::query()
            ->select('receiving_number', 'received_at', 'received_by', 'status', 'quantity')
            ->with('receivedBy:id,name')
            ->when($this->filterReceivingNumber, fn($q) => 
                $q->byReceivingNumber($this->filterReceivingNumber)
            )
            ->when($this->filterStatus, fn($q) => $q->where('status', $this->filterStatus))
            ->orderByDesc('received_at')
            ->limit(200)
            ->get();

        return $rawItems->groupBy('receiving_number')
            ->map(function($items) {
                $first = $items->first();
                return [
                    'receiving_number' => $first->receiving_number,
                    'received_by' => $first->receivedBy?->name ?? 'N/A',
                    'received_at' => $first->received_at->format('d/m/Y H:i'),
                    'status' => $first->status,
                    'total_parts' => $items->count(),
                    'total_quantity' => $items->sum('quantity'),
                ];
            })
            ->values();
    }
    
    public function getPartsOptionsProperty()
    {
        return Cache::remember('parts_options_list', 300, function () {
            return Singlepart::select('id', 'part_number', 'customer_code', 'model', 'variant')
                ->orderBy('part_number')
                ->limit(500) // SAFETY CAP
                ->get();
        });
    }
    
    public function with(): array
    {
        return [
            'receivings' => $this->receivings,
            'partsOptions' => $this->partsOptions,
        ];
    }
}; ?>

<div class="min-h-screen text-gray-900 dark:text-gray-100/90">
    @if($showList)
        {{-- List View (Tidak Berubah) --}}
        <div class="space-y-6">
            {{-- Header --}}
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Daftar Penerimaan Part</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Kelola penerimaan barang masuk</p>
                </div>
                <x-button label="Buat Penerimaan Baru" wire:click="toggleView" icon="o-plus" class="btn-primary" />
            </div>
            
            {{-- Filter Section --}}
            <x-card shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 text-gray-900 dark:text-gray-100">
                <div class="grid grid-cols-3 gap-4">
                    {{-- Optimasi: debounce --}}
                    <x-input wire:model.live.debounce.400ms="filterReceivingNumber" label="Nomor Penerimaan" placeholder="Cari nomor..." icon="o-magnifying-glass" />
                    <x-select wire:model.live="filterStatus" label="Status" placeholder="Semua Status" :options="[
                        ['id' => 'draft', 'name' => 'Draft'],
                        ['id' => 'completed', 'name' => 'Selesai'],
                        ['id' => 'cancelled', 'name' => 'Dibatalkan']
                    ]" />
                    <div class="flex items-end">
                        <x-button label="Reset Filter" wire:click="resetFilter" icon="o-arrow-path" class="btn-ghost" />
                    </div>
                </div>
            </x-card>

            {{-- Receivings Table --}}
            <x-card shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 text-gray-900 dark:text-gray-100">
                <x-table :headers="[
                    ['key' => 'receiving_number', 'label' => 'Nomor Penerimaan'],
                    ['key' => 'received_at', 'label' => 'Tanggal Terima'],
                    ['key' => 'received_by', 'label' => 'Diterima Oleh'],
                    ['key' => 'total_parts', 'label' => 'Jumlah Part'],
                    ['key' => 'total_quantity', 'label' => 'Total Qty'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'actions', 'label' => 'Aksi'],
                ]" :rows="$receivings">
                    @scope('cell_status', $receiving)
                        @if($receiving['status'] === 'draft')
                            <x-badge value="Draft" class="badge-warning" />
                        @elseif($receiving['status'] === 'completed')
                            <x-badge value="Selesai" class="badge-success" />
                        @else
                            <x-badge value="Dibatalkan" class="badge-error" />
                        @endif
                    @endscope

                    @scope('cell_actions', $receiving)
                        <div class="flex gap-2">
                            <x-button icon="o-eye" wire:click="openView('{{ $receiving['receiving_number'] }}')" class="btn-sm btn-ghost" tooltip="Lihat Detail" />
                            @if($receiving['status'] === 'draft')
                                <x-button icon="o-pencil" wire:click="editDraft('{{ $receiving['receiving_number'] }}')" class="btn-sm btn-ghost" tooltip="Edit Draft" />
                                <x-button icon="o-trash" wire:click="delete('{{ $receiving['receiving_number'] }}')" wire:confirm="Yakin ingin menghapus penerimaan ini?" class="btn-sm btn-ghost text-error" tooltip="Hapus" />
                            @endif
                        </div>
                    @endscope
                </x-table>
                {{-- Info for limited data --}}
                <div class="text-xs text-gray-500 mt-2 text-right italic">
                    * Menampilkan max 200 data terakhir untuk performa. Gunakan filter untuk mencari data lama.
                </div>
            </x-card>
        </div>
    @else
        {{-- Create/Edit Form View --}}
        <div class="space-y-6">
            {{-- Header --}}
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Buat Penerimaan Part</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Tambahkan item ke keranjang penerimaan</p>
                </div>
                <div class="flex gap-2">
                    @if($this->hasItems())
                        <x-button label="Batal & Hapus Draft" wire:click="cancelAndClearDraft" icon="o-x-mark" class="btn-error btn-ghost" wire:confirm="Yakin ingin membatalkan dan menghapus semua item?" />
                    @endif
                    <x-button label="Kembali ke Daftar" wire:click="toggleView" icon="o-arrow-left" class="btn-ghost" />
                </div>
            </div>

            <form wire:submit="submit" class="space-y-6">
                {{-- Receipt Info (MODIFIKASI DI SINI) --}}
                <x-card title="Informasi Penerimaan" shadow separator class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 text-gray-900 dark:text-gray-100">
                    <div class="grid grid-cols-3 gap-4">
                        {{-- MODIFIKASI: Pilihan Jenis Sumber --}}
                        <x-select 
                            label="Jenis Sumber" 
                            wire:model.live="sourceType" 
                            :options="[
                                ['id' => 'INHOUSE', 'name' => 'INHOUSE (Auto Generate)'],
                                ['id' => 'SUBCONT', 'name' => 'SUBCONT (Input Manual)']
                            ]" 
                            required 
                        />
                        
                        {{-- Optimasi: blur/debounce --}}
                        {{-- MODIFIKASI: Terapkan Readonly jika INHOUSE --}}
                        <x-input 
                            label="Nomor Penerimaan" 
                            type="text" 
                            wire:model.blur="receivingNumber" 
                            placeholder="Masukkan nomor penerimaan" 
                            required 
                            :readonly="$sourceType === 'INHOUSE'"
                            class="{{ $sourceType === 'INHOUSE' ? 'bg-gray-200 dark:bg-gray-700' : '' }}"
                        />
                        
                        <x-input label="Tanggal Penerimaan" type="datetime-local" wire:model.blur="receivedAt" required />
                    </div>
                    
                    {{-- MODIFIKASI: Tambahkan pesan validasi khusus SUBCONT --}}
                    @if($sourceType === 'SUBCONT')
                        <p class="text-xs text-orange-500 mt-1">Nomor penerimaan SUBCONT harus diisi minimal 10 karakter.</p>
                    @endif
                </x-card>

                {{-- Add Item (Tidak Berubah) --}}
                <x-card title="Tambah Item" shadow separator class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 text-gray-900 dark:text-gray-100">
                    <div class="grid grid-cols-12 gap-4 items-end">
                        <div class="col-span-5 relative z-30">
                            <x-choices 
                                label="Pilih Part"
                                wire:model.live.debounce.300ms="partId"
                                placeholder="Ketik untuk mencari..."
                                :options="$partsSearchable"
                                wire:search="search"
                                searchable
                                single
                                min-chars="2"
                            />
                        </div>
                        <div class="col-span-3">
                            <x-input label="Jumlah" type="number" min="1" wire:model="quantity" />
                        </div>
                        <div class="col-span-2">
                            <x-button wire:click="addItem" icon="o-plus" class="btn-primary w-full" :disabled="!$partId" spinner="addItem">
                                Tambah
                            </x-button>
                        </div>
                        <div class="col-span-2">
                            <x-button wire:click="clearAllItems" icon="o-trash" class="btn-outline border-red-300 text-red-600 w-full" :disabled="!$this->hasItems()" wire:confirm="Yakin ingin menghapus semua item?">
                                Clear All
                            </x-button>
                        </div>
                    </div>
                </x-card>

                {{-- Items List (Tidak Berubah) --}}
                <x-card title="Item yang akan diterima ({{ count($items) }} jenis)" shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 text-gray-900 dark:text-gray-100">
                    <div 
                        class="space-y-3 max-h-80 overflow-y-auto"
                        x-data="{}"
                        @item-added.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight; })"
                    >
                        @forelse($items as $index => $item)
                            <div wire:key="item-{{ $index }}" class="relative flex justify-between items-center p-4 rounded-xl border border-gray-300 dark:border-white/10 bg-gray-100 dark:bg-white/5 backdrop-blur">
                                <span class="absolute left-0 top-0 h-full w-1 bg-gradient-to-b from-blue-400 via-indigo-400 to-purple-500"></span>
                                <div>
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $item['part_number'] }}</span>
                                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $item['customer_code'] }} | {{ $item['model'] }} - {{ $item['variant'] }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    {{-- Optimasi: debounce --}}
                                    <x-input type="number" min="1" wire:model.live.debounce.500ms="items.{{ $index }}.quantity" class="w-24 text-center" />
                                    <x-button icon="o-trash" wire:click="removeItem({{ $index }})" class="btn-circle btn-ghost btn-sm text-red-500" wire:confirm="Yakin ingin menghapus item ini?"/>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-12">
                                <x-icon name="o-inbox" class="w-12 h-12 mx-auto text-gray-400 dark:text-gray-300" />
                                <p class="mt-4 text-gray-600 dark:text-gray-300">Belum ada item yang ditambahkan.</p>
                            </div>
                        @endforelse
                    </div>

                    @if($this->hasItems())
                        <x-slot:actions>
                            <div class="w-full text-right font-semibold text-gray-900 dark:text-white">
                                Total: {{ $this->totalItems() }} pcs
                            </div>
                        </x-slot:actions>
                    @endif
                </x-card>

                {{-- Submit Button (Tidak Berubah) --}}
                <div class="flex justify-end gap-2">
                    <x-button label="Simpan sebagai Draft" wire:click="saveDraft" icon="o-document" class="btn-ghost" :disabled="!$this->hasItems()" spinner="saveDraft" />
                    <x-button type="submit" label="Simpan & Selesaikan" icon="o-check" class="btn-primary px-8" :disabled="!$this->hasItems()" spinner="submit" />
                </div>
            </form>
        </div>
    @endif

    {{-- Confirmation Modal (Tidak Berubah) --}}
    <x-modal wire:model="showConfirmationModal" title="Konfirmasi Penerimaan" persistent separator>
        <div class="space-y-4">
            <p class="text-gray-800 dark:text-gray-200">Anda akan menyimpan data penerimaan berikut. Mohon periksa kembali sebelum melanjutkan:</p>

            <div class="rounded-lg">
                <h4 class="font-bold text-lg mb-2 text-gray-900 dark:text-white">Ringkasan Penerimaan</h4>
                <p class="text-gray-800 dark:text-gray-200"><strong>Tanggal:</strong> {{ \Carbon\Carbon::parse($receivedAt)->format('d M Y H:i') }}</p>
                <p class="text-gray-800 dark:text-gray-200"><strong>Total Jenis Item:</strong> {{ count($items) }}</p>
                <p class="text-gray-800 dark:text-gray-200"><strong>Total Kuantitas:</strong> {{ $this->totalItems() }} pcs</p>
            </div>

            <div class="max-h-60 overflow-y-auto mt-4 border-t pt-4 border-gray-300 dark:border-gray-700">
                <h4 class="font-bold text-lg mb-2 text-gray-900 dark:text-white">Detail Item</h4>
                @foreach($items as $index => $item)
                    <div class="flex justify-between items-center py-2 border-b last:border-b-0 border-gray-300 dark:border-gray-700">
                        <div class="flex-1">
                            <span class="font-medium text-gray-900 dark:text-white">{{ $item['part_number'] }}</span>
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $item['customer_code'] }} | {{ $item['model'] }} - {{ $item['variant'] }}</p>
                        </div>
                        <div class="font-semibold text-gray-900 dark:text-white text-right">{{ $item['quantity'] }} pcs</div>
                    </div>
                @endforeach
            </div>
        </div>
        <x-slot:actions>
            <x-button label="Batal" class="btn-ghost" @click="$wire.set('showConfirmationModal', false)" />
            <x-button label="Simpan & Lanjutkan" class="btn-primary" wire:click="saveCompleted" spinner="saveCompleted" />
        </x-slot:actions>
    </x-modal>

    {{-- View Modal (Tidak Berubah) --}}
    <x-modal wire:model="viewModal" title="Detail Penerimaan Part" class="max-w-3xl">
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-4 bg-gray-100 dark:bg-base-200 p-4 rounded">
                <div>
                    <p class="text-sm text-gray-600 dark:text-gray-500">Nomor Penerimaan</p>
                    <p class="font-semibold text-gray-900 dark:text-gray-200">{{ $viewReceivingHeader['receiving_number'] ?? '-' }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600 dark:text-gray-500">Tanggal Terima</p>
                    <p class="font-semibold text-gray-900 dark:text-gray-200">{{ $viewReceivingHeader['received_at'] ?? '-' }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600 dark:text-gray-500">Diterima Oleh</p>
                    <p class="font-semibold text-gray-900 dark:text-gray-200">{{ $viewReceivingHeader['received_by'] ?? '-' }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600 dark:text-gray-500">Status</p>
                    @if(isset($viewReceivingHeader['status']))
                        @if($viewReceivingHeader['status'] === 'draft')
                            <x-badge value="Draft" class="badge-warning" />
                        @elseif($viewReceivingHeader['status'] === 'completed')
                            <x-badge value="Selesai" class="badge-success" />
                        @else
                            <x-badge value="Dibatalkan" class="badge-error" />
                        @endif
                    @endif
                </div>
            </div>

            <div>
                <h4 class="font-semibold mb-2 text-gray-900 dark:text-white">Daftar Part</h4>
                <x-table :headers="[
                    ['key' => 'part_number', 'label' => 'Part Number'],
                    ['key' => 'customer_code', 'label' => 'Customer'],
                    ['key' => 'model', 'label' => 'Model'],
                    ['key' => 'variant', 'label' => 'Variant'],
                    ['key' => 'quantity', 'label' => 'Qty'],
                ]" :rows="$viewReceivingItems" />
            </div>

            <div class="flex justify-end pt-4 border-t border-gray-300 dark:border-gray-700">
                <x-button label="Tutup" @click="$wire.viewModal = false" />
            </div>
        </div>
    </x-modal>
</div>