<?php

namespace App\Http\Controllers;

use App\Http\Requests\Permission\Store;
use App\Http\Requests\Permission\Update;
use App\Http\Services\PermissionService;
use App\Models\Permission;

class PermissionController extends Controller
{
    public function __construct(protected PermissionService $permissionService) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $permissions = $this->permissionService->getPermissions($request->only(['search', 'date_from', 'date_to', 'per_page']));
        return view('permissions.index', compact('permissions'));
    }

    public function create()
    {
        return view('permissions.create');
    }

    public function store(Store $request)
    {
        $this->permissionService->createPermission($request->validated());
        return redirect()->route('permissions.index')->with('success', 'Permission berhasil dibuat!');
    }

    public function edit(Permission $permission)
    {
        return view('permissions.edit', compact('permission'));
    }

    public function update(Update $request, Permission $permission)
    {
        $this->permissionService->updatePermission($permission, $request->validated());
        return redirect()->route('permissions.index')->with('success', 'Permission berhasil diperbarui!');
    }

    public function destroy(Permission $permission)
    {
        $this->permissionService->deletePermission($permission);
        return redirect()->route('permissions.index')->with('success', 'Permission berhasil dihapus!');
    }
}
