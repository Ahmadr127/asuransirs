<?php

namespace App\Http\Services;

use App\Models\ServiceClass;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Cache;

class ServiceClassService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function getClasses(array $filters = [])
    {
        $query = ServiceClass::query();

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

    public function createClass(array $data)
    {
        $class = ServiceClass::create($data);
        Cache::forget('filter_classes');

        return $class;
    }

    public function updateClass(ServiceClass $class, array $data)
    {
        $oldData = $class->toArray();
        $class->update($data);
        $class->refresh();
        $newData = $class->toArray();

        $this->activityLogger->logUpdated($class, $oldData, $newData);
        Cache::forget('filter_classes');

        return $class;
    }

    public function deleteClass(ServiceClass $class)
    {
        if ($class->tarifs()->count() > 0) {
            return false;
        }

        $this->activityLogger->logDeleted($class);
        $deleted = $class->delete();
        Cache::forget('filter_classes');

        return $deleted;
    }
}
