<?php

namespace App\Http\Services;

use App\Models\Service;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Cache;

class ServiceService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function getServices(array $filters = [])
    {
        $query = Service::query();

        if (!empty($filters['search'])) {
            $like = '%'.addcslashes(mb_strtolower($filters['search']), '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(code) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(description) LIKE ?', [$like]);
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
        $service = Service::create($data);
        Cache::forget('filter_services');

        return $service;
    }

    public function updateService(Service $service, array $data)
    {
        $oldData = $service->toArray();
        $service->update($data);
        $service->refresh();
        $newData = $service->toArray();

        $this->activityLogger->logUpdated($service, $oldData, $newData);
        Cache::forget('filter_services');

        return $service;
    }

    public function deleteService(Service $service)
    {
        if ($service->tarifs()->count() > 0) {
            return false;
        }

        $this->activityLogger->logDeleted($service);
        $deleted = $service->delete();
        Cache::forget('filter_services');

        return $deleted;
    }
}
