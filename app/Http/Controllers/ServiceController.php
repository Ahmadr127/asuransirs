<?php

namespace App\Http\Controllers;

use App\Http\Requests\Service\Store;
use App\Http\Requests\Service\Update;
use App\Http\Services\ServiceService;
use App\Models\Service;

class ServiceController extends Controller
{
    public function __construct(protected ServiceService $serviceService) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $services = $this->serviceService->getServices($request->only(['search', 'status', 'sort', 'direction', 'per_page']));
        return view('services.index', compact('services'));
    }

    public function create()
    {
        return view('services.create');
    }

    public function store(Store $request)
    {
        $this->serviceService->createService($request->validated());
        return redirect()->route('services.index')->with('success', 'Service berhasil dibuat!');
    }

    public function show(Service $service)
    {
        $service->load('tarifs.jenisTarif', 'tarifs.provider', 'tarifs.serviceClass');
        return view('services.show', compact('service'));
    }

    public function edit(Service $service)
    {
        return view('services.edit', compact('service'));
    }

    public function update(Update $request, Service $service)
    {
        $this->serviceService->updateService($service, $request->validated());
        return redirect()->route('services.index')->with('success', 'Service berhasil diperbarui!');
    }

    public function destroy(Service $service)
    {
        $result = $this->serviceService->deleteService($service);
        if ($result === false) {
            return redirect()->route('services.index')->with('error', 'Service tidak dapat dihapus karena masih digunakan pada data tarif!');
        }

        return redirect()->route('services.index')->with('success', 'Service berhasil dihapus!');
    }
}
