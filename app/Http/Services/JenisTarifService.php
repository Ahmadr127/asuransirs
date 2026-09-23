<?php

namespace App\Http\Services;

use App\Models\JenisTarif;
use App\Services\ActivityLogService;

class JenisTarifService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function getJenisTarifs(array $filters = [])
    {
        $query = JenisTarif::query();

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

    public function createJenisTarif(array $data)
    {
        return JenisTarif::create($data);
    }

    public function updateJenisTarif(JenisTarif $jenisTarif, array $data)
    {
        $oldData = $jenisTarif->toArray();
        $jenisTarif->update($data);
        $jenisTarif->refresh();
        $newData = $jenisTarif->toArray();

        $this->activityLogger->logUpdated($jenisTarif, $oldData, $newData);

        return $jenisTarif;
    }

    public function deleteJenisTarif(JenisTarif $jenisTarif)
    {
        if ($jenisTarif->tarifs()->count() > 0) {
            return false;
        }

        $this->activityLogger->logDeleted($jenisTarif);
        return $jenisTarif->delete();
    }
}
