<?php

namespace App\Http\Services;

use App\Models\Service;
use App\Services\ActivityLogService;

class ServiceService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function getServices(array $filters = [])
    {
        $query = Service::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
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

    public function createService(array $data)
    {
        return Service::create($data);
    }

    public function updateService(Service $service, array $data)
    {
        $oldData = $service->toArray();
        $service->update($data);
        $service->refresh();
        $newData = $service->toArray();

        $this->activityLogger->logUpdated($service, $oldData, $newData);

        return $service;
    }

    public function deleteService(Service $service)
    {
        if ($service->tarifs()->count() > 0) {
            return false;
        }

        $this->activityLogger->logDeleted($service);
        return $service->delete();
    }
}
