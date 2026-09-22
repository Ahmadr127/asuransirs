<?php

namespace App\Http\Services;

use App\Models\Role;
use App\Services\ActivityLogService;

class RoleService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function getRoles(array $filters = [])
    {
        $query = Role::with('permissions')->withCount('users');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('display_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        $perPage = in_array((int) ($filters['per_page'] ?? 10), [5, 10, 25, 50, 100]) ? (int) ($filters['per_page'] ?? 10) : 10;

        return $query->latest()->paginate($perPage)->withQueryString();
    }

    public function getStats(): array
    {
        return [
            'total' => Role::count(),
            'active' => Role::where('is_active', true)->count(),
            'inactive' => Role::where('is_active', false)->count(),
        ];
    }

    public function createRole(array $data)
    {
        $permissions = $data['permissions'] ?? null;
        unset($data['permissions']);

        // handle boolean
        if (isset($data['is_active'])) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $data['is_active'] = true;
        }

        $role = Role::create($data);

        if (!empty($permissions)) {
            $role->permissions()->attach($permissions);
        }

        return $role;
    }

    public function updateRole(Role $role, array $data)
    {
        $permissions = $data['permissions'] ?? null;
        // jika key permissions tidak ada, berarti sync kosong (hapus semua)
        $hasPermissionsKey = array_key_exists('permissions', $data);
        unset($data['permissions']);

        if (isset($data['is_active'])) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $oldData = $role->toArray();
        $oldData['permissions'] = $role->permissions->pluck('id')->toArray();

        $role->update($data);

        if ($hasPermissionsKey) {
            $role->permissions()->sync($permissions ?? []);
        }

        $role->refresh();
        $role->load('permissions');
        $newData = $role->toArray();
        $newData['permissions'] = $role->permissions->pluck('id')->toArray();

        $this->activityLogger->logUpdated($role, $oldData, $newData);

        return $role;
    }

    public function deleteRole(Role $role)
    {
        if ($role->users()->count() > 0) {
            return false; // signal cannot delete
        }

        $role->loadMissing('permissions');
        $this->activityLogger->logDeleted($role);

        return $role->delete();
    }
}
