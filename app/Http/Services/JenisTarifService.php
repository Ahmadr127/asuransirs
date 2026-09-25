<?php

namespace App\Http\Services;

use App\Models\JenisTarif;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Cache;

class JenisTarifService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function getJenisTarifs(array $filters = [])
    {
        $query = JenisTarif::query();

        if (!empty($filters['search'])) {
            $like = '%'.addcslashes(mb_strtolower($filters['search']), '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(code) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(name) LIKE ?', [$like]);
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
        $jenis = JenisTarif::create($data);
        Cache::forget('filter_jenis_tarifs');

        return $jenis;
    }

    public function updateJenisTarif(JenisTarif $jenisTarif, array $data)
    {
        $oldData = $jenisTarif->toArray();
        $jenisTarif->update($data);
        $jenisTarif->refresh();
        $newData = $jenisTarif->toArray();

        $this->activityLogger->logUpdated($jenisTarif, $oldData, $newData);
        Cache::forget('filter_jenis_tarifs');

        return $jenisTarif;
    }

    public function deleteJenisTarif(JenisTarif $jenisTarif)
    {
        if ($jenisTarif->tarifs()->count() > 0) {
            return false;
        }

        $this->activityLogger->logDeleted($jenisTarif);
        $deleted = $jenisTarif->delete();
        Cache::forget('filter_jenis_tarifs');

        return $deleted;
    }
}
