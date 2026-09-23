<?php

namespace Database\Seeders;

use App\Models\JenisTarif;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceClass;
use App\Models\Tarif;
use Illuminate\Database\Seeder;

class TarifManagementSeeder extends Seeder
{
    public function run(): void
    {
        $jenisTarifs = [
            ['code' => 'tarif', 'name' => 'Tarif', 'status' => 'active'],
            ['code' => 'obat', 'name' => 'Obat', 'status' => 'active'],
            ['code' => 'alkes', 'name' => 'Alkes', 'status' => 'active'],
            ['code' => 'bhp', 'name' => 'BHP', 'status' => 'active'],
            ['code' => 'makanan', 'name' => 'Makanan', 'status' => 'active'],
        ];

        foreach ($jenisTarifs as $jenis) {
            JenisTarif::firstOrCreate(['code' => $jenis['code']], $jenis);
        }

        $providers = [
            ['code' => 'PRV001', 'name' => 'PT Asuransi Sehat', 'status' => 'active'],
            ['code' => 'PRV002', 'name' => 'BPJS Kesehatan', 'status' => 'active'],
            ['code' => 'PRV003', 'name' => 'PT Asuransi Jiwa', 'status' => 'inactive'],
        ];

        foreach ($providers as $provider) {
            Provider::firstOrCreate(['code' => $provider['code']], $provider);
        }

        $services = [
            ['code' => 'SV001', 'name' => 'Konsultasi Dokter', 'description' => 'Konsultasi dokter umum', 'status' => 'active'],
            ['code' => 'SV002', 'name' => 'Rawat Inap', 'description' => 'Layanan rawat inap pasien', 'status' => 'active'],
            ['code' => 'OBT001', 'name' => 'Paracetamol 500mg', 'description' => 'Obat penurun demam', 'status' => 'active'],
            ['code' => 'ALK001', 'name' => 'Infus Set', 'description' => 'Alat kesehatan infus set dewasa', 'status' => 'active'],
        ];

        foreach ($services as $service) {
            Service::firstOrCreate(['code' => $service['code']], $service);
        }

        $classes = [
            ['code' => 'VIP', 'name' => 'VIP', 'status' => 'active'],
            ['code' => 'KLS1', 'name' => 'Kelas 1', 'status' => 'active'],
            ['code' => 'KLS2', 'name' => 'Kelas 2', 'status' => 'active'],
            ['code' => 'KLS3', 'name' => 'Kelas 3', 'status' => 'active'],
        ];

        foreach ($classes as $class) {
            ServiceClass::firstOrCreate(['code' => $class['code']], $class);
        }

        // Contoh tarif mencakup ketiga status otomatis: ACTIVE, UPCOMING, EXPIRED
        $tarifs = [
            [
                'jenis_tarif' => 'tarif',
                'provider_code' => 'PRV001',
                'service_code' => 'SV001',
                'class_code' => 'VIP',
                'surgery_type' => 'NON SURGERY',
                'helper' => null,
                'tariff' => 150000,
                'valid_date_from' => now()->subMonth()->toDateString(),
                'end_date_to' => now()->addMonths(11)->toDateString(),
            ],
            [
                'jenis_tarif' => 'obat',
                'provider_code' => 'PRV002',
                'service_code' => 'OBT001',
                'class_code' => 'KLS1',
                'surgery_type' => 'NON SURGERY',
                'helper' => 'Apoteker',
                'tariff' => 5000,
                'valid_date_from' => now()->addMonth()->toDateString(),
                'end_date_to' => now()->addYear()->toDateString(),
            ],
            [
                'jenis_tarif' => 'alkes',
                'provider_code' => 'PRV001',
                'service_code' => 'ALK001',
                'class_code' => 'KLS2',
                'surgery_type' => 'SURGERY',
                'helper' => null,
                'tariff' => 75000,
                'valid_date_from' => now()->subYear()->toDateString(),
                'end_date_to' => now()->subDay()->toDateString(),
            ],
        ];

        foreach ($tarifs as $tarif) {
            $jenisId = JenisTarif::where('code', $tarif['jenis_tarif'])->value('id');
            $exists = Tarif::where('jenis_tarif_id', $jenisId)
                ->where('provider_id', Provider::where('code', $tarif['provider_code'])->value('id'))
                ->where('service_id', Service::where('code', $tarif['service_code'])->value('id'))
                ->where('class_id', ServiceClass::where('code', $tarif['class_code'])->value('id'))
                ->exists();

            if ($exists) {
                continue;
            }

            Tarif::create([
                'jenis_tarif_id' => $jenisId,
                'provider_id' => Provider::where('code', $tarif['provider_code'])->value('id'),
                'service_id' => Service::where('code', $tarif['service_code'])->value('id'),
                'class_id' => ServiceClass::where('code', $tarif['class_code'])->value('id'),
                'surgery_type' => $tarif['surgery_type'],
                'helper' => $tarif['helper'],
                'tariff' => $tarif['tariff'],
                'valid_date_from' => $tarif['valid_date_from'],
                'end_date_to' => $tarif['end_date_to'],
            ]);
        }
    }
}
