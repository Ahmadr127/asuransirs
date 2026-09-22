<?php

namespace App\Http\Services;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(protected ActivityLogService $activityLogger) {}

    /**
     * Create a new class instance.
     */
    public function getUsers(array $filters = []){
        $query = User::with('role');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        //pagination
        $perPage = in_array((int) ($filters['per_page'] ?? 10), [5, 10, 25, 50, 100]) ? (int) ($filters['per_page'] ?? 10) : 10;    

        return $query
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function createUser(array $data){
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        return User::create($data);
    }

    public function updateUser(User $user, array $data){
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        // Simpan snapshot sebelum update untuk log
        $oldData = $user->toArray();
        // Load relasi untuk konteks (opsional)
        $oldData['role'] = $user->role?->toArray();

        $user->update($data);
        $user->refresh();
        $user->load('role');

        $newData = $user->toArray();
        $newData['role'] = $user->role?->toArray();

        $this->activityLogger->logUpdated($user, $oldData, $newData);

        return $user;
    }

    public function deleteUser(User $user){
        // Simpan snapshot lengkap sebelum hapus
        // Load relasi agar log komplit
        $user->loadMissing('role', 'organizationUnit');
        $this->activityLogger->logDeleted($user);

        return $user->delete();
    }

}
