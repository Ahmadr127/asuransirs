<?php

namespace App\Jobs;

use App\Http\Services\TarifImportService;
use App\Models\ImportBatch;
use App\Models\JenisTarif;
use App\Services\TarifImport\TarifImportColumnMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Scan Excel tarif di background (chunk streaming, tanpa hard limit row).
 *
 * - TIDAK memakai Excel::toArray / load seluruh worksheet: hanya
 *   readSlice() per chunk + countSheetRows() (listWorksheetInfo).
 * - Validasi/normalisasi/resolusi/duplikat memakai TarifImportService
 *   yang sama dengan business rule existing (tidak diubah).
 * - Hasil disimpan bounded: ringkasan + kandidat unik + sampel preview
 *   (200) + sampel error (500), berapa pun jumlah row.
 * - Progress diupdate per chunk dari actual rows.
 */
class ScanTarifImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry manual via UI, bukan otomatis oleh worker. */
    public int $tries = 1;

    /** File besar 120K-500K: 10000 rows ~35s/slice → 126K ~7.5 min, 500K ~29 min. Beri 60 menit + margin retry_after. */
    public int $timeout = 3600;

    public function __construct(protected int $batchId) {}

    public function handle(TarifImportService $service): void
    {
        $batch = ImportBatch::find($this->batchId);
        if (! $batch) {
            return;
        }
        if (! Storage::exists($batch->path)) {
            $batch->update([
                'status' => ImportBatch::STATUS_SCAN_FAILED,
                'scan_error_message' => 'File import sudah tidak tersedia. Silakan upload ulang.',
            ]);

            return;
        }

        $batch->update([
            'status' => ImportBatch::STATUS_PROCESSING_SCAN,
            'scan_error_message' => null,
            'scan_processed_rows' => 0,
        ]);

        try {
            $path = Storage::path($batch->path);

            // Header saja (1 baris via ReadFilter) — bukan seluruh file.
            $headerRow = $service->readSlice($path, 1, 1)[0] ?? null;
            if ($headerRow === null) {
                throw new \RuntimeException('File Excel kosong.');
            }
            $header = TarifImportColumnMapper::validateHeaders($headerRow);
            if (! $header['valid']) {
                throw new \RuntimeException('Header tidak valid. Kolom wajib hilang: '.implode(', ', $header['missing']));
            }

            $total = max(0, $service->countSheetRows($path) - 1);
            $batch->update(['scan_total_rows' => $total]);

            $jenis = JenisTarif::find($batch->jenis_tarif_id);
            $state = $service->freshScanState(
                $batch->filename,
                $jenis,
                $headerRow,
                ['map' => $header['map'], 'missing' => [], 'unknown' => $header['unknown'], 'valid' => true]
            );
            $state['summary']['jenis_tarif_id'] = $batch->jenis_tarif_id;
            $existingKeys = $service->existingKeysFor((int) $batch->jenis_tarif_id);

            // Streaming per chunk mengikuti nomor baris Excel (kursor),
            // release tiap slice setelah diproses. Tanpa hard limit row.
            // Dimensi sheet sering mencakup ribuan baris kosong ber-format
            // (hasil drag-format); 3 slice kosong beruntun dianggap buntut
            // file sehingga scan berhenti dan total dikoreksi ke baris data.
            $cursor = 2;
            $lastRow = $total + 1;
            $emptyStreak = 0;
            $lastSaved = -1;
            $startedAt = microtime(true);
            $chunkIdx = 0;
            Log::info('Scan dimulai', ['batch_id' => $batch->id, 'total' => $total, 'slice' => TarifImportService::SCAN_SLICE]);
            while ($cursor <= $lastRow) {
                $chunkIdx++;
                $chunkStart = microtime(true);
                $end = min($cursor + TarifImportService::SCAN_SLICE - 1, $lastRow);
                $rawRows = $service->readSlice($path, $cursor, $end);
                if ($rawRows === []) {
                    break;
                }
                $cursor += count($rawRows);
                if (! collect($rawRows)->contains(fn ($r) => TarifImportService::isNonEmptyRow($r))) {
                    unset($rawRows);
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                    $emptyStreak++;
                    if ($emptyStreak >= 3) {
                        Log::info('Scan tail empty, berhenti', ['batch_id' => $batch->id, 'cursor' => $cursor]);
                        break;
                    }

                    continue;
                }
                $emptyStreak = 0;
                $service->processSlice($rawRows, $header['map'], $cursor - count($rawRows), $existingKeys, $state);
                unset($rawRows);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                $processed = (int) $state['summary']['processed'];
                if ($processed !== $lastSaved) {
                    $batch->update(['scan_processed_rows' => $processed]);
                    $lastSaved = $processed;
                }
                $elapsed = round(microtime(true) - $chunkStart, 2);
                $totalElapsed = round(microtime(true) - $startedAt, 1);
                $peak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
                $cur = round(memory_get_usage(true) / 1024 / 1024, 1);
                Log::info("Scan chunk {$chunkIdx}", [
                    'batch_id' => $batch->id,
                    'processed' => $processed,
                    'total' => $total,
                    'cursor' => $cursor,
                    'chunk_time' => $elapsed,
                    'total_time' => $totalElapsed,
                    'mem_cur' => $cur,
                    'mem_peak' => $peak,
                    'rows_sec' => $elapsed > 0 ? round(TarifImportService::SCAN_SLICE / $elapsed, 0) : 0,
                ]);
            }
            Log::info('Scan loop selesai', ['batch_id' => $batch->id, 'total_time' => round(microtime(true) - $startedAt, 1), 'peak' => round(memory_get_peak_usage(true)/1024/1024,1)]);

            $result = $service->finalizeScanState($state, 'scan-'.$batch->id);

            $batch->update([
                'status' => ImportBatch::STATUS_SCAN_COMPLETED,
                'scan_total_rows' => $result['total_rows'],
                'scan_processed_rows' => $result['total_rows'],
                'scan_summary' => collect($result)->except([
                    'preview', 'errors', 'new_providers', 'new_services', 'new_classes', 'token', 'filename',
                ])->all(),
                'scan_candidates' => [
                    'providers' => $result['new_providers'] ?? [],
                    'services' => $result['new_services'] ?? [],
                    'classes' => $result['new_classes'] ?? [],
                ],
                'scan_preview' => array_values($result['preview'] ?? []),
                'scan_errors' => array_values($result['errors'] ?? []),
            ]);
        } catch (\Throwable $e) {
            Log::error('Queue scan tarif gagal', [
                'batch_id' => $batch->id,
                'filename' => $batch->filename,
                'error' => $e->getMessage(),
            ]);
            $batch->update([
                'status' => ImportBatch::STATUS_SCAN_FAILED,
                'scan_error_message' => mb_substr($e->getMessage(), 0, 2000),
            ]);
        }
    }

    /**
     * Dipanggil worker ketika job gagal di luar try/catch handle()
     * (timeout worker, tries habis, error sebelum/sesudah blok utama).
     * Menjamin batch tidak tertahan di processing_scan selamanya —
     * progress terakhir tetap tersimpan.
     */
    public function failed(\Throwable $exception): void
    {
        $batch = ImportBatch::find($this->batchId);
        if (! $batch) {
            return;
        }
        if (! in_array($batch->status, [ImportBatch::STATUS_PENDING_SCAN, ImportBatch::STATUS_PROCESSING_SCAN], true)) {
            return;
        }

        Log::error('Queue scan tarif gagal (failed hook)', [
            'batch_id' => $batch->id,
            'filename' => $batch->filename,
            'error' => $exception->getMessage(),
        ]);
        $batch->update([
            'status' => ImportBatch::STATUS_SCAN_FAILED,
            'scan_error_message' => mb_substr($exception->getMessage(), 0, 2000),
        ]);
    }
}
