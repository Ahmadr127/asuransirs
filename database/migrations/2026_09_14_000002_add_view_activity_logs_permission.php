<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('permissions')->where('name', 'view_activity_logs')->exists();
        if (!$exists) {
            $id = DB::table('permissions')->insertGetId([
                'name' => 'view_activity_logs',
                'display_name' => 'Lihat Audit Log',
                'description' => 'Melihat log update & delete (data lama, waktu, by user)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // assign ke role admin
            $admin = DB::table('roles')->where('name', 'admin')->first();
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
        $perm = DB::table('permissions')->where('name', 'view_activity_logs')->first();
        if ($perm) {
            DB::table('role_permission')->where('permission_id', $perm->id)->delete();
            DB::table('permissions')->where('id', $perm->id)->delete();
        }
    }
};
