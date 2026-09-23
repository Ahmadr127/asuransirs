<?php

namespace App\Http\Controllers;

use App\Http\Requests\Provider\Store;
use App\Http\Requests\Provider\Update;
use App\Http\Services\ProviderService;
use App\Models\Provider;

class ProviderController extends Controller
{
    public function __construct(protected ProviderService $providerService) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $providers = $this->providerService->getProviders($request->only(['search', 'status', 'sort', 'direction', 'per_page']));
        return view('providers.index', compact('providers'));
    }

    public function create()
    {
        return view('providers.create');
    }

    public function store(Store $request)
    {
        $this->providerService->createProvider($request->validated());
        return redirect()->route('providers.index')->with('success', 'Provider berhasil dibuat!');
    }

    public function show(Provider $provider)
    {
        $provider->load('tarifs.jenisTarif', 'tarifs.service', 'tarifs.serviceClass');
        return view('providers.show', compact('provider'));
    }

    public function edit(Provider $provider)
    {
        return view('providers.edit', compact('provider'));
    }

    public function update(Update $request, Provider $provider)
    {
        $this->providerService->updateProvider($provider, $request->validated());
        return redirect()->route('providers.index')->with('success', 'Provider berhasil diperbarui!');
    }

    public function destroy(Provider $provider)
    {
        $result = $this->providerService->deleteProvider($provider);
        if ($result === false) {
            return redirect()->route('providers.index')->with('error', 'Provider tidak dapat dihapus karena masih digunakan pada data tarif!');
        }

        return redirect()->route('providers.index')->with('success', 'Provider berhasil dihapus!');
    }
}
