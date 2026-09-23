<?php

namespace App\Http\Services;

use App\Models\Tarif;
use App\Services\ActivityLogService;
use Illuminate\Support\Carbon;

class TarifService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function applyFilters($query, array $filters = [])
    {
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('helper', 'like', "%{$search}%")
                  ->orWhereHas('service', function ($sq) use ($search) {
                      $sq->where('code', 'like', "%{$search}%")
                         ->orWhere('name', 'like', "%{$search}%")
                         ->orWhere('description', 'like', "%{$search}%");
                  })
                  ->orWhereHas('provider', function ($pq) use ($search) {
                      $pq->where('code', 'like', "%{$search}%")
                         ->orWhere('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('serviceClass', function ($cq) use ($search) {
                      $cq->where('code', 'like', "%{$search}%")
                         ->orWhere('name', 'like', "%{$search}%");
                  });
            });
        }

        if (!empty($filters['jenis_tarif_id'])) {
            $query->where('jenis_tarif_id', $filters['jenis_tarif_id']);
        }

        if (!empty($filters['provider_id'])) {
            $query->where('provider_id', $filters['provider_id']);
        }

        if (!empty($filters['service_id'])) {
            $query->where('service_id', $filters['service_id']);
        }

        if (!empty($filters['class_id'])) {
            $query->where('class_id', $filters['class_id']);
        }

        if (!empty($filters['surgery_type']) && in_array($filters['surgery_type'], Tarif::SURGERY_TYPES)) {
            $query->where('surgery_type', $filters['surgery_type']);
        }

        // Status dihitung dari periode, filter via SQL agar bisa dikombinasikan
        if (!empty($filters['status']) && in_array($filters['status'], Tarif::STATUSES)) {
            $today = Carbon::today()->toDateString();
            match ($filters['status']) {
                'UPCOMING' => $query->where('valid_date_from', '>', $today),
                'ACTIVE' => $query->where('valid_date_from', '<=', $today)->where('end_date_to', '>=', $today),
                'EXPIRED' => $query->where('end_date_to', '<', $today),
            };
        }

        // Filter periode (overlap dengan masa berlaku tarif)
        if (!empty($filters['date_from'])) {
            $query->where('end_date_to', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('valid_date_from', '<=', $filters['date_to']);
        }

        return $query;
    }

    public function getTarifs(array $filters = [])
    {
        $query = Tarif::with(['jenisTarif', 'provider', 'service', 'serviceClass']);

        $this->applyFilters($query, $filters);

        $sort = in_array($filters['sort'] ?? '', ['tariff', 'valid_date_from', 'end_date_to', 'created_at'])
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? '') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        $perPage = in_array((int) ($filters['per_page'] ?? 10), [5, 10, 25, 50, 100]) ? (int) ($filters['per_page'] ?? 10) : 10;

        return $query->paginate($perPage)->withQueryString();
    }

    public function createTarif(array $data)
    {
        return Tarif::create($data);
    }

    public function updateTarif(Tarif $tarif, array $data)
    {
        $oldData = $tarif->toArray();
        $tarif->update($data);
        $tarif->refresh();
        $tarif->load(['jenisTarif', 'provider', 'service', 'serviceClass']);
        $newData = $tarif->toArray();

        $this->activityLogger->logUpdated($tarif, $oldData, $newData);

        return $tarif;
    }

    public function deleteTarif(Tarif $tarif)
    {
        $tarif->loadMissing(['jenisTarif', 'provider', 'service', 'serviceClass']);
        $this->activityLogger->logDeleted($tarif);

        return $tarif->delete();
    }
}
