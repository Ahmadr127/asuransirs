<?php

namespace Database\Seeders;

use App\Models\Helper;
use Illuminate\Database\Seeder;

class HelperSeeder extends Seeder
{
    public function run(): void
    {
        $helpers = [
            ['code' => 'CITO', 'name' => 'CITO', 'operator_pct' => 30, 'anesthesia_pct' => 30, 'room_pct' => 30, 'child_pct' => 30],
            ['code' => 'PENYULIT', 'name' => 'PENYULIT', 'operator_pct' => 30, 'anesthesia_pct' => 30, 'room_pct' => 30, 'child_pct' => 30],
            ['code' => 'CITO_PENYULIT', 'name' => 'CITO+PENYULIT', 'operator_pct' => 60, 'anesthesia_pct' => 60, 'room_pct' => 60, 'child_pct' => 60],
            ['code' => 'GEMELLI', 'name' => 'GEMELLI', 'operator_pct' => 130, 'anesthesia_pct' => 130, 'room_pct' => 130, 'child_pct' => 130],
            ['code' => 'TRIPLET', 'name' => 'TRIPLET', 'operator_pct' => 175, 'anesthesia_pct' => 175, 'room_pct' => 175, 'child_pct' => 175],
            ['code' => 'TINDAKAN_1', 'name' => 'TINDAKAN KE 1', 'operator_pct' => 100, 'anesthesia_pct' => 100, 'room_pct' => 100, 'child_pct' => 100],
            ['code' => 'TINDAKAN_2_LOKASI_SAMA_OPERATOR_SAMA', 'name' => 'TINDAKAN KE 2 LOKASI SAMA OPERATOR SAMA', 'operator_pct' => 50, 'anesthesia_pct' => 50, 'room_pct' => 0, 'child_pct' => 0],
            ['code' => 'TINDAKAN_2_LOKASI_SAMA_OPERATOR_BEDA', 'name' => 'TINDAKAN KE 2 LOKASI SAMA OPERATOR BEDA', 'operator_pct' => 100, 'anesthesia_pct' => 100, 'room_pct' => 0, 'child_pct' => 0],
            ['code' => 'TINDAKAN_2_LOKASI_BEDA_OPERATOR_SAMA', 'name' => 'TINDAKAN KE 2 LOKASI BEDA OPERATOR SAMA', 'operator_pct' => 100, 'anesthesia_pct' => 100, 'room_pct' => 0, 'child_pct' => 0],
            ['code' => 'TINDAKAN_2_LOKASI_BEDA_OPERATOR_BEDA', 'name' => 'TINDAKAN KE 2 LOKASI BEDA OPERATOR BEDA', 'operator_pct' => 100, 'anesthesia_pct' => 100, 'room_pct' => 0, 'child_pct' => 0],
            ['code' => 'TINDAKAN_3_LOKASI_SAMA_OPERATOR_SAMA', 'name' => 'TINDAKAN KE 3 LOKASI SAMA OPERATOR SAMA', 'operator_pct' => 50, 'anesthesia_pct' => 50, 'room_pct' => 0, 'child_pct' => 0],
            ['code' => 'TINDAKAN_3_LOKASI_SAMA_OPERATOR_BEDA', 'name' => 'TINDAKAN KE 3 LOKASI SAMA OPERATOR BEDA', 'operator_pct' => 100, 'anesthesia_pct' => 100, 'room_pct' => 0, 'child_pct' => 0],
            ['code' => 'TINDAKAN_3_LOKASI_BEDA_OPERATOR_SAMA', 'name' => 'TINDAKAN KE 3 LOKASI BEDA OPERATOR SAMA', 'operator_pct' => 100, 'anesthesia_pct' => 100, 'room_pct' => 0, 'child_pct' => 0],
            ['code' => 'TINDAKAN_3_LOKASI_BEDA_OPERATOR_BEDA', 'name' => 'TINDAKAN KE 3 LOKASI BEDA OPERATOR BEDA', 'operator_pct' => 100, 'anesthesia_pct' => 100, 'room_pct' => 0, 'child_pct' => 0],
            ['code' => 'REOPERASI_1', 'name' => 'REOPERASI KE 1', 'operator_pct' => 75, 'anesthesia_pct' => 75, 'room_pct' => 75, 'child_pct' => 75],
        ];

        foreach ($helpers as $helper) {
            Helper::firstOrCreate(
                ['code' => $helper['code']],
                array_merge($helper, ['status' => 'active'])
            );
        }
    }
}
