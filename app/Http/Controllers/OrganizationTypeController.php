<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrganizationType\Store;
use App\Http\Requests\OrganizationType\Update;
use App\Http\Services\OrganizationTypeService;
use App\Models\OrganizationType;

class OrganizationTypeController extends Controller
{
    public function __construct(protected OrganizationTypeService $typeService) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $types = $this->typeService->getTypes($request->only(['search', 'per_page']));
        return view('organization-types.index', compact('types'));
    }

    public function create()
    {
        return view('organization-types.create');
    }

    public function store(Store $request)
    {
        $this->typeService->createType($request->validated());
        return redirect()->route('organization-types.index')->with('success', 'Tipe Organisasi berhasil dibuat!');
    }

    public function edit(OrganizationType $organizationType)
    {
        return view('organization-types.edit', compact('organizationType'));
    }

    public function update(Update $request, OrganizationType $organizationType)
    {
        $this->typeService->updateType($organizationType, $request->validated());
        return redirect()->route('organization-types.index')->with('success', 'Tipe Organisasi berhasil diperbarui!');
    }

    public function destroy(OrganizationType $organizationType)
    {
        $result = $this->typeService->deleteType($organizationType);
        if ($result === false) {
            return redirect()->route('organization-types.index')->with('error', 'Tipe organisasi tidak dapat dihapus karena masih digunakan!');
        }

        return redirect()->route('organization-types.index')->with('success', 'Tipe Organisasi berhasil dihapus!');
    }
}
