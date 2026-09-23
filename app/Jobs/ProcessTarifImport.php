<?php

namespace App\Jobs;

use App\Http\Services\TarifImportService;
use App\Imports\TarifChunkImport;
use App\Models\ImportBatch;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceClass;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Memproses satu file import tarif di background.
 *
 * - HTTP selesai segera setelah dispatch; browser tidak menunggu Excel.
 * - Chunking/streaming/bulk/idempotency tetap milik TarifImportService —
 *   job ini hanya orkestrasi status batch + progress per chunk.
 * - Retry aman: master memakai insertOrIgnore + tarif memakai business
 *   key, sehingga data yang sudah masuk dilewati, bukan digandakan.
 * - File TIDAK dihapus di sini agar retry bisa memakai file yang sama;
 *   file dihapus saat batch di-delete.
 */
class ProcessTarifImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry manual via UI (idempoten), bukan otomatis oleh worker. */
    public int $tries = 1;

    /** File besar 120K-500K: chunk 2000 ~20s/chunk → 126K ~21 min, 500K ~83 min. Beri 90 menit. */
    public int $timeout = 5400;

    public function __construct(protected int $batchId) {}

    public function handle(TarifImportService $service): void
    {
        $batch = ImportBatch::find($this->batchId);
        if (! $batch) {
            return;
        }
        if (! Storage::exists($batch->path)) {
            $batch->update([
                'status' => ImportBatch::STATUS_FAILED,
                'error_message' => 'File import sudah tidak tersedia. Silakan upload ulang.',
            ]);

            return;
        }

        $batch->update(['status' => ImportBatch::STATUS_PROCESSING, 'error_message' => null]);

        $providersBefore = Provider::count();
        $servicesBefore = Service::count();
        $classesBefore = ServiceClass::count();

        try {
            $path = Storage::path($batch->path);
            $batch->update(['total_rows' => max(0, $service->countSheetRows($path) - 1)]);

            $import = new TarifChunkImport($service, (int) $batch->jenis_tarif_id);
            $import->onChunk = function (TarifChunkImport $chunk) use ($batch) {
                $batch->update([
                    'processed_rows' => $chunk->processedRows,
                    'inserted' => $chunk->inserted,
                    'skipped_duplicate' => $chunk->skippedDuplicate,
                    'skipped_error' => $chunk->skippedError,
                ]);
            };

            Excel::import($import, $path);

            $batch->update([
                'status' => ImportBatch::STATUS_COMPLETED,
                'processed_rows' => $import->processedRows,
                'inserted' => $import->inserted,
                'skipped_duplicate' => $import->skippedDuplicate,
                'skipped_error' => $import->skippedError,
                'providers_created' => max(0, Provider::count() - $providersBefore),
                'services_created' => max(0, Service::count() - $servicesBefore),
                'classes_created' => max(0, ServiceClass::count() - $classesBefore),
            ]);
        } catch (\Throwable $e) {
            Log::error('Queue import tarif gagal', [
                'batch_id' => $batch->id,
                'filename' => $batch->filename,
                'error' => $e->getMessage(),
            ]);
            $batch->update([
                'status' => ImportBatch::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ]);
        }
    }

    /**
     * Dipanggil worker ketika job gagal di luar try/catch handle()
     * (timeout worker, tries habis). Menjamin batch tidak tertahan
     * di processing_import selamanya — progress terakhir tersimpan.
     */
    public function failed(\Throwable $exception): void
    {
        $batch = ImportBatch::find($this->batchId);
        if (! $batch) {
            return;
        }
        if (! in_array($batch->status, [ImportBatch::STATUS_PENDING_IMPORT, ImportBatch::STATUS_PROCESSING_IMPORT], true)) {
            return;
        }

        Log::error('Queue import tarif gagal (failed hook)', [
            'batch_id' => $batch->id,
            'filename' => $batch->filename,
            'error' => $exception->getMessage(),
        ]);
        $batch->update([
            'status' => ImportBatch::STATUS_FAILED,
            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
        ]);
    }
}
