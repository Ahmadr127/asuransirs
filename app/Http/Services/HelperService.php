<?php

namespace App\Http\Services;

use App\Models\Helper;
use App\Services\ActivityLogService;

class HelperService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function getHelpers(array $filters = [])
    {
        $query = Helper::query();

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

    public function createHelper(array $data)
    {
        return Helper::create($data);
    }

    public function updateHelper(Helper $helper, array $data)
    {
        $oldData = $helper->toArray();
        $helper->update($data);
        $helper->refresh();
        $newData = $helper->toArray();

        $this->activityLogger->logUpdated($helper, $oldData, $newData);

        return $helper;
    }

    public function deleteHelper(Helper $helper)
    {
        $this->activityLogger->logDeleted($helper);

        return $helper->delete();
    }
}
