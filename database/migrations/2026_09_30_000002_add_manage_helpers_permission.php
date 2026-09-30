<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permission = [
            'name' => 'manage_helpers',
            'display_name' => 'Kelola Helper',
            'description' => 'Mengelola master helper (aturan persentase billing bedah)',
        ];

        $admin = DB::table('roles')->where('name', 'admin')->first();

        $exists = DB::table('permissions')->where('name', $permission['name'])->exists();
        if (! $exists) {
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
        $ids = DB::table('permissions')->where('name', 'manage_helpers')->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
