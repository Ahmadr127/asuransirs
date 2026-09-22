<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OrganizationUnitSeeder extends Seeder
{
    public function run(): void
    {
        // Get types
        $holdingType = OrganizationType::where('name', 'holding')->first();
        $hospitalType = OrganizationType::where('name', 'hospital')->first();
        $directorateType = OrganizationType::where('name', 'directorate')->first();
        $departmentType = OrganizationType::where('name', 'department')->first();
        $unitType = OrganizationType::where('name', 'unit')->first();

        // Get or create manager role
        $managerRole = Role::firstOrCreate(
            ['name' => 'manager'],
            ['display_name' => 'Manager', 'description' => 'Manager unit organisasi']
        );
        
        $staffRole = Role::firstOrCreate(
            ['name' => 'staff'],
            ['display_name' => 'Staff', 'description' => 'Staff umum']
        );

        // ========================================
        // 1. DIREKTUR UTAMA (Top Level)
        // ========================================
        $direkturUtama = $this->createUserWithUnit(
            'Dr. Ahmad Direktur',
            'direktur.utama',
            'direktur.utama@hospital.com',
            'Direktur Utama',
            'DIRUT',
            $holdingType->id,
            null,
            'Direktur Utama Rumah Sakit',
            $managerRole->id
        );

        // ========================================
        // 2. DEPARTEMEN SIRS (langsung dibawah Direktur Utama)
        // ========================================
        $sirs = OrganizationUnit::create([
            'name' => 'Departemen SIRS',
            'code' => 'SIRS',
            'type_id' => $departmentType->id,
            'parent_id' => $direkturUtama['unit']->id,
            'description' => 'Sistem Informasi Rumah Sakit',
            'is_active' => true,
        ]);

        // Manager SIRS
        $managerSirs = $this->createUser('Budi Manager SIRS', 'budi.sirs', 'manager.sirs@hospital.com', $managerRole->id, $sirs->id);
        $sirs->update(['head_id' => $managerSirs->id]);

        // Staff SIRS (2 users)
        $this->createUser('Citra Staff SIRS', 'citra.sirs', 'citra.sirs@hospital.com', $staffRole->id, $sirs->id);
        $this->createUser('Dani Staff SIRS', 'dani.sirs', 'dani.sirs@hospital.com', $staffRole->id, $sirs->id);

        // ========================================
        // 3. SEKRETARIS (langsung dibawah Direktur Utama)
        // ========================================
        $sekretaris = OrganizationUnit::create([
            'name' => 'Sekretaris',
            'code' => 'SEKR',
            'type_id' => $departmentType->id,
            'parent_id' => $direkturUtama['unit']->id,
            'description' => 'Sekretaris Direktur',
            'is_active' => true,
        ]);

        // Manager Sekretaris
        $managerSekretaris = $this->createUser('Erna Manager Sekretaris', 'erna.sekretaris', 'manager.sekretaris@hospital.com', $managerRole->id, $sekretaris->id);
        $sekretaris->update(['head_id' => $managerSekretaris->id]);

        // Staff Sekretaris (2 users)
        $this->createUser('Fitri Staff Sekretaris', 'fitri.sekretaris', 'fitri.sekretaris@hospital.com', $staffRole->id, $sekretaris->id);
        $this->createUser('Gina Staff Sekretaris', 'gina.sekretaris', 'gina.sekretaris@hospital.com', $staffRole->id, $sekretaris->id);

        // ========================================
        // 4. KEPERAWATAN (langsung dibawah Direktur Utama)
        // ========================================
        $keperawatan = OrganizationUnit::create([
            'name' => 'Departemen Keperawatan',
            'code' => 'PERAWAT',
            'type_id' => $departmentType->id,
            'parent_id' => $direkturUtama['unit']->id,
            'description' => 'Departemen Keperawatan',
            'is_active' => true,
        ]);

        // Manager Keperawatan
        $managerKeperawatan = $this->createUser('Hana Manager Keperawatan', 'hana.keperawatan', 'manager.keperawatan@hospital.com', $managerRole->id, $keperawatan->id);
        $keperawatan->update(['head_id' => $managerKeperawatan->id]);

        // Staff Keperawatan (2 users)
        $this->createUser('Indah Staff Keperawatan', 'indah.keperawatan', 'indah.keperawatan@hospital.com', $staffRole->id, $keperawatan->id);
        $this->createUser('Joko Staff Keperawatan', 'joko.keperawatan', 'joko.keperawatan@hospital.com', $staffRole->id, $keperawatan->id);

        // ========================================
        // 5. SUB-UNIT KEPERAWATAN (untuk contoh hierarki lebih dalam)
        // ========================================
        
        // Unit Rawat Inap
        $rawatInap = OrganizationUnit::create([
            'name' => 'Unit Rawat Inap',
            'code' => 'RANAP',
            'type_id' => $unitType->id,
            'parent_id' => $keperawatan->id,
            'description' => 'Unit Rawat Inap Keperawatan',
            'is_active' => true,
        ]);

        // Manager Rawat Inap
        $managerRanap = $this->createUser('Kiki Manager Rawat Inap', 'kiki.ranap', 'manager.ranap@hospital.com', $managerRole->id, $rawatInap->id);
        $rawatInap->update(['head_id' => $managerRanap->id]);

        // Staff Rawat Inap (2 users)
        $this->createUser('Lina Perawat Ranap', 'lina.ranap', 'lina.ranap@hospital.com', $staffRole->id, $rawatInap->id);
        $this->createUser('Maya Perawat Ranap', 'maya.ranap', 'maya.ranap@hospital.com', $staffRole->id, $rawatInap->id);

        // Unit IGD
        $igd = OrganizationUnit::create([
            'name' => 'Unit IGD',
            'code' => 'IGD',
            'type_id' => $unitType->id,
            'parent_id' => $keperawatan->id,
            'description' => 'Instalasi Gawat Darurat',
            'is_active' => true,
        ]);

        // Manager IGD
        $managerIgd = $this->createUser('Nana Manager IGD', 'nana.igd', 'manager.igd@hospital.com', $managerRole->id, $igd->id);
        $igd->update(['head_id' => $managerIgd->id]);

        // Staff IGD (2 users)
        $this->createUser('Oscar Perawat IGD', 'oscar.igd', 'oscar.igd@hospital.com', $staffRole->id, $igd->id);
        $this->createUser('Putri Perawat IGD', 'putri.igd', 'putri.igd@hospital.com', $staffRole->id, $igd->id);
    }

    /**
     * Helper function to create a user
     */
    private function createUser(string $name, string $username, string $email, int $roleId, int $unitId): User
    {
        return User::create([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => Hash::make('password'),
            'role_id' => $roleId,
            'organization_unit_id' => $unitId,
        ]);
    }

    /**
     * Helper function to create user and unit together
     */
    private function createUserWithUnit(
        string $userName,
        string $username,
        string $email,
        string $unitName,
        string $unitCode,
        int $typeId,
        ?int $parentId,
        string $description,
        int $roleId
    ): array {
        // Create unit first
        $unit = OrganizationUnit::create([
            'name' => $unitName,
            'code' => $unitCode,
            'type_id' => $typeId,
            'parent_id' => $parentId,
            'description' => $description,
            'is_active' => true,
        ]);

        // Create user
        $user = User::create([
            'name' => $userName,
            'username' => $username,
            'email' => $email,
            'password' => Hash::make('password'),
            'role_id' => $roleId,
            'organization_unit_id' => $unit->id,
        ]);

        // Set user as head
        $unit->update(['head_id' => $user->id]);

        return ['user' => $user, 'unit' => $unit];
    }
}
