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

    /** File besar: beri ruang hingga 30 menit. */
    public int $timeout = 1800;

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
            $cursor = 2;
            $lastRow = $total + 1;
            while ($cursor <= $lastRow) {
                $end = min($cursor + TarifImportService::SCAN_SLICE - 1, $lastRow);
                $rawRows = $service->readSlice($path, $cursor, $end);
                if ($rawRows === []) {
                    break;
                }
                $service->processSlice($rawRows, $header['map'], $cursor, $existingKeys, $state);
                $cursor += count($rawRows);
                $batch->update(['scan_processed_rows' => (int) $state['summary']['processed']]);
                unset($rawRows);
            }

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
}
