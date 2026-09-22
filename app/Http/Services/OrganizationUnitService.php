<?php

namespace App\Http\Services;

use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;

class OrganizationUnitService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    public function getUnits(array $filters = [])
    {
        $query = OrganizationUnit::with(['type', 'parent', 'head']);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }
        if (!empty($filters['type_id'])) {
            $query->where('type_id', $filters['type_id']);
        }
        if (!empty($filters['parent_id'])) {
            $query->where('parent_id', $filters['parent_id']);
        }
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $query->where('is_active', $filters['is_active']);
        }

        $perPage = in_array((int) ($filters['per_page'] ?? 10), [5, 10, 25, 50, 100]) ? (int) ($filters['per_page'] ?? 10) : 10;

        return $query->orderBy('type_id')->orderBy('name')->paginate($perPage)->withQueryString();
    }

    public function getFormData(?OrganizationUnit $excludeUnit = null): array
    {
        $types = OrganizationType::orderBy('level')->get();
        $users = User::orderBy('name')->get();

        if ($excludeUnit) {
            $excludeIds = $this->getDescendantIds($excludeUnit);
            $excludeIds[] = $excludeUnit->id;
            $parentUnits = OrganizationUnit::with('type')->active()->whereNotIn('id', $excludeIds)->orderBy('name')->get();
        } else {
            $parentUnits = OrganizationUnit::with('type')->active()->orderBy('name')->get();
        }

        return compact('types', 'parentUnits', 'users');
    }

    public function createUnit(array $data)
    {
        $data['code'] = strtoupper($data['code']);
        $data['is_active'] = isset($data['is_active']) ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) : false;

        return OrganizationUnit::create($data);
    }

    public function updateUnit(OrganizationUnit $unit, array $data)
    {
        // Prevent self parent - validation di controller/service
        if (isset($data['parent_id']) && $data['parent_id'] == $unit->id) {
            throw new \InvalidArgumentException('Unit tidak bisa menjadi parent dari dirinya sendiri!');
        }

        $data['code'] = strtoupper($data['code'] ?? $unit->code);
        // checkbox handling: jika tidak ada key is_active berarti false
        if (!array_key_exists('is_active', $data)) {
            $data['is_active'] = false;
        } else {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $oldData = $unit->toArray();
        $oldData['type'] = $unit->type?->toArray();
        $oldData['parent'] = $unit->parent?->toArray();
        $oldData['head'] = $unit->head?->toArray();

        $unit->update($data);
        $unit->refresh();
        $unit->load(['type', 'parent', 'head']);

        $newData = $unit->toArray();
        $newData['type'] = $unit->type?->toArray();
        $newData['parent'] = $unit->parent?->toArray();
        $newData['head'] = $unit->head?->toArray();

        $this->activityLogger->logUpdated($unit, $oldData, $newData);

        return $unit;
    }

    public function deleteUnit(OrganizationUnit $unit)
    {
        if ($unit->children()->count() > 0) {
            return ['success' => false, 'message' => 'Unit tidak dapat dihapus karena masih memiliki sub-unit!'];
        }
        if ($unit->members()->count() > 0) {
            return ['success' => false, 'message' => 'Unit tidak dapat dihapus karena masih memiliki anggota!'];
        }

        $unit->loadMissing(['type', 'parent', 'head']);
        $this->activityLogger->logDeleted($unit);

        $unit->delete();
        return ['success' => true];
    }

    public function addMember(OrganizationUnit $unit, int $userId)
    {
        $user = User::findOrFail($userId);
        $oldData = $user->toArray();
        $user->update(['organization_unit_id' => $unit->id]);
        $user->refresh();
        $newData = $user->toArray();

        // Log sebagai update pada User + juga log pada Unit
        $this->activityLogger->logUpdated($user, $oldData, $newData, "Added user {$user->name} to unit {$unit->name}");
        $this->activityLogger->log(
            $unit,
            'member_added',
            ['user' => $user->toArray(), 'unit_id' => $unit->id],
            "Member added: {$user->name} to {$unit->name}"
        );

        return $user;
    }

    public function removeMember(OrganizationUnit $unit, User $user)
    {
        if ($unit->head_id == $user->id) {
            return ['success' => false, 'message' => 'Tidak dapat menghapus kepala unit. Ganti kepala unit terlebih dahulu!'];
        }

        $oldData = $user->toArray();
        $user->update(['organization_unit_id' => null]);
        $user->refresh();
        $newData = $user->toArray();

        $this->activityLogger->logUpdated($user, $oldData, $newData, "Removed user {$user->name} from unit {$unit->name}");
        $this->activityLogger->log($unit, 'member_removed', ['user' => $newData, 'unit_id' => $unit->id], "Member removed: {$user->name} from {$unit->name}");

        return ['success' => true, 'user' => $user];
    }

    public function updateHead(OrganizationUnit $unit, ?int $headId)
    {
        $oldData = $unit->toArray();
        $oldData['head'] = $unit->head?->toArray();

        $unit->update(['head_id' => $headId]);
        $unit->refresh();
        $unit->load('head');

        $newData = $unit->toArray();
        $newData['head'] = $unit->head?->toArray();

        $headName = $headId ? User::find($headId)?->name : 'Tidak ada';
        $this->activityLogger->logUpdated($unit, $oldData, $newData, "Head updated to {$headName} for unit {$unit->name}");

        return $unit;
    }

    private function getDescendantIds(OrganizationUnit $unit): array
    {
        $ids = [];
        $unit->loadMissing('children');
        foreach ($unit->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $this->getDescendantIds($child));
        }
        return $ids;
    }
}
