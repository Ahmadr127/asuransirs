<?php

namespace App\Http\Controllers;

use App\Exports\TarifExport;
use App\Http\Requests\TarifImport\CommitRequest;
use App\Http\Requests\TarifImport\ScanRequest;
use App\Http\Services\TarifExportService;
use App\Http\Services\TarifImportService;
use App\Jobs\ProcessTarifImport;
use App\Jobs\ScanTarifImport;
use App\Models\ImportBatch;
use App\Models\JenisTarif;
use App\Services\TarifImport\TarifImportColumnMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Thin controller import/export tarif. Seluruh business logic ada di
 * TarifImportService / TarifExportService.
 */
class TarifImportController extends Controller
{
    public function __construct(
        protected TarifImportService $importService,
        protected TarifExportService $exportService,
    ) {}

    public function index()
    {
        $jenisTarifs = JenisTarif::where('status', 'active')->orderBy('name')->get();

        return view('tarifs.import', compact('jenisTarifs'));
    }

    /**
     * STEP scan: HANYA validasi request, simpan file, buat batch,
     * dispatch ScanTarifImport. TIDAK membaca Excel di HTTP —
     * response langsung kembali agar browser tidak menunggu.
     */
    public function scan(ScanRequest $request)
    {
        $jenisTarifId = (int) $request->validated()['jenis_tarif_id'];
        $file = $request->file('file');

        $storedPath = $file->store('tarif-imports');

        $batch = ImportBatch::create([
            'user_id' => auth()->id(),
            'filename' => $file->getClientOriginalName(),
            'path' => $storedPath,
            'jenis_tarif_id' => $jenisTarifId,
            'status' => ImportBatch::STATUS_PENDING_SCAN,
        ]);

        ScanTarifImport::dispatch($batch->id);

        return redirect()->route('tarif-import.batches.show', $batch);
    }

    /**
     * Halaman batch: progress scan (polling) atau preview hasil scan
     * + tombol Import. Import hanya dari status scan_completed.
     */
    public function show(ImportBatch $batch)
    {
        $batch->load('jenisTarif');

        if ($batch->status === ImportBatch::STATUS_SCAN_FAILED) {
            $jenisTarifs = JenisTarif::where('status', 'active')->orderBy('name')->get();
            $result = [
                'filename' => $batch->filename,
                'fatal' => $batch->scan_error_message ?? 'Scan gagal.',
                'batch_id' => $batch->id,
            ];

            return view('tarifs.import', compact('result', 'jenisTarifs', 'batch'));
        }

        if ($batch->status === ImportBatch::STATUS_SCAN_COMPLETED) {
            $jenisTarifs = JenisTarif::where('status', 'active')->orderBy('name')->get();
            $summary = array_merge([
                'total_rows' => 0, 'valid_rows' => 0, 'warning_rows' => 0,
                'error_rows' => 0, 'duplicate_rows' => 0,
                'duplicate_in_file' => 0, 'duplicate_in_db' => 0,
                'provider_found' => 0, 'provider_missing' => 0,
                'service_found' => 0, 'service_missing' => 0,
                'class_found' => 0, 'class_missing' => 0,
                'provider_will_create' => 0, 'service_will_create' => 0, 'class_will_create' => 0,
                'new_provider_total' => 0, 'new_service_total' => 0, 'new_class_total' => 0,
                'processed' => 0,
            ], $batch->scan_summary ?? []);
            $candidates = $batch->scan_candidates ?? [];
            $result = array_merge($summary, [
                'filename' => $batch->filename,
                'jenis_tarif_id' => $batch->jenis_tarif_id,
                'jenis_tarif_name' => $batch->jenisTarif ? $batch->jenisTarif->code.' — '.$batch->jenisTarif->name : null,
                'new_providers' => $candidates['providers'] ?? [],
                'new_services' => $candidates['services'] ?? [],
                'new_classes' => $candidates['classes'] ?? [],
                'preview' => $batch->scan_preview ?? [],
                'errors' => $batch->scan_errors ?? [],
                'truncated_preview' => ($summary['total_rows'] ?? 0) > TarifImportService::PREVIEW_LIMIT,
                'truncated_errors' => count($batch->scan_errors ?? []) >= TarifImportService::ERROR_LIMIT,
                'batch_id' => $batch->id,
            ]);

            return view('tarifs.import', compact('result', 'jenisTarifs', 'batch'));
        }

        if ($batch->isImportActive() || $batch->status === ImportBatch::STATUS_COMPLETED || $batch->status === ImportBatch::STATUS_FAILED) {
            return redirect()->route('tarif-import.batches')
                ->with('info', 'Batch ini sudah masuk tahap import. Pantau di Riwayat Import.');
        }

        return view('tarifs.import-batches.show', compact('batch'));
    }

    /**
     * Hasil scan tersimpan (JSON) untuk preview setelah scan_completed.
     */
    public function preview(ImportBatch $batch)
    {
        return response()->json([
            'id' => $batch->id,
            'filename' => $batch->filename,
            'status' => $batch->status,
            'scan_ready' => $batch->status === ImportBatch::STATUS_SCAN_COMPLETED,
            'summary' => $batch->scan_summary,
            'candidates' => $batch->scan_candidates,
            'preview' => $batch->scan_preview,
            'errors' => $batch->scan_errors,
        ]);
    }

    /**
     * STEP commit: TANPA membaca Excel di HTTP. Import hanya dari batch
     * berstatus scan_completed, memakai file path + jenis_tarif_id yang
     * tersimpan. Dispatch queue, HTTP langsung selesai.
     */
    public function commit(CommitRequest $request)
    {
        $batch = ImportBatch::findOrFail($request->validated()['batch_id']);

        if ($batch->status !== ImportBatch::STATUS_SCAN_COMPLETED) {
            return back()->with('error', 'Import hanya dapat dilakukan setelah scan selesai (status saat ini: '.ImportBatch::statusLabel($batch->status).').');
        }

        if (! Storage::exists($batch->path)) {
            return back()->with('error', 'File import sudah tidak tersedia. Silakan upload ulang.');
        }

        $batch->update([
            'status' => ImportBatch::STATUS_PENDING_IMPORT,
            'error_message' => null,
        ]);
        ProcessTarifImport::dispatch($batch->id);

        return redirect()->route('tarif-import.batches');
    }

    /**
     * Riwayat import (status queue). Polling via batchStatus().
     */
    public function batches()
    {
        $batches = ImportBatch::with('jenisTarif')->orderByDesc('id')->paginate(15);

        return view('tarifs.import-batches.index', compact('batches'));
    }

    /**
     * JSON status untuk polling (1–2 detik) selama fase aktif scan/import.
     */
    public function batchStatus(Request $request)
    {
        $batches = ImportBatch::with('jenisTarif')->orderByDesc('id')->limit(30)->get();

        return response()->json([
            'batches' => $batches->map(fn (ImportBatch $b) => [
                'id' => $b->id,
                'filename' => $b->filename,
                'jenis' => $b->jenisTarif->name ?? '-',
                'status' => $b->status,
                'status_label' => ImportBatch::statusLabel($b->status),
                'scan_total_rows' => (int) $b->scan_total_rows,
                'scan_processed_rows' => (int) $b->scan_processed_rows,
                'scan_percent' => $b->scanPercent(),
                'scan_ready' => $b->status === ImportBatch::STATUS_SCAN_COMPLETED,
                'total_rows' => (int) $b->total_rows,
                'processed_rows' => (int) $b->processed_rows,
                'percent' => $b->progressPercent(),
                'inserted' => (int) $b->inserted,
                'skipped_duplicate' => (int) $b->skipped_duplicate,
                'skipped_error' => (int) $b->skipped_error,
                'providers_created' => (int) $b->providers_created,
                'services_created' => (int) $b->services_created,
                'classes_created' => (int) $b->classes_created,
                'error_message' => $b->error_message,
                'scan_error_message' => $b->scan_error_message,
                'scan_summary' => $b->scan_summary,
                'is_terminal' => $b->isTerminal(),
                'is_scan_active' => $b->isScanActive(),
                'is_import_active' => $b->isImportActive(),
                'updated_at' => $b->updated_at?->toDateTimeString(),
            ])->values(),
        ]);
    }

    /**
     * Retry scan gagal: pastikan file ada, reset progress + error lama,
     * dispatch ScanTarifImport kembali. Selalu via queue.
     */
    public function retryScan(ImportBatch $batch)
    {
        if ($batch->isScanActive() || $batch->isImportActive()) {
            return back()->with('error', 'Batch masih dalam antrean/proses. Tunggu hingga selesai atau gagal.');
        }

        if (in_array($batch->status, [ImportBatch::STATUS_COMPLETED, ImportBatch::STATUS_FAILED], true)) {
            return back()->with('error', 'Batch ini sudah masuk tahap import. Gunakan Retry import bila gagal.');
        }

        if (! Storage::exists($batch->path)) {
            return back()->with('error', 'File import sudah tidak tersedia. Silakan upload ulang.');
        }

        $batch->update([
            'status' => ImportBatch::STATUS_PENDING_SCAN,
            'scan_total_rows' => 0,
            'scan_processed_rows' => 0,
            'scan_summary' => null,
            'scan_candidates' => null,
            'scan_preview' => null,
            'scan_errors' => null,
            'scan_error_message' => null,
        ]);
        ScanTarifImport::dispatch($batch->id);

        return back()->with('info', 'Scan dijadwalkan ulang. File akan dipindai kembali melalui Queue.');
    }

    /**
     * Retry import gagal: pakai file + jenis tarif yang sama, selalu
     * melalui queue. Idempoten via business key existing.
     */
    public function retryBatch(ImportBatch $batch)
    {
        if ($batch->status !== ImportBatch::STATUS_FAILED) {
            return back()->with('error', 'Retry import hanya untuk status FAILED.');
        }

        if (! Storage::exists($batch->path)) {
            return back()->with('error', 'File import sudah tidak tersedia. Silakan upload ulang.');
        }

        $batch->update([
            'status' => ImportBatch::STATUS_PENDING_IMPORT,
            'processed_rows' => 0,
            'inserted' => 0,
            'skipped_duplicate' => 0,
            'skipped_error' => 0,
            'providers_created' => 0,
            'services_created' => 0,
            'classes_created' => 0,
            'error_message' => null,
        ]);
        ProcessTarifImport::dispatch($batch->id);

        return back()->with('success', 'Import dijadwalkan ulang. File akan diproses kembali melalui Queue.');
    }

    /**
     * Hapus batch + file terkait. Diblokir selama processing_scan /
     * processing_import karena tidak ada cancel worker yang aman.
     */
    public function destroyBatch(ImportBatch $batch)
    {
        if ($batch->status === ImportBatch::STATUS_PROCESSING_SCAN || $batch->status === ImportBatch::STATUS_PROCESSING_IMPORT) {
            return back()->with('error', 'Batch sedang diproses dan tidak dapat dihapus. Tunggu hingga selesai atau gagal.');
        }

        if (Storage::exists($batch->path)) {
            Storage::delete($batch->path);
        }
        $batch->delete();

        return redirect()->route('tarif-import.batches')
            ->with('success', 'Import berhasil dihapus.');
    }

    /**
     * Template Excel kosong dengan 11 header canonical.
     */
    public function template(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([TarifImportColumnMapper::CANONICAL_HEADERS], null, 'A1');
        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $path = storage_path('app/tarif-import-template.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, 'template-import-tarif.xlsx')->deleteFileAfterSend(false);
    }

    /**
     * Export XLSX mengikuti filter aktif (CSV tetap di TarifController@export).
     */
    public function exportXlsx(Request $request)
    {
        $filters = $request->only([
            'search', 'jenis_tarif_id', 'provider_id', 'service_id', 'class_id',
            'surgery_type', 'status', 'date_from', 'date_to',
        ]);

        return Excel::download(
            new TarifExport($filters),
            $this->exportService->filename($filters, 'xlsx')
        );
    }
}
