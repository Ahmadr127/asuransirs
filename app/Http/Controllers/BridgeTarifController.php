<?php

namespace App\Http\Controllers;

use App\Services\Bridge\BridgeTarifService;
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
    public function __construct(protected BridgeTarifService $service) {}

    public function index()
    {
        return view('bridge.index');
    }

    public function scan(Request $request)
    {
        $validated = $request->validate(
            ['file' => 'required|file|mimes:xlsx,xls,csv|max:20480'],
            [
                'file.required' => 'File Excel wajib diupload.',
                'file.mimes' => 'Format file harus .xlsx, .xls, atau .csv.',
                'file.max' => 'Ukuran file maksimal 20 MB.',
            ]
        );

        try {
            $result = $this->service->scanUpload($validated['file']);
        } catch (\Throwable $e) {
            Log::warning('Bridge scan gagal: '.$e->getMessage(), ['file' => $validated['file']->getClientOriginalName()]);
            return back()->with('error', 'Scan gagal: '.$e->getMessage())->withInput();
        }

        return view('bridge.index', compact('result'));
    }

    public function resolveMapping(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string|max:64',
            'mapping_key' => 'required|string',
            'candidate' => 'required|string|max:101',
        ]);

        $parts = explode('|', $validated['candidate']);
        if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            return back()->with('error', 'Format kandidat tidak valid.');
        }

        try {
            $result = $this->service->resolve(
                $validated['token'],
                $validated['mapping_key'],
                trim($parts[0]),
                trim($parts[1])
            );
            $result['token'] = $validated['token'];
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return view('bridge.index', compact('result'))->with('success', 'Mapping diterapkan ke seluruh row dengan key sama.');
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
                ->with('info', 'Masih terdapat '.$generated['unresolved'].' row yang belum memiliki mapping — row tersebut tetap memakai kode dari Excel original.');
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
}
