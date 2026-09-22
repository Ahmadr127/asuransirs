<?php

namespace App\Http\Services;

use App\Models\OrganizationType;
use App\Services\ActivityLogService;

class OrganizationTypeService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function getTypes(array $filters = [])
    {
        $query = OrganizationType::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('display_name', 'like', "%{$search}%");
            });
        }

        $perPage = in_array((int) ($filters['per_page'] ?? 10), [5, 10, 25, 50, 100]) ? (int) ($filters['per_page'] ?? 10) : 10;

        return $query->orderBy('level')->paginate($perPage)->withQueryString();
    }

    public function createType(array $data)
    {
        return OrganizationType::create($data);
    }

    public function updateType(OrganizationType $type, array $data)
    {
        $oldData = $type->toArray();
        $type->update($data);
        $type->refresh();
        $newData = $type->toArray();

        $this->activityLogger->logUpdated($type, $oldData, $newData);

        return $type;
    }

    public function deleteType(OrganizationType $type)
    {
        if ($type->organizationUnits()->count() > 0) {
            return false;
        }

        $this->activityLogger->logDeleted($type);
        return $type->delete();
    }
}
