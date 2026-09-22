<?php

namespace App\Http\Controllers;

use App\Http\Requests\Role\Store;
use App\Http\Requests\Role\Update;
use App\Http\Services\RoleService;
use App\Models\Permission;
use App\Models\Role;

class RoleController extends Controller
{
    public function __construct(protected RoleService $roleService) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $roles = $this->roleService->getRoles($request->only(['search', 'date_from', 'date_to', 'per_page']));
        $permissions = Permission::all();
        $stats = $this->roleService->getStats();

        return view('roles.index', [
            'roles' => $roles,
            'permissions' => $permissions,
            'totalRoles' => $stats['total'],
            'activeRoles' => $stats['active'],
            'inactiveRoles' => $stats['inactive'],
        ]);
    }

    public function create()
    {
        $permissions = Permission::all();
        return view('roles.create', compact('permissions'));
    }

    public function store(Store $request)
    {
        $this->roleService->createRole($request->validated());
        return redirect()->route('roles.index')->with('success', 'Role berhasil dibuat!');
    }

    public function edit(Role $role)
    {
        $permissions = Permission::all();
        return view('roles.edit', compact('role', 'permissions'));
    }

    public function update(Update $request, Role $role)
    {
        $this->roleService->updateRole($role, $request->validated());
        return redirect()->route('roles.index')->with('success', 'Role berhasil diperbarui!');
    }

    public function destroy(Role $role)
    {
        $result = $this->roleService->deleteRole($role);
        if ($result === false) {
            return redirect()->route('roles.index')->with('error', 'Role tidak dapat dihapus karena masih digunakan oleh pengguna!');
        }

        return redirect()->route('roles.index')->with('success', 'Role berhasil dihapus!');
    }
}
