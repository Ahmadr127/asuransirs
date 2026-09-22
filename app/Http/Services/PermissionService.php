<?php

namespace App\Http\Services;

use App\Models\Permission;
use App\Services\ActivityLogService;

class PermissionService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function getPermissions(array $filters = [])
    {
        $query = Permission::with('roles');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('display_name', 'like', "%{$search}%");
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

    public function createPermission(array $data)
    {
        return Permission::create($data);
    }

    public function updatePermission(Permission $permission, array $data)
    {
        $oldData = $permission->toArray();
        $permission->update($data);
        $permission->refresh();
        $newData = $permission->toArray();

        $this->activityLogger->logUpdated($permission, $oldData, $newData);

        return $permission;
    }

    public function deletePermission(Permission $permission)
    {
        $permission->loadMissing('roles');
        $this->activityLogger->logDeleted($permission);
        return $permission->delete();
    }
}
