<?php

namespace App\Http\Controllers;

use App\Exports\TarifExport;
use App\Http\Requests\TarifImport\CommitRequest;
use App\Http\Requests\TarifImport\ScanRequest;
use App\Http\Services\TarifExportService;
use App\Http\Services\TarifImportService;
use App\Models\JenisTarif;
use App\Services\TarifImport\TarifImportColumnMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
     * STEP scan: simpan file, validasi header saja (<2 detik), lalu:
     * - file kecil (<= SCAN_SLICE): scan penuh langsung, render hasil.
     * - file besar: kembalikan halaman progres; browser memproses per slice
     *   via scanChunk() sehingga tidak ada satu pun request yang timeout.
     */
    public function scan(ScanRequest $request)
    {
        $jenisTarifId = (int) $request->validated()['jenis_tarif_id'];
        $file = $request->file('file');

        $storedPath = $file->store('tarif-imports');
        $filename = $file->getClientOriginalName();

        try {
            $init = $this->importService->initScan(Storage::path($storedPath), $jenisTarifId, $filename);
        } catch (\Throwable $e) {
            Storage::delete($storedPath);

            return back()->with('error', 'Gagal membaca file: '.$e->getMessage())->withInput();
        }

        if (isset($init['fatal'])) {
            Storage::delete($storedPath);
            $jenisTarifs = JenisTarif::where('status', 'active')->orderBy('name')->get();
            $result = $init;

            return view('tarifs.import', compact('result', 'jenisTarifs'));
        }

        $token = bin2hex(random_bytes(16));
        $state = $this->importService->freshScanState($filename, null, [], ['map' => $init['header_map'], 'missing' => [], 'unknown' => [], 'valid' => true]);
        $state['summary']['jenis_tarif_id'] = $init['jenis_tarif_id'];
        $state['summary']['jenis_tarif_name'] = $init['jenis_tarif_name'];
        $state['summary']['header'] = $init['header'];
        Cache::put($this->cacheKey($token), [
            'path' => $storedPath,
            'jenis_tarif_id' => $jenisTarifId,
            'filename' => $filename,
            'header_map' => $init['header_map'],
            'header' => $init['header'],
            'total' => $init['total_rows'],
            'state' => $state,
        ], now()->addMinutes(120));

        // File kecil: selesaikan sekaligus tanpa polling.
        if ($init['total_rows'] <= TarifImportService::SCAN_SLICE) {
            try {
                $result = $this->importService->scan(Storage::path($storedPath), $jenisTarifId);
            } catch (\Throwable $e) {
                Storage::delete($storedPath);
                Cache::forget($this->cacheKey($token));

                return back()->with('error', 'Gagal membaca file: '.$e->getMessage())->withInput();
            }
            $result['token'] = $token;
            $jenisTarifs = JenisTarif::where('status', 'active')->orderBy('name')->get();

            return view('tarifs.import', compact('result', 'jenisTarifs'));
        }

        $jenisTarifs = JenisTarif::where('status', 'active')->orderBy('name')->get();
        $pending = ['token' => $token, 'filename' => $filename, 'total' => $init['total_rows'], 'jenis_tarif_name' => $init['jenis_tarif_name']];

        return view('tarifs.import', compact('pending', 'jenisTarifs'));
    }

    /**
     * Satu slice scan bertahap (dipanggil berulang via AJAX oleh halaman
     * progres). Setiap request hanya memproses SCAN_SLICE baris.
     */
    public function scanChunk(Request $request)
    {
        @set_time_limit(120);

        $token = (string) $request->validate(['token' => 'required|string|max:64'])['token'];
        $job = Cache::get($this->cacheKey($token));

        if (! is_array($job) || ! Storage::exists($job['path'] ?? '')) {
            return response()->json(['message' => 'Sesi scan kedaluwarsa atau file tidak ditemukan. Ulangi scan.'], 419);
        }

        $state = $job['state'];
        $processed = (int) ($state['summary']['processed'] ?? 0);
        // Baris Excel 1-indexed: row 1 header, data mulai row 2.
        $startRow = $processed + 2;
        $endRow = min($startRow + TarifImportService::SCAN_SLICE - 1, $job['total'] + 1);

        $rawRows = $startRow > $endRow ? [] : $this->importService->readSlice(Storage::path($job['path']), $startRow, $endRow);

        if ($rawRows === []) {
            $state['empty_streak'] = ($state['empty_streak'] ?? 0) + 1;
        } else {
            $state['empty_streak'] = 0;
            $this->importService->processSlice(
                $rawRows,
                $job['header_map'],
                $processed + 2,
                $this->importService->existingKeysFor((int) $job['jenis_tarif_id']),
                $state
            );
        }

        $processed = (int) $state['summary']['processed'];
        $done = $processed >= (int) $job['total'] || ($state['empty_streak'] ?? 0) >= 3 || $startRow > $endRow;

        if ($done) {
            $state['summary']['total_rows'] = $processed;
        }

        $job['state'] = $state;
        Cache::put($this->cacheKey($token), $job, now()->addMinutes(120));

        return response()->json([
            'done' => $done,
            'processed' => $processed,
            'total' => $job['total'],
        ]);
    }

    /**
     * Halaman hasil scan bertahap (dibuka JS setelah polling selesai).
     * Bentuk $result SAMA dengan scan() sehingga blade tidak berubah.
     */
    public function result(Request $request)
    {
        $token = (string) $request->validate(['token' => 'required|string|max:64'])['token'];
        $job = Cache::get($this->cacheKey($token));

        if (! is_array($job) || ! isset($job['state'])) {
            return redirect()->route('tarif-import.index')
                ->with('error', 'Sesi scan kedaluwarsa. Ulangi scan.');
        }

        $result = $this->importService->finalizeScanState($job['state'], $token);
        $result['filename'] = $job['filename'];
        $jenisTarifs = JenisTarif::where('status', 'active')->orderBy('name')->get();

        return view('tarifs.import', compact('result', 'jenisTarifs'));
    }

    /**
     * STEP commit: baca ulang file tersimpan, insert batch valid via chunk.
     */
    public function commit(CommitRequest $request)
    {
        // Commit membaca ulang file + batch insert per 1000 row; beri ruang
        // waktu lebih. Scan bertahap sudah menjamin file valid sebelumnya.
        @set_time_limit(300);

        $payload = Cache::get($this->cacheKey($request->validated()['token']));

        if (! is_array($payload) || ! Storage::exists($payload['path'] ?? '')) {
            return redirect()->route('tarif-import.index')
                ->with('error', 'Sesi scan kedaluwarsa atau file tidak ditemukan. Ulangi scan.');
        }

        try {
            $stats = $this->importService->commit(
                Storage::path($payload['path']),
                (int) $payload['jenis_tarif_id']
            );
        } catch (\Throwable $e) {
            return redirect()->route('tarif-import.index')
                ->with('error', 'Import gagal: '.$e->getMessage());
        } finally {
            Storage::delete($payload['path']);
            Cache::forget($this->cacheKey($request->validated()['token']));
        }

        return redirect()->route('tarifs.index')
            ->with('success', sprintf(
                'Import selesai: %d masuk, %d duplikat dilewati, %d error dilewati (dari %d baris).',
                $stats['inserted'], $stats['skipped_duplicate'], $stats['skipped_error'], $stats['total_rows']
            ));
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

    protected function cacheKey(string $token): string
    {
        return 'tarif_import_'.$token;
    }
}
