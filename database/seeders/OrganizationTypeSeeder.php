<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\OrganizationType;

class OrganizationTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => 'holding',
                'display_name' => 'Holding Company',
                'level' => 1,
                'description' => 'Perusahaan induk/PT yang menaungi unit bisnis'
            ],
            [
                'name' => 'hospital',
                'display_name' => 'Rumah Sakit',
                'level' => 2,
                'description' => 'Unit rumah sakit di bawah holding'
            ],
            [
                'name' => 'directorate',
                'display_name' => 'Direktorat',
                'level' => 3,
                'description' => 'Direktorat dalam rumah sakit'
            ],
            [
                'name' => 'department',
                'display_name' => 'Departemen',
                'level' => 4,
                'description' => 'Departemen dalam direktorat'
            ],
            [
                'name' => 'unit',
                'display_name' => 'Unit/Seksi',
                'level' => 5,
                'description' => 'Sub-unit dalam departemen'
            ],
        ];

        foreach ($types as $type) {
            OrganizationType::create($type);
        }
    }
}
