<?php

namespace App\Jobs;

use App\Models\Singlepart;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelType;

class ImportPartsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $filePath;
    protected int $userId;

    /**
     * Buat instance pekerjaan baru.
     *
     * @param string $filePath Jalur file relatif di storage (contoh: 'public/imports/file.xlsx').
     * @param int $userId ID pengguna yang memulai impor, untuk notifikasi/logging.
     */
    public function __construct(string $filePath, int $userId)
    {
        $this->filePath = $filePath;
        $this->userId = $userId;
    }

    /**
     * Jalankan pekerjaan.
     */
    public function handle(): void
    {
        // Untuk impor besar, alokasi memori yang tinggi di sini akan menghindari kesalahan
        // tanpa memengaruhi alokasi memori aplikasi web.
        $originalMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '1024M'); // 1GB limit for the job worker

        $fullPath = Storage::path($this->filePath);
        $importedCount = 0;
        $skippedCount = 0;

        try {
            if (!file_exists($fullPath)) {
                throw new \Exception("File impor tidak ditemukan pada: {$fullPath}");
            }

            $collections = Excel::toCollection(null, $fullPath, null, ExcelType::XLSX);

            if ($collections->isNotEmpty() && $collections->first()->isNotEmpty()) {
                $sheet = $collections->first();
                $headerRow = $sheet->shift(); 

                $normalizedHeader = $headerRow->map(function ($key) {
                    return strtolower(str_replace(' ', '_', trim($key)));
                })->toArray();

                foreach ($sheet as $rowData) {
                    $rowDataArray = $rowData->toArray();
                    
                    if (empty($rowDataArray[0])) {
                        continue; // Skip empty rows
                    }

                    $row = array_combine($normalizedHeader, array_pad($rowDataArray, count($normalizedHeader), null));
                    $partNumberKey = 'part_number';
                    
                    if (empty($row[$partNumberKey])) {
                        $skippedCount++;
                        continue; // Skip if part_number is empty
                    }

                    // Prepare data
                    $partData = [
                        'part_number'      => $row['part_number'],
                        'part_name'        => $row['part_name'] ?? '',
                        'customer_code'    => $row['customer_code'] ?? '',
                        'supplier_code'    => $row['supplier_code'] ?? null,
                        'model'            => $row['model'] ?? null,
                        'variant'          => $row['variant'] ?? null,
                        'standard_packing' => $row['standard_packing'] ?? '',
                        'stock'            => !empty($row['stock']) ? (int) $row['stock'] : 0,
                        'address'          => $row['address'] ?? null,
                        'is_active'        => match (strtolower((string)($row['is_active'] ?? '1'))) {
                            '0', 'false', 'tidak' => false,
                            default => true,
                        }
                    ];

                    // Create or update part
                    Singlepart::updateOrCreate(
                        ['part_number' => $row['part_number']],
                        $partData
                    );

                    $importedCount++;
                }
            }
            
            Log::info("Impor suku cadang berhasil diselesaikan oleh pengguna {$this->userId}. Diimpor: {$importedCount}, Dilewati: {$skippedCount}.");
            // Di sini Anda dapat memicu event atau notifikasi Livewire untuk memberi tahu pengguna secara real-time.
            
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $message = "Kesalahan Validasi Excel. Periksa log server untuk detail.";
            Log::error("Parts import validation failed for user {$this->userId}. " . $e->getMessage());
            // Tambahkan notifikasi kegagalan ke pengguna jika sistem notifikasi sudah diatur.

        } catch (\Throwable $e) {
            $message = "Impor gagal karena kesalahan server. Periksa log server.";
            Log::error("Parts import failed for user {$this->userId}: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            // Tambahkan notifikasi kegagalan ke pengguna jika sistem notifikasi sudah diatur.

        } finally {
            // Hapus file setelah diproses, berhasil atau gagal (untuk membersihkan storage)
            if (Storage::exists($this->filePath)) {
                Storage::delete($this->filePath);
            }
            // Pulihkan batas memori (walaupun biasanya tidak diperlukan di job worker)
            ini_set('memory_limit', $originalMemoryLimit);
        }
    }
}