<?php

    use Livewire\Volt\Component;
    use App\Models\Singlepart;
    use Mary\Traits\Toast;
    use Livewire\Attributes\Computed;
    use Livewire\Attributes\Layout;
    use Livewire\Attributes\Title;
    use Maatwebsite\Excel\Facades\Excel;
    use Maatwebsite\Excel\Excel as ExcelType;
    use Livewire\WithFileUploads; 
    use App\Jobs\ImportPartsJob; // Import the new Job

    new
    #[Layout('components.layouts.app')]
    #[Title('Parts Management')]
    class extends Component {
        use Toast;
        use WithFileUploads; 

        /* ---------- state ---------- */
        public int  $perPage = 5;
        public bool $showPartModal = false;
        public bool $showImportModal = false;
        // Note: The $importFile property now holds an instance of Livewire\Features\SupportFileUploads\TemporaryUploadedFile
        public $importFile = null; 
        // These counts are no longer updated synchronously, but kept for UI structure/future async updates
        public int $importedCount = 0; 
        public int $skippedCount = 0;

        // filters
        public string $filterPartNumber = '';
        public string $filterCustomer = '';
        public string $filterModel = '';
        public string $filterVariant = '';

        public array $headers = [];

        // part form
        public ?int   $partId           = null;
        public string $part_number      = '';
        public string $part_name        = '';
        public string $customer_code    = '';
        public string $supplier_code    = '';
        public string $model            = '';
        public string $variant          = '';
        public string $standard_packing = '';
        public string $stock            = '';
        public string $address          = '';
        public bool   $is_active        = true;

        /* ---------- lifecycle ---------- */
        public function mount(): void
        {
            $this->headers = [
                ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
                ['key' => 'part_number', 'label' => 'Nomor Suku'],
                ['key' => 'part_name', 'label' => 'Nama Suku'],
                ['key' => 'customer_code', 'label' => 'Kode Pelanggan'],
                ['key' => 'supplier_code', 'label' => 'Kode Pemasok'],
                ['key' => 'model', 'label' => 'Model'],
                ['key' => 'variant', 'label' => 'Varian'],
                ['key' => 'standard_packing', 'label' => 'Pengemasan Standar'],
                ['key' => 'stock', 'label' => 'Stok'],
                ['key' => 'address', 'label' => 'Alamat'],
                ['key' => 'is_active', 'label' => 'Aktif'],
                ['key' => 'actions', 'label' => 'Aksi'],
            ];
        }

        /* ---------- computed ---------- */
        #[Computed]
        public function getPartsProperty()
        {
            return Singlepart::query()
                ->select('id', 'part_number', 'part_name', 'customer_code', 'supplier_code', 'model', 'variant', 'standard_packing', 'stock', 'address', 'is_active')
                ->when($this->filterPartNumber, fn($q) => $q->where('part_number', 'like', '%' . $this->filterPartNumber . '%'))
                ->when($this->filterCustomer, fn($q) => $q->where('customer_code', 'like', '%' . $this->filterCustomer . '%'))
                ->when($this->filterModel, fn($q) => $q->where('model', 'like', '%' . $this->filterModel . '%'))
                ->when($this->filterVariant, fn($q) => $q->where('variant', 'like', '%' . $this->filterVariant . '%'))
                ->latest()
                ->paginate($this->perPage);
        }

        // Expose data to the view
        public function with(): array
        {
            return [
                'parts' => $this->parts,
            ];
        }

        /* ---------- actions ---------- */
        public function openCreate(): void
        {
            $this->reset('partId', 'part_number', 'part_name', 'customer_code', 'supplier_code', 'model', 'variant', 'standard_packing', 'stock', 'address');
            $this->is_active = true;
            $this->showPartModal = true;
        }

        public function openEdit(Singlepart $part): void
        {
            $this->partId           = $part->id;
            $this->part_number      = $part->part_number;
            $this->part_name        = $part->part_name;
            $this->customer_code    = $part->customer_code;
            $this->supplier_code    = $part->supplier_code;
            $this->model            = $part->model;
            $this->variant          = $part->variant;
            $this->standard_packing = $part->standard_packing;
            $this->stock            = $part->stock;
            $this->address          = $part->address;
            $this->is_active        = (bool) $part->is_active;
            $this->showPartModal    = true;
        }

        public function savePart(): void
        {
            $rules = [
                'part_number'      => 'required|string|max:255',
                'part_name'        => 'required|string|max:255',
                'customer_code'    => 'required|string|max:255',
                'supplier_code'    => 'nullable|string|max:255',
                'model'            => 'nullable|string|max:255',
                'variant'          => 'nullable|string|max:255',
                'standard_packing' => 'required|string|max:255',
                'stock'            => 'nullable|numeric|min:0',
                'address'          => 'nullable|string|max:255',
                'is_active'        => 'boolean',
            ];

            $this->validate($rules);

            $data = [
                'part_number'      => $this->part_number,
                'part_name'        => $this->part_name,
                'customer_code'    => $this->customer_code,
                'supplier_code'    => $this->supplier_code,
                'model'            => $this->model,
                'variant'          => $this->variant,
                'standard_packing' => $this->standard_packing,
                'stock'            => $this->stock,
                'address'          => $this->address,
                'is_active'        => $this->is_active,
            ];

            Singlepart::updateOrCreate(['id' => $this->partId], $data);

            $this->success($this->partId ? 'Suku diperbarui' : 'Suku dibuat');
            $this->showPartModal = false;
        }

        public function deletePart(Singlepart $part): void
        {
            $part->delete();
            $this->success('Suku dihapus');
        }

        public function openImport(): void
        {
            $this->reset('importFile', 'importedCount', 'skippedCount');
            $this->showImportModal = true;
        }

        /**
         * Download template Excel dengan baris header dan data sampel.
         */
        public function downloadTemplate()
        {
            $headers = [
                'part_number',
                'part_name',
                'customer_code',
                'supplier_code',
                'model',
                'variant',
                'standard_packing',
                'stock',
                'address',
                'is_active',
            ];
            
            // Baris contoh untuk membantu pengguna memahami format
            $sampleRow = [
                'P001-A',           // Contoh: Nomor Suku (Wajib)
                'Bumper Depan',     // Contoh: Nama Suku (Wajib)
                'CUST-001',         // Contoh: Kode Pelanggan (Wajib)
                'SUP-A1',           // Contoh: Kode Pemasok (Opsional)
                'MODEL-X',          // Contoh: Model (Opsional)
                'VARIANT-B',        // Contoh: Varian (Opsional)
                '10',               // Contoh: Pengemasan Standar (Wajib, angka)
                '100',              // Contoh: Stok saat ini (Opsional)
                'A-1-01',           // Contoh: Alamat (Opsional)
                '1',                // Contoh: Aktif (Wajib: 1 atau 0)
            ];

            // Data yang akan diekspor (Header + Baris Sampel)
            $exportData = [$headers, $sampleRow];

            // Gunakan metode 'fromArray' untuk memastikan data diekspor dengan benar
            return Excel::download(new class($exportData) implements \Maatwebsite\Excel\Concerns\FromArray {
                private $data;

                public function __construct(array $data)
                {
                    $this->data = $data;
                }

                public function array(): array
                {
                    return $this->data;
                }
            }, 'parts_template.xlsx', ExcelType::XLSX);
        }

        /**
         * Memindahkan file ke storage dan mengirimkan Job ke antrian.
         */
        public function importParts(): void
        {
            // Add file validation using Livewire's validation features
            $this->validate([
                'importFile' => 'required|file|mimes:xlsx,xls|max:10240', // Max 10MB
            ]);

            if (is_null($this->importFile) || !$this->importFile->exists()) {
                $this->error('File impor tidak ditemukan atau tidak valid setelah diunggah.');
                return;
            }

            try {
                // 1. Pindahkan file ke persistent storage (misalnya, storage/app/public/imports)
                $filePath = $this->importFile->store('public/imports');

                // 2. Dispatch the job ke antrian.
                // Mengambil ID pengguna saat ini untuk konteks di Job.
                $userId = auth()->id() ?? 0; 
                ImportPartsJob::dispatch($filePath, $userId);

                // 3. Beri tahu pengguna bahwa proses telah dimulai
                $this->success("Impor suku cadang dimulai di latar belakang. Proses ini mungkin memakan waktu beberapa saat.");
                
                // Reset UI
                $this->showImportModal = false;
                $this->importFile = null;

            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Failed to dispatch import job: " . $e->getMessage());
                $this->error('Gagal memulai impor: ' . $e->getMessage());
            } 
        }

        private function error(string $title): void
        {
            $this->toast('error', $title, '', 'toast-top toast-end', 'o-exclamation-circle', 'alert-error', 5000);
        }
    };
    ?>

    <div>
        {{-- HEADER --}}
        <x-header :title="'Bagian'" :subtitle="'Kelola suku cadang tunggal'" separator progress-indicator>
            <x-slot:actions>
                <x-button :label="'Impor'" icon="o-arrow-up-tray" wire:click="openImport" />
                <x-button :label="'Buat'" icon="o-plus" class="btn-primary" wire:click="openCreate" />
            </x-slot:actions>
        </x-header>

        {{-- FILTERS --}}
        <x-card shadow class="mt-6 bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 border border-gray-300 dark:border-white/10">
            <div class="grid grid-cols-4 gap-4">
                <x-input placeholder="Cari Nomor Suku..." wire:model.live="filterPartNumber" icon="o-magnifying-glass" />
                <x-input placeholder="Cari Kode Pelanggan..." wire:model.live="filterCustomer" icon="o-magnifying-glass" />
                <x-input placeholder="Cari Model..." wire:model.live="filterModel" icon="o-magnifying-glass" />
                <x-input placeholder="Cari Varian..." wire:model.live="filterVariant" icon="o-magnifying-glass" />
            </div>
            <div class="mt-4 flex gap-2">
                <x-button :label="'Reset Filter'" icon="o-x-mark" class="btn-sm btn-outline" wire:click="$set('filterPartNumber', ''); $set('filterCustomer', ''); $set('filterModel', ''); $set('filterVariant', '');" />
            </div>
        </x-card>

        {{-- PARTS TABLE --}}
        <x-card shadow class="mt-6 bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 border border-gray-300 dark:border-white/10">
            <x-table :headers="$headers" :rows="$parts" with-pagination per-page="perPage" :per-page-values="[5,10,20]">
                @scope('cell_is_active', $part)
                    <span class="badge {{ $part->is_active ? 'badge-success' : 'badge-error' }} badge-sm">
                        {{ $part->is_active ? 'Ya' : 'Tidak' }}
                    </span>
                @endscope

                @scope('actions', $part)
                    <div class="flex gap-1">
                        <x-button icon="o-pencil" wire:click="openEdit({{ $part->id }})" :tooltip="'Edit'" />
                        {{-- Replaced JavaScript confirm with Livewire's built-in confirmation helper via Mary UI --}}
                        <x-button icon="o-trash" wire:click="deletePart({{ $part->id }})" :tooltip="'Hapus'" @click="$wire.deletePart({{ $part->id }}).then(() => { toast.success('Suku dihapus'); })" />
                    </div>
                @endscope
            </x-table>
        </x-card>

        {{-- CREATE / EDIT PART MODAL --}}
        <x-modal wire:model="showPartModal" :title="$partId ? 'Edit Suku' : 'Buat Suku'" separator>
            <div class="grid grid-cols-2 gap-4">
                <x-input :label="'Nomor Suku'" wire:model.defer="part_number" />
                <x-input :label="'Nama Suku'" wire:model.defer="part_name" />
                <x-input :label="'Kode Pelanggan'" wire:model.defer="customer_code" />
                <x-input :label="'Kode Pemasok'" wire:model.defer="supplier_code" />
                <x-input :label="'Model'" wire:model.defer="model" />
                <x-input :label="'Varian'" wire:model.defer="variant" />
                <x-input :label="'Pengemasan Standar'" wire:model.defer="standard_packing" />
                <x-input :label="'Stok'" wire:model.defer="stock" type="number" />
            </div>

            <div class="mt-4">
                <x-input :label="'Alamat'" wire:model.defer="address" />
            </div>

            <div class="mt-4">
                <x-checkbox :label="'Aktif'" wire:model.defer="is_active" />
            </div>

            <x-slot:actions>
                <x-button :label="'Batal'" wire:click="$set('showPartModal', false)" />
                <x-button :label="'Simpan'" icon="o-check" class="btn-primary" wire:click="savePart" spinner="savePart" />
            </x-slot:actions>
        </x-modal>

        {{-- IMPORT PARTS MODAL --}}
        <x-modal wire:model="showImportModal" :title="'Impor Bagian'" separator>
            <div class="space-y-4">
                <div class="alert alert-info">
                    <span>Unggah file Excel untuk mengimpor suku. Proses impor akan berjalan di latar belakang (queued job). Suku cadang yang sudah ada akan diperbarui.</span>
                </div>

                <x-file :label="'File Excel'" wire:model="importFile" accept=".xlsx,.xls" />
                {{-- Display Livewire validation errors for the file upload --}}
                @error('importFile')
                    <div class="text-error text-sm">{{ $message }}</div>
                @enderror

                <div class="flex gap-2">
                    <x-button :label="'Unduh Template'" icon="o-arrow-down-tray" class="btn-outline" wire:click="downloadTemplate" />
                </div>

                {{-- Notifikasi impor di latar belakang tidak lagi menampilkan hitungan di sini secara real-time --}}
            </div>

            <x-slot:actions>
                <x-button :label="'Batal'" wire:click="$set('showImportModal', false)" />
                <x-button :label="'Impor'" icon="o-check" class="btn-primary" wire:click="importParts" spinner="importParts" />
            </x-slot:actions>
        </x-modal>
    </div>