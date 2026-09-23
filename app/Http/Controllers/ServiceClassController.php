<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceClass\Store;
use App\Http\Requests\ServiceClass\Update;
use App\Http\Services\ServiceClassService;
use App\Models\ServiceClass;

class ServiceClassController extends Controller
{
    public function __construct(protected ServiceClassService $classService) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $classes = $this->classService->getClasses($request->only(['search', 'status', 'sort', 'direction', 'per_page']));
        return view('classes.index', compact('classes'));
    }

    public function create()
    {
        return view('classes.create');
    }

    public function store(Store $request)
    {
        $this->classService->createClass($request->validated());
        return redirect()->route('classes.index')->with('success', 'Kelas berhasil dibuat!');
    }

    public function show(ServiceClass $serviceClass)
    {
        $serviceClass->load('tarifs.jenisTarif', 'tarifs.provider', 'tarifs.service');
        return view('classes.show', compact('serviceClass'));
    }

    public function edit(ServiceClass $serviceClass)
    {
        return view('classes.edit', compact('serviceClass'));
    }

    public function update(Update $request, ServiceClass $serviceClass)
    {
        $this->classService->updateClass($serviceClass, $request->validated());
        return redirect()->route('classes.index')->with('success', 'Kelas berhasil diperbarui!');
    }

    public function destroy(ServiceClass $serviceClass)
    {
        $result = $this->classService->deleteClass($serviceClass);
        if ($result === false) {
            return redirect()->route('classes.index')->with('error', 'Kelas tidak dapat dihapus karena masih digunakan pada data tarif!');
        }

        return redirect()->route('classes.index')->with('success', 'Kelas berhasil dihapus!');
    }
}
