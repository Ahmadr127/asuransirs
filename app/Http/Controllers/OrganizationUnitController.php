<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrganizationUnit\AddMember;
use App\Http\Requests\OrganizationUnit\Store;
use App\Http\Requests\OrganizationUnit\Update;
use App\Http\Requests\OrganizationUnit\UpdateHead;
use App\Http\Services\OrganizationUnitService;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\User;

class OrganizationUnitController extends Controller
{
    public function __construct(protected OrganizationUnitService $unitService) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $units = $this->unitService->getUnits($request->only(['search', 'type_id', 'parent_id', 'is_active', 'per_page']));
        $types = OrganizationType::orderBy('level')->get();
        $parentUnits = OrganizationUnit::orderBy('name')->get();

        return view('organization-units.index', compact('units', 'types', 'parentUnits'));
    }

    public function create()
    {
        $data = $this->unitService->getFormData();
        return view('organization-units.create', $data);
    }

    public function store(Store $request)
    {
        $this->unitService->createUnit($request->validated());
        return redirect()->route('organization-units.index')->with('success', 'Unit Organisasi berhasil dibuat!');
    }

    public function show(OrganizationUnit $organizationUnit)
    {
        $organizationUnit->load(['type', 'parent', 'head', 'members', 'children.type']);

        $availableUsers = User::whereNull('organization_unit_id')
            ->orWhere('organization_unit_id', '!=', $organizationUnit->id)
            ->orderBy('name')
            ->get();

        $allUsers = User::orderBy('name')->get();

        return view('organization-units.show', compact('organizationUnit', 'availableUsers', 'allUsers'));
    }

    public function edit(OrganizationUnit $organizationUnit)
    {
        $data = $this->unitService->getFormData($organizationUnit);
        return view('organization-units.edit', array_merge(['organizationUnit' => $organizationUnit], $data));
    }

    public function update(Update $request, OrganizationUnit $organizationUnit)
    {
        try {
            $this->unitService->updateUnit($organizationUnit, $request->validated());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('organization-units.index')->with('success', 'Unit Organisasi berhasil diperbarui!');
    }

    public function destroy(OrganizationUnit $organizationUnit)
    {
        $result = $this->unitService->deleteUnit($organizationUnit);

        if (isset($result['success']) && $result['success'] === false) {
            return redirect()->route('organization-units.index')->with('error', $result['message']);
        }

        return redirect()->route('organization-units.index')->with('success', 'Unit Organisasi berhasil dihapus!');
    }

    public function addMember(AddMember $request, OrganizationUnit $organizationUnit)
    {
        $user = $this->unitService->addMember($organizationUnit, $request->validated()['user_id']);

        return redirect()->route('organization-units.show', $organizationUnit)
            ->with('success', "User {$user->name} berhasil ditambahkan ke unit!");
    }

    public function removeMember(OrganizationUnit $organizationUnit, User $user)
    {
        $result = $this->unitService->removeMember($organizationUnit, $user);

        if (isset($result['success']) && $result['success'] === false) {
            return redirect()->route('organization-units.show', $organizationUnit)
                ->with('error', $result['message']);
        }

        return redirect()->route('organization-units.show', $organizationUnit)
            ->with('success', "User {$user->name} berhasil dihapus dari unit!");
    }

    public function updateHead(UpdateHead $request, OrganizationUnit $organizationUnit)
    {
        $this->unitService->updateHead($organizationUnit, $request->validated()['head_id'] ?? null);

        $headName = $request->head_id ? User::find($request->head_id)?->name : 'Tidak ada';
        return redirect()->route('organization-units.show', $organizationUnit)
            ->with('success', "Kepala unit berhasil diubah menjadi: {$headName}");
    }
}
