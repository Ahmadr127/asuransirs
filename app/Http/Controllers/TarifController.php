<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tarif\Store;
use App\Http\Requests\Tarif\Update;
use App\Http\Services\TarifService;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceClass;
use App\Models\JenisTarif;
use App\Models\Tarif;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TarifController extends Controller
{
    public function __construct(protected TarifService $tarifService) {}

    public function index(Request $request)
    {
        $tarifs = $this->tarifService->getTarifs($request->only([
            'search', 'jenis_tarif_id', 'provider_id', 'service_id', 'class_id',
            'surgery_type', 'status', 'date_from', 'date_to', 'sort', 'direction', 'per_page',
        ]));

        $jenisTarifs = JenisTarif::where('status', 'active')->orderBy('name')->get();
        $providers = Provider::where('status', 'active')->orderBy('name')->get();
        $services = Service::where('status', 'active')->orderBy('name')->get();
        $classes = ServiceClass::where('status', 'active')->orderBy('name')->get();

        return view('tarifs.index', compact('tarifs', 'jenisTarifs', 'providers', 'services', 'classes'));
    }

    public function create()
    {
        $jenisTarifs = JenisTarif::where('status', 'active')->orderBy('name')->get();
        $providers = Provider::where('status', 'active')->orderBy('name')->get();
        $services = Service::where('status', 'active')->orderBy('name')->get();
        $classes = ServiceClass::where('status', 'active')->orderBy('name')->get();

        return view('tarifs.create', compact('jenisTarifs', 'providers', 'services', 'classes'));
    }

    public function store(Store $request)
    {
        $this->tarifService->createTarif($request->validated());
        return redirect()->route('tarifs.index')->with('success', 'Tarif berhasil dibuat!');
    }

    public function show(Tarif $tarif)
    {
        $tarif->load(['jenisTarif', 'provider', 'service', 'serviceClass']);
        return view('tarifs.show', compact('tarif'));
    }

    public function edit(Tarif $tarif)
    {
        $jenisTarifs = JenisTarif::where('status', 'active')->orderBy('name')->get();
        $providers = Provider::where('status', 'active')->orderBy('name')->get();
        $services = Service::where('status', 'active')->orderBy('name')->get();
        $classes = ServiceClass::where('status', 'active')->orderBy('name')->get();

        return view('tarifs.edit', compact('tarif', 'jenisTarifs', 'providers', 'services', 'classes'));
    }

    public function update(Update $request, Tarif $tarif)
    {
        $this->tarifService->updateTarif($tarif, $request->validated());
        return redirect()->route('tarifs.index')->with('success', 'Tarif berhasil diperbarui!');
    }

    public function destroy(Tarif $tarif)
    {
        $this->tarifService->deleteTarif($tarif);
        return redirect()->route('tarifs.index')->with('success', 'Tarif berhasil dihapus!');
    }

    /**
     * Export CSV mengikuti filter yang sedang aktif
     * (semua data / filter aktif / per jenis tarif).
     */
    public function export(Request $request): StreamedResponse
    {
        $query = Tarif::with(['jenisTarif', 'provider', 'service', 'serviceClass']);
        $this->tarifService->applyFilters($query, $request->only([
            'search', 'jenis_tarif_id', 'provider_id', 'service_id', 'class_id',
            'surgery_type', 'status', 'date_from', 'date_to',
        ]));
        $tarifs = $query->orderBy('created_at', 'desc')->get();

        $jenisCode = $request->filled('jenis_tarif_id')
            ? (JenisTarif::where('id', $request->get('jenis_tarif_id'))->value('code') ?? 'filter')
            : 'semua';
        $filename = 'tarif-' . $jenisCode . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($tarifs) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Jenis', 'Service Code', 'Service Name', 'Service Description', 'Provider Code', 'Provider Name', 'Kode Kelas', 'Kelas', 'Surgery Type', 'Helper', 'Tarif', 'Berlaku Dari', 'Berlaku Sampai', 'Status']);

            foreach ($tarifs as $tarif) {
                fputcsv($out, [
                    $tarif->jenisTarif->name ?? '-',
                    $tarif->service->code ?? '-',
                    $tarif->service->name ?? '-',
                    $tarif->service->description ?? '-',
                    $tarif->provider->code ?? '-',
                    $tarif->provider->name ?? '-',
                    $tarif->serviceClass->code ?? '-',
                    $tarif->serviceClass->name ?? '-',
                    $tarif->surgery_type,
                    $tarif->helper ?? '-',
                    $tarif->tariff,
                    $tarif->valid_date_from->format('Y-m-d'),
                    $tarif->end_date_to->format('Y-m-d'),
                    $tarif->status,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
