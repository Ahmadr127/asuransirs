<?php

namespace App\Http\Services;

use App\Models\ServiceClass;
use App\Services\ActivityLogService;

class ServiceClassService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function getClasses(array $filters = [])
    {
        $query = ServiceClass::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sort = in_array($filters['sort'] ?? '', ['code', 'name', 'created_at']) ? $filters['sort'] : 'name';
        $direction = ($filters['direction'] ?? '') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction);

        $perPage = in_array((int) ($filters['per_page'] ?? 10), [5, 10, 25, 50, 100]) ? (int) ($filters['per_page'] ?? 10) : 10;

        return $query->paginate($perPage)->withQueryString();
    }

    public function createClass(array $data)
    {
        return ServiceClass::create($data);
    }

    public function updateClass(ServiceClass $class, array $data)
    {
        $oldData = $class->toArray();
        $class->update($data);
        $class->refresh();
        $newData = $class->toArray();

        $this->activityLogger->logUpdated($class, $oldData, $newData);

        return $class;
    }

    public function deleteClass(ServiceClass $class)
    {
        if ($class->tarifs()->count() > 0) {
            return false;
        }

        $this->activityLogger->logDeleted($class);
        return $class->delete();
    }
}
