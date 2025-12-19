<?php

use Livewire\Volt\Component;
use App\Models\Movement;
use App\Models\Singlepart;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Maatwebsite\Excel\Facades\Excel;

new
#[Layout('components.layouts.app')]
#[Title('Stock Movements')]
class extends Component {
    public int $perPage = 20;

    // filters
    public string $filterPartNumber = '';
    public string $filterType = '';
    public string $filterPic = '';
    public string $filterStartDate = '';
    public string $filterEndDate = '';

    public array $headers = [];

    public function mount(): void
    {
        // Set default date filters to current month if not set
        if (empty($this->filterStartDate)) {
            $this->filterStartDate = now()->startOfMonth()->format('Y-m-d');
        }
        if (empty($this->filterEndDate)) {
            $this->filterEndDate = now()->endOfMonth()->format('Y-m-d');
        }

        $this->headers = [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
            ['key' => 'part_number', 'label' => 'Part Number'],
            ['key' => 'model', 'label' => 'Model'],
            ['key' => 'variant', 'label' => 'Variant'],
            ['key' => 'type', 'label' => 'Type'],
            ['key' => 'acted_by', 'label' => 'Acted By'],
            ['key' => 'qty', 'label' => 'Qty'],
            ['key' => 'final_qty', 'label' => 'Final Qty'],
            ['key' => 'created_at', 'label' => 'Date'],
        ];
    }

    #[Computed]
    public function getMovementsProperty()
    {
        return Movement::query()
            ->with(['part:id,part_number,model,variant', 'user:id,name'])
            ->when($this->filterPartNumber, fn($q) => $q->whereHas('part', fn($pq) => $pq->where('part_number', 'like', '%' . $this->filterPartNumber . '%')))
            ->when($this->filterType, fn($q) => $q->where('type', $this->filterType))
            ->when($this->filterPic, fn($q) => $q->whereHas('user', fn($uq) => $uq->where('name', 'like', '%' . $this->filterPic . '%')))
            ->when($this->filterStartDate, fn($q) => $q->whereDate('created_at', '>=', $this->filterStartDate))
            ->when($this->filterEndDate, fn($q) => $q->whereDate('created_at', '<=', $this->filterEndDate))
            ->latest()
            ->paginate($this->perPage);
    }

    public function downloadReport()
    {
        $fileName = 'stock-movement-report-' . now()->format('Y-m-d-His') . '.xlsx';
        
        $filterPartNumber = $this->filterPartNumber;
        $filterType = $this->filterType;
        $filterPic = $this->filterPic;
        $filterStartDate = $this->filterStartDate;
        $filterEndDate = $this->filterEndDate;
        
        return Excel::download(new class($filterPartNumber, $filterType, $filterPic, $filterStartDate, $filterEndDate) implements \Maatwebsite\Excel\Concerns\WithMultipleSheets {
            public function __construct(
                private $filterPartNumber,
                private $filterType,
                private $filterPic,
                private $filterStartDate,
                private $filterEndDate
            ) {}

            public function sheets(): array
            {
                return [
                    new class($this->filterPartNumber, $this->filterType, $this->filterPic, $this->filterStartDate, $this->filterEndDate) implements 
                        \Maatwebsite\Excel\Concerns\FromCollection,
                        \Maatwebsite\Excel\Concerns\WithHeadings,
                        \Maatwebsite\Excel\Concerns\WithTitle,
                        \Maatwebsite\Excel\Concerns\WithStyles
                    {
                        public function __construct(
                            private $filterPartNumber,
                            private $filterType,
                            private $filterPic,
                            private $filterStartDate,
                            private $filterEndDate
                        ) {}

                        public function collection()
                        {
                            return Singlepart::query()
                                ->select('part_number', 'part_name', 'customer_code', 'supplier_code', 'model', 'variant', 'stock')
                                ->orderBy('part_number')
                                ->get()
                                ->map(fn($part) => [
                                    'part_number' => $part->part_number,
                                    'part_name' => $part->part_name,
                                    'customer_code' => $part->customer_code,
                                    'supplier_code' => $part->supplier_code,
                                    'model' => $part->model,
                                    'variant' => $part->variant,
                                    'stock' => $part->stock,
                                ]);
                        }

                        public function headings(): array
                        {
                            return ['Part Number', 'Part Name', 'Customer Code', 'Supplier Code', 'Model', 'Variant', 'Stock'];
                        }

                        public function title(): string
                        {
                            return 'Stock';
                        }

                        public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
                        {
                            // Auto-fit all columns
                            foreach (range('A', 'G') as $col) {
                                $sheet->getColumnDimension($col)->setAutoSize(true);
                            }

                            // Header styling
                            $sheet->getStyle('A1:G1')->applyFromArray([
                                'font' => [
                                    'bold' => true,
                                    'color' => ['rgb' => 'FFFFFF'],
                                    'size' => 11,
                                ],
                                'fill' => [
                                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                                    'startColor' => ['rgb' => '4F46E5'],
                                ],
                                'alignment' => [
                                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                                ],
                                'borders' => [
                                    'allBorders' => [
                                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                        'color' => ['rgb' => 'CCCCCC'],
                                    ],
                                ],
                            ]);

                            // Data rows styling
                            $lastRow = $sheet->getHighestRow();
                            if ($lastRow > 1) {
                                $sheet->getStyle('A2:G' . $lastRow)->applyFromArray([
                                    'borders' => [
                                        'allBorders' => [
                                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                            'color' => ['rgb' => 'EEEEEE'],
                                        ],
                                    ],
                                    'alignment' => [
                                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                                    ],
                                ]);

                                // Center align stock column
                                $sheet->getStyle('G2:G' . $lastRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                            }

                            return [];
                        }
                    },
                    new class($this->filterPartNumber, $this->filterType, $this->filterPic, $this->filterStartDate, $this->filterEndDate) implements 
                        \Maatwebsite\Excel\Concerns\FromCollection,
                        \Maatwebsite\Excel\Concerns\WithHeadings,
                        \Maatwebsite\Excel\Concerns\WithTitle,
                        \Maatwebsite\Excel\Concerns\WithStyles
                    {
                        public function __construct(
                            private $filterPartNumber,
                            private $filterType,
                            private $filterPic,
                            private $filterStartDate,
                            private $filterEndDate
                        ) {}

                        public function collection()
                        {
                            return Movement::query()
                                ->with(['part:id,part_number,model,variant', 'user:id,name'])
                                ->when($this->filterPartNumber, fn($q) => $q->whereHas('part', fn($pq) => $pq->where('part_number', 'like', '%' . $this->filterPartNumber . '%')))
                                ->when($this->filterType, fn($q) => $q->where('type', $this->filterType))
                                ->when($this->filterPic, fn($q) => $q->whereHas('user', fn($uq) => $uq->where('name', 'like', '%' . $this->filterPic . '%')))
                                ->when($this->filterStartDate, fn($q) => $q->whereDate('created_at', '>=', $this->filterStartDate))
                                ->when($this->filterEndDate, fn($q) => $q->whereDate('created_at', '<=', $this->filterEndDate))
                                ->latest()
                                ->get()
                                ->map(fn($movement) => [
                                    'part_number' => $movement->part->part_number ?? 'N/A',
                                    'model' => $movement->part->model ?? '-',
                                    'variant' => $movement->part->variant ?? '-',
                                    'type' => strtoupper($movement->type),
                                    'acted_by' => $movement->user->name ?? 'Unknown',
                                    'qty' => ($movement->type === 'in' ? '+' : '-') . $movement->qty,
                                    'final_qty' => $movement->final_qty,
                                    'created_at' => $movement->created_at->format('d/m/Y H:i'),
                                ]);
                        }

                        public function headings(): array
                        {
                            return ['Part Number', 'Model', 'Variant', 'Type', 'Acted By', 'Qty', 'Final Qty', 'Date'];
                        }

                        public function title(): string
                        {
                            return 'Movements';
                        }

                        public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
                        {
                            // Auto-fit all columns
                            foreach (range('A', 'H') as $col) {
                                $sheet->getColumnDimension($col)->setAutoSize(true);
                            }

                            // Header styling
                            $sheet->getStyle('A1:H1')->applyFromArray([
                                'font' => [
                                    'bold' => true,
                                    'color' => ['rgb' => 'FFFFFF'],
                                    'size' => 11,
                                ],
                                'fill' => [
                                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                                    'startColor' => ['rgb' => '059669'],
                                ],
                                'alignment' => [
                                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                                ],
                                'borders' => [
                                    'allBorders' => [
                                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                        'color' => ['rgb' => 'CCCCCC'],
                                    ],
                                ],
                            ]);

                            // Data rows styling
                            $lastRow = $sheet->getHighestRow();
                            if ($lastRow > 1) {
                                $sheet->getStyle('A2:H' . $lastRow)->applyFromArray([
                                    'borders' => [
                                        'allBorders' => [
                                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                            'color' => ['rgb' => 'EEEEEE'],
                                        ],
                                    ],
                                    'alignment' => [
                                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                                    ],
                                ]);

                                // Center align Type, Qty, Final Qty columns
                                $sheet->getStyle('D2:D' . $lastRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                                $sheet->getStyle('F2:G' . $lastRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                            }

                            return [];
                        }
                    },
                ];
            }
        }, $fileName);
    }

    public function with(): array
    {
        return [
            'movements' => $this->movements,
        ];
    }
};
?>

<div>
    {{-- HEADER --}}
    <x-header title="Stock Movements" subtitle="Track all stock in/out movements" separator progress-indicator>
        <x-slot:actions>
            <x-button label="Download Report" icon="o-arrow-down-tray" wire:click="downloadReport" class="btn-primary" spinner="downloadReport" />
        </x-slot:actions>
    </x-header>

    {{-- FILTERS --}}
    <x-card shadow class="mt-6 bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <x-input placeholder="Search Part Number..." wire:model.live="filterPartNumber" icon="o-magnifying-glass" />
            <x-select wire:model.live="filterType" placeholder="All Types" :options="[
                ['id' => 'in', 'name' => 'In'],
                ['id' => 'out', 'name' => 'Out']
            ]" />
            <x-input placeholder="Search PIC..." wire:model.live="filterPic" icon="o-magnifying-glass" />
            <x-input type="date" wire:model.live="filterStartDate" label="Start Date" />
            <x-input type="date" wire:model.live="filterEndDate" label="End Date" />
            <div class="flex items-end">
                <x-button label="Reset Filters" icon="o-x-mark" class="btn-outline w-full" wire:click="$set('filterPartNumber', ''); $set('filterType', ''); $set('filterPic', ''); $set('filterStartDate', ''); $set('filterEndDate', '');" />
            </div>
        </div>
    </x-card>

    {{-- MOVEMENTS TABLE --}}
    <x-card shadow class="mt-6 bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10">
        <x-table :headers="$headers" :rows="$movements" with-pagination per-page="perPage" :per-page-values="[10,20,50,100]">
            @scope('cell_part_number', $movement)
                <span class="font-mono text-sm">{{ $movement->part->part_number ?? 'N/A' }}</span>
            @endscope

            @scope('cell_model', $movement)
                <span class="text-sm">{{ $movement->part->model ?? '-' }}</span>
            @endscope

            @scope('cell_variant', $movement)
                <span class="text-sm">{{ $movement->part->variant ?? '-' }}</span>
            @endscope

            @scope('cell_type', $movement)
                <span class="badge {{ $movement->type === 'in' ? 'badge-success' : 'badge-error' }} badge-sm">
                    {{ strtoupper($movement->type) }}
                </span>
            @endscope

            @scope('cell_acted_by', $movement)
                <span class="text-sm">{{ $movement->user->name ?? 'Unknown' }}</span>
            @endscope

            @scope('cell_qty', $movement)
                <span class="font-semibold {{ $movement->type === 'in' ? 'text-emerald-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                    {{ $movement->type === 'in' ? '+' : '-' }}{{ $movement->qty }}
                </span>
            @endscope

            @scope('cell_final_qty', $movement)
                <span class="font-mono text-sm">{{ $movement->final_qty }}</span>
            @endscope

            @scope('cell_created_at', $movement)
                <span class="text-xs text-gray-600 dark:text-gray-400">{{ $movement->created_at->format('d/m/Y H:i') }}</span>
            @endscope
        </x-table>
    </x-card>
</div>
