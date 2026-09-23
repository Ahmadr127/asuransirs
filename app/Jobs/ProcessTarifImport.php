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
        // Snapshot awal untuk retry monotonik: jangan turun saat retry reprocess duplicate
        $initialProcessed = (int) $batch->processed_rows;
        $initialInserted = (int) $batch->inserted;
        $initialDup = (int) $batch->skipped_duplicate;
        $initialErr = (int) $batch->skipped_error;

        try {
            $importStart = microtime(true);
            Log::info('IMPORT START', ['batch_id'=>$batch->id, 'file'=>$batch->filename, 'ts'=>now()->toDateTimeString(), 'mem'=>round(memory_get_usage(true)/1024/1024,1)]);
            $path = Storage::path($batch->path);
            Log::info('IMPORT COUNT ROWS START', ['batch_id'=>$batch->id]);
            $cntT0 = microtime(true);
            $total = max(0, $service->countSheetRows($path) - 1);
            Log::info('IMPORT COUNT ROWS COMPLETE', ['batch_id'=>$batch->id, 'total'=>$total, 'elapsed'=>round(microtime(true)-$cntT0,2)]);
            $batch->update(['total_rows' => $total]);

            $import = new TarifChunkImport($service, (int) $batch->jenis_tarif_id);
            $import->onChunk = function (TarifChunkImport $chunk) use ($batch, $initialProcessed, $initialInserted, $initialDup, $initialErr) {
                Log::info('IMPORT PROGRESS UPDATE START', ['batch_id'=>$batch->id, 'chunk_processed'=>$chunk->processedRows, 'mem'=>round(memory_get_usage(true)/1024/1024,1)]);
                $upT0 = microtime(true);
                $batch->refresh();
                // processed: chunk is cumulative from 0 for file → max(batch, chunk) NEVER DECREASE
                // inserted/dup/err: chunk is delta new in this execution → total = initial + chunk, also max
                $batch->update([
                    'processed_rows' => max((int) $batch->processed_rows, (int) $chunk->processedRows),
                    'inserted' => max((int) $batch->inserted, $initialInserted + (int) $chunk->inserted),
                    'skipped_duplicate' => max((int) $batch->skipped_duplicate, $initialDup + (int) $chunk->skippedDuplicate),
                    'skipped_error' => max((int) $batch->skipped_error, $initialErr + (int) $chunk->skippedError),
                ]);
                Log::info('IMPORT PROGRESS UPDATE COMPLETE', ['batch_id'=>$batch->id, 'elapsed'=>round(microtime(true)-$upT0,2)]);
            };

            Log::info('EXCEL IMPORT START', ['batch_id'=>$batch->id, 'ts'=>now()->toDateTimeString(), 'mem_peak'=>round(memory_get_peak_usage(true)/1024/1024,1)]);
            $excelT0 = microtime(true);
            Excel::import($import, $path);
            Log::info('EXCEL IMPORT COMPLETE', ['batch_id'=>$batch->id, 'elapsed'=>round(microtime(true)-$excelT0,2), 'total_time'=>round(microtime(true)-$importStart,1), 'mem_peak'=>round(memory_get_peak_usage(true)/1024/1024,1)]);

            $batch->refresh();
            $batch->update([
                'status' => ImportBatch::STATUS_COMPLETED,
                'processed_rows' => max((int) $batch->processed_rows, (int) $import->processedRows, $initialProcessed + (int) $import->processedRows),
                'inserted' => max((int) $batch->inserted, $initialInserted + (int) $import->inserted),
                'skipped_duplicate' => max((int) $batch->skipped_duplicate, $initialDup + (int) $import->skippedDuplicate),
                'skipped_error' => max((int) $batch->skipped_error, $initialErr + (int) $import->skippedError),
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
