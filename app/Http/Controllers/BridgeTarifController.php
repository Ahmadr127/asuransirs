<?php

namespace App\Http\Controllers;

use App\Services\Bridge\BridgeServiceSearch;
use App\Services\Bridge\BridgeTarifService;
use App\Services\Bridge\NotFound\NotFoundResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bridge Tarif: upload Excel lama -> mapping ke master Tarif ->
 * generate Excel baru. Thin controller — seluruh business logic di
 * BridgeTarifService. Tanpa pilihan Jenis Tarif, tanpa queue.
 */
class BridgeTarifController extends Controller
{
    public function __construct(
        protected BridgeTarifService $service,
        protected NotFoundResolver $notFound,
    ) {}

    public function index()
    {
        return view('bridge.index');
    }

    public function scan(Request $request)
    {
        $validated = $request->validate(
            ['file' => 'required|file|mimes:xlsx,xls,csv,html,htm|max:20480'],
            [
                'file.required' => 'File Excel wajib diupload.',
                'file.mimes' => 'Format file harus .xlsx, .xls, atau .csv (termasuk .xls hasil export lama).',
                'file.max' => 'Ukuran file maksimal 20 MB.',
            ]
        );

        try {
            $result = $this->service->scanUpload($validated['file']);
        } catch (\Throwable $e) {
            Log::warning('Bridge scan gagal: '.$e->getMessage(), ['file' => $validated['file']->getClientOriginalName()]);

            return back()->with('error', 'Scan gagal: '.$e->getMessage())->withInput();
        }

        return redirect()->route('bridge.result', $result['token']);
    }

    public function resolveMapping(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string|max:64',
            'mapping_key' => 'required|string',
            'candidate' => 'required|string|max:101',
        ]);

        $parts = explode('|', $validated['candidate']);
        // Bagian kelas boleh kosong (grup NOT_FOUND: kelas ikut bawaan Excel).
        if (count($parts) !== 2 || trim($parts[0]) === '') {
            return back()->with('error', 'Format kandidat tidak valid.');
        }

        try {
            $this->service->resolve(
                $validated['token'],
                $validated['mapping_key'],
                trim($parts[0]),
                trim($parts[1] ?? '')
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bridge.result', $validated['token'])
            ->with('success', 'Mapping diterapkan ke seluruh row dengan key sama.');
    }

    /**
     * Terapkan banyak mapping sekaligus dari modal (satu tombol).
     * Redirect (PRG) agar refresh halaman hasil aman (tidak 405).
     */
    public function resolveBatch(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string|max:64',
            'rows' => 'nullable|array|max:500',
            'rows.*.key' => 'required_with:rows|string',
            'rows.*.candidate' => 'nullable|string|max:101',
        ]);

        $candidates = [];
        foreach ($validated['rows'] ?? [] as $row) {
            $candidate = trim((string) ($row['candidate'] ?? ''));
            if ($candidate === '' || $candidate === '|') {
                continue;
            }
            $candidates[$row['key']] = $candidate;
        }
        if ($candidates === []) {
            return back()->with('error', 'Belum ada mapping yang dipilih.');
        }

        try {
            $applied = $this->service->resolveMany($validated['token'], $candidates);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bridge.result', $validated['token'])
            ->with('success', "Diterapkan {$applied} mapping ke seluruh row dengan key sama.");
    }

    /**
     * Halaman hasil GET (tujuan redirect pola PRG) — aman di-refresh.
     */
    public function showResult(string $token)
    {
        try {
            $result = $this->service->resultFor($token);
        } catch (\Throwable $e) {
            return redirect()->route('bridge.index')->with('error', $e->getMessage());
        }

        return view('bridge.result', compact('result'));
    }

    public function generate(Request $request)
    {
        $token = (string) $request->validate(['token' => 'required|string|max:64'])['token'];

        try {
            $generated = $this->service->generate($token);
        } catch (\Throwable $e) {
            return back()->with('error', 'Generate gagal: '.$e->getMessage());
        }

        if ($generated['unresolved'] > 0) {
            return redirect()->route('bridge.download', $generated['download_token'])
                ->with('info', 'Masih terdapat '.$generated['unresolved'].' row yang belum memiliki mapping manual — row AMBIGUOUS/NOT_FOUND yang ada saran (konsensus kode) sudah memakai kode saran, sisanya tetap memakai SERVICECODE dari Excel original (kolom kelas mengikuti master bila ditemukan).');
        }

        return redirect()->route('bridge.download', $generated['download_token'])
            ->with('success', 'Excel hasil Bridge siap diunduh ('.$generated['changed'].' dari '.$generated['total'].' row diubah).');
    }

    public function download(string $token): BinaryFileResponse|\Illuminate\Http\RedirectResponse
    {
        $path = $this->service->outputPath($token);
        if ($path === null) {
            return redirect()->route('bridge.index')
                ->with('error', 'File hasil sudah tidak tersedia. Ulangi generate.');
        }

        return response()->download($path, $this->service->outputFilename($token));
    }

    /**
     * Cari master service untuk pemetaan manual NOT_FOUND (JSON).
     * Bila description diisi: daftar terurut paling mirip dengan
     * description baris Excel; ketikan user (q) ikut menyaring.
     * Bila konteks kelas/tarif ikut dikirim (opsional), urutan
     * disusun ulang oleh NotFoundResolver memakai skor kelas + tarif
     * dari tabel tarifs — tanpa konteks, perilaku lama dipertahankan
     * persis (dropdown existing tidak mengirim param ini).
     * Tanpa description: mirip dengan ketikan saja.
     */
    public function searchServices(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:255',
            'class' => 'nullable|string|max:100',
            'tariff' => 'nullable|numeric|min:0',
        ]);
        $q = trim((string) ($validated['q'] ?? ''));
        $description = trim((string) ($validated['description'] ?? ''));
        $class = trim((string) ($validated['class'] ?? ''));
        $tariff = isset($validated['tariff']) && is_numeric($validated['tariff'])
            ? (float) $validated['tariff']
            : null;

        if ($description !== '') {
            if ($class !== '' || $tariff !== null) {
                return response()->json(['data' => $this->notFound->suggest(
                    $description,
                    $q !== '' ? $q : null,
                    $class !== '' ? $class : null,
                    $tariff,
                )]);
            }

            return response()->json(['data' => BridgeServiceSearch::similar($description, $q !== '' ? $q : null)]);
        }
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => BridgeServiceSearch::search($q)]);
    }
}
