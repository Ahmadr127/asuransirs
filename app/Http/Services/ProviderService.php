<?php

namespace App\Http\Services;

use App\Models\Provider;
use App\Services\ActivityLogService;

class ProviderService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function getProviders(array $filters = [])
    {
        $query = Provider::query();

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

    public function createProvider(array $data)
    {
        return Provider::create($data);
    }

    public function updateProvider(Provider $provider, array $data)
    {
        $oldData = $provider->toArray();
        $provider->update($data);
        $provider->refresh();
        $newData = $provider->toArray();

        $this->activityLogger->logUpdated($provider, $oldData, $newData);

        return $provider;
    }

    public function deleteProvider(Provider $provider)
    {
        if ($provider->tarifs()->count() > 0) {
            return false;
        }

        $this->activityLogger->logDeleted($provider);
        return $provider->delete();
    }
}
