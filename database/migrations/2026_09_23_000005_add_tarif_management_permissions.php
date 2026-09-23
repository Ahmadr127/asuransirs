<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            [
                'name' => 'manage_tarif_masters',
                'display_name' => 'Kelola Master Tarif',
                'description' => 'Mengelola master provider, service, dan kelas',
            ],
            [
                'name' => 'manage_tarifs',
                'display_name' => 'Kelola Tarif',
                'description' => 'Mengelola data tarif (tarif, obat, alkes, bhp, makanan)',
            ],
        ];

        $admin = DB::table('roles')->where('name', 'admin')->first();

        foreach ($permissions as $permission) {
            $exists = DB::table('permissions')->where('name', $permission['name'])->exists();
            if ($exists) {
                continue;
            }

            $id = DB::table('permissions')->insertGetId(array_merge($permission, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            // assign ke role admin
            if ($admin) {
                DB::table('role_permission')->insert([
                    'role_id' => $admin->id,
                    'permission_id' => $id,
                ]);
            }
        }
    }

    public function down(): void
    {
        $names = ['manage_tarif_masters', 'manage_tarifs'];
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
