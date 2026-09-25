<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tarif\Store;
use App\Http\Requests\Tarif\Update;
use App\Http\Services\TarifExportService;
use App\Http\Services\TarifService;
use App\Models\JenisTarif;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceClass;
use App\Models\Tarif;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TarifController extends Controller
{
    public function __construct(protected TarifService $tarifService, protected TarifExportService $exportService) {}

    public function index(Request $request)
    {
        $tarifs = $this->tarifService->getTarifs($request->only([
            'search', 'jenis_tarif_id', 'provider_id', 'service_id', 'service_code', 'class_id',
            'surgery_type', 'status', 'date_from', 'date_to', 'sort', 'direction', 'per_page',
        ]));

        // $services/$providers sengaja tidak dikirim ke view index (filter service
        // pakai input service_code, filter provider dihapus).
        ['jenisTarifs' => $jenisTarifs, 'classes' => $classes] = $this->filterMasters();

        return view('tarifs.index', compact('tarifs', 'jenisTarifs', 'classes'));
    }

    public function create()
    {
        ['jenisTarifs' => $jenisTarifs, 'providers' => $providers, 'services' => $services, 'classes' => $classes] = $this->filterMasters();

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
        ['jenisTarifs' => $jenisTarifs, 'providers' => $providers, 'services' => $services, 'classes' => $classes] = $this->filterMasters();

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
     * Master untuk dropdown filter/form. Di-cache forever sebagai array polos
     * (di-forget saat master berubah di Service masing-masing) agar halaman
     * tidak menghidrasi 13rb+ model Service setiap load (terukur: 230ms ->
     * 16ms). Hanya kolom yang dipakai view. Dibungkus collect() agar Blade
     * tetap bisa memakai ->map().
     *
     * @return array{jenisTarifs: \Illuminate\Support\Collection, providers: \Illuminate\Support\Collection, services: \Illuminate\Support\Collection, classes: \Illuminate\Support\Collection}
     */
    protected function filterMasters(): array
    {
        $cached = fn (string $key, string $model) => collect(Cache::rememberForever($key, fn () =>
            $model::where('status', 'active')->orderBy('name')->get(['id', 'code', 'name'])->toArray()));

        return [
            'jenisTarifs' => $cached('filter_jenis_tarifs', JenisTarif::class),
            'providers' => $cached('filter_providers', Provider::class),
            'services' => $cached('filter_services', Service::class),
            'classes' => $cached('filter_classes', ServiceClass::class),
        ];
    }

    /**
     * Export CSV mengikuti filter yang sedang aktif
     * (semua data / filter aktif / per jenis tarif).
     */
    public function export(Request $request): StreamedResponse
    {
        return $this->exportService->exportCsv($request->only([
            'search', 'jenis_tarif_id', 'provider_id', 'service_id', 'service_code', 'class_id',
            'surgery_type', 'status', 'date_from', 'date_to',
        ]));
    }
}
