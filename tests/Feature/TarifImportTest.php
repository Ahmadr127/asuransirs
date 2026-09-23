<?php

namespace Tests\Feature;

use App\Http\Services\TarifImportService;
use App\Models\JenisTarif;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceClass;
use App\Models\Tarif;
use App\Services\TarifImport\TarifMasterResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Catatan: tidak memakai RefreshDatabase karena salah satu migration
 * existing (add_username_to_users_table) memakai CONCAT() khas MySQL yang
 * tidak jalan di sqlite :memory:. Test ini membuat hanya 5 tabel tarif
 * secara manual agar tidak menyentuh migration existing.
 */
class TarifImportTest extends TestCase
{
    protected TarifImportService $service;

    protected JenisTarif $jenis;

    protected Provider $provider;

    protected Service $serviceModel;

    protected ServiceClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTarifTables();
        $this->truncateTarifTables();

        $this->jenis = JenisTarif::create(['code' => 'obat', 'name' => 'Obat', 'status' => 'active']);
        $this->provider = Provider::create(['code' => 'PRV001', 'name' => 'Provider Satu', 'status' => 'active']);
        $this->serviceModel = Service::create(['code' => 'SVC001', 'name' => 'Service Satu', 'description' => 'Desc', 'status' => 'active']);
        $this->class = ServiceClass::create(['code' => 'KLS1', 'name' => 'Kelas 1', 'status' => 'active']);

        $this->service = new TarifImportService(new TarifMasterResolver);
    }

    protected function createTarifTables(): void
    {
        if (! Schema::hasTable('providers')) {
            Schema::create('providers', function ($table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('services')) {
            Schema::create('services', function ($table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('classes')) {
            Schema::create('classes', function ($table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('jenis_tarifs')) {
            Schema::create('jenis_tarifs', function ($table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('tarifs')) {
            Schema::create('tarifs', function ($table) {
                $table->id();
                $table->foreignId('jenis_tarif_id')->nullable()->constrained('jenis_tarifs');
                $table->foreignId('provider_id')->constrained('providers');
                $table->foreignId('service_id')->constrained('services');
                $table->foreignId('class_id')->constrained('classes');
                $table->string('surgery_type')->nullable();
                $table->string('helper')->nullable();
                $table->decimal('tariff', 15, 2);
                $table->date('valid_date_from');
                $table->date('end_date_to');
                $table->timestamps();
            });
        }
    }

    protected function truncateTarifTables(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        foreach (['tarifs', 'jenis_tarifs', 'providers', 'services', 'classes'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function makeFile(array $rows): string
    {
        $header = [
            'PROVID', 'PROVIDER_NAME', 'SERVICECODE', 'SERVICECODE DESCRIPTION',
            'SERVICECODE_KELAS', 'KELAS',
            'RUANG BEDAH (SURGERY)/NON RUANG BEDAH (NON SURGERY)',
            'HELPER', 'TARIFF', 'VALID DATE FROM', 'END DATE TO',
        ];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(array_merge([$header], $rows), null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'tarif').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    protected function validRow(array $over = []): array
    {
        return array_replace(
            ['PRV001', 'Provider Satu', 'SVC001', 'Desc Satu', 'KLS1', 'Kelas 1', 'SURGERY', 'Dr. Andi', 150000, '2024-01-01', '2024-12-31'],
            $over
        );
    }

    public function test_scan_counts_valid_error_and_missing_master(): void
    {
        $path = $this->makeFile([
            $this->validRow(),
            // SERVICECODE tidak ada di master -> direncanakan dibuat (WARNING, bukan ERROR).
            $this->validRow([2 => 'UNKNOWN']),
            // TARIFF bukan numeric.
            $this->validRow([8 => 'rusak']),
        ]);

        $result = $this->service->scan($path, $this->jenis->id);

        $this->assertSame(3, $result['total_rows']);
        $this->assertSame(1, $result['valid_rows']);
        $this->assertSame(1, $result['warning_rows']);
        $this->assertSame(1, $result['error_rows']);
        $this->assertSame(0, $result['service_missing']);
        $this->assertSame(1, $result['service_will_create']);
        $this->assertCount(3, $result['preview']);
        // Scan tidak boleh menulis DB.
        $this->assertSame(1, Provider::count());
        $this->assertSame(1, Service::count());
        $this->assertSame(0, Tarif::count());
    }

    public function test_scan_detects_duplicate_in_file_and_database(): void
    {
        Tarif::create([
            'jenis_tarif_id' => $this->jenis->id,
            'provider_id' => $this->provider->id,
            'service_id' => $this->serviceModel->id,
            'class_id' => $this->class->id,
            'surgery_type' => 'SURGERY', 'helper' => null, 'tariff' => 150000,
            'valid_date_from' => '2024-01-01', 'end_date_to' => '2024-12-31',
        ]);

        $path = $this->makeFile([$this->validRow(), $this->validRow()]);

        $result = $this->service->scan($path, $this->jenis->id);

        // Baris 1 duplikat DB, baris 2 duplikat dalam file (atau DB juga).
        $this->assertSame(2, $result['duplicate_rows']);
        $this->assertSame(0, $result['valid_rows']);
    }

    public function test_commit_inserts_valid_and_skips_rest(): void
    {
        $path = $this->makeFile([
            $this->validRow(),
            // Master baru otomatis dibuat + tarifnya ikut masuk.
            $this->validRow([2 => 'UNKNOWN']),
            $this->validRow(), // duplikat dalam file
        ]);

        $stats = $this->service->commit($path, $this->jenis->id);

        $this->assertSame(3, $stats['total_rows']);
        $this->assertSame(2, $stats['inserted']);
        $this->assertSame(2, Tarif::count());
        // Master baru tidak duplikat.
        $this->assertSame(1, Service::where('code', 'UNKNOWN')->count());

        $tarif = Tarif::whereHas('service', fn ($q) => $q->where('code', 'SVC001'))->first();
        $this->assertSame($this->jenis->id, $tarif->jenis_tarif_id);
        $this->assertSame($this->provider->id, $tarif->provider_id);
        $this->assertSame($this->serviceModel->id, $tarif->service_id);
        $this->assertSame($this->class->id, $tarif->class_id);
        $this->assertSame('150000.00', (string) $tarif->tariff);
    }

    public function test_commit_is_idempotent_on_rerun(): void
    {
        $path = $this->makeFile([$this->validRow()]);

        $first = $this->service->commit($path, $this->jenis->id);
        $second = $this->service->commit($path, $this->jenis->id);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, $second['skipped_duplicate']);
        $this->assertSame(1, Tarif::count());
    }

    public function test_staged_scan_matches_single_scan(): void
    {
        $path = $this->makeFile([
            $this->validRow(),
            $this->validRow([2 => 'UNKNOWN']),
            $this->validRow([8 => 'rusak']),
            $this->validRow(), // duplikat baris 1
        ]);

        $ref = $this->service->scan($path, $this->jenis->id);

        $init = $this->service->initScan($path, $this->jenis->id, 'staged.xlsx');
        $this->assertArrayNotHasKey('fatal', $init);
        $this->assertSame(4, $init['total_rows']);

        $state = $this->service->freshScanState('staged.xlsx', null, [], ['map' => $init['header_map'], 'missing' => [], 'unknown' => [], 'valid' => true]);
        $state['summary']['jenis_tarif_id'] = $init['jenis_tarif_id'];
        $state['summary']['jenis_tarif_name'] = $init['jenis_tarif_name'];
        $state['summary']['header'] = $init['header'];

        $existing = $this->service->existingKeysFor($this->jenis->id);

        // Slice 2 baris agar duplikat lintas-slice ikut teruji.
        $processed = 0;
        while ($processed < $init['total_rows']) {
            $raw = $this->service->readSlice($path, $processed + 2, $processed + 3);
            if ($raw === []) {
                break;
            }
            $this->service->processSlice($raw, $init['header_map'], $processed + 2, $existing, $state);
            $processed = $state['summary']['processed'];
        }

        $result = $this->service->finalizeScanState($state, 'tok');

        foreach (['total_rows', 'valid_rows', 'warning_rows', 'error_rows', 'duplicate_rows', 'duplicate_in_file', 'duplicate_in_db', 'provider_found', 'provider_missing', 'service_found', 'service_missing', 'class_found', 'class_missing', 'provider_will_create', 'service_will_create', 'class_will_create', 'new_provider_total', 'new_service_total', 'new_class_total'] as $key) {
            $this->assertSame($ref[$key], $result[$key], "mismatch {$key}");
        }
        $this->assertEquals($ref['new_providers'], $result['new_providers']);
        $this->assertEquals($ref['new_services'], $result['new_services']);
        $this->assertEquals($ref['new_classes'], $result['new_classes']);
        $this->assertSame($ref['jenis_tarif_id'], $result['jenis_tarif_id']);
        $this->assertCount(count($ref['preview']), $result['preview']);
    }

    public function test_init_scan_rejects_bad_header(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['KOLOM', 'NGAWUR']], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'tarif').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $init = $this->service->initScan($path, $this->jenis->id, 'bad.xlsx');

        $this->assertArrayHasKey('fatal', $init);
    }

    public function test_scan_allows_empty_surgery_and_helper(): void
    {
        $path = $this->makeFile([
            // Bedah "-" + Helper "-" => null, tetap VALID.
            $this->validRow([6 => '-', 7 => '-']),
            // Bedah kosong + Helper kosong => null, tetap VALID (duplikat beda helper? tidak — key sama kecuali helper tidak masuk key, jadi baris 2 duplikat).
            // Pakai tanggal beda agar tidak duplikat.
            array_replace($this->validRow(), [6 => '', 7 => '', 9 => '2025-01-01', 10 => '2025-12-31']),
            // Bedah ngawur tetap ERROR.
            $this->validRow([6 => 'ngawur']),
        ]);

        $result = $this->service->scan($path, $this->jenis->id);

        $this->assertSame(3, $result['total_rows']);
        $this->assertSame(2, $result['valid_rows']);
        $this->assertSame(1, $result['error_rows']);
        $this->assertNull($result['preview'][0]['surgery_type']);
        $this->assertNull($result['preview'][0]['helper']);
        $this->assertNull($result['preview'][1]['surgery_type']);
    }

    public function test_commit_auto_creates_empty_masters_without_duplicates(): void
    {
        $path = $this->makeFile([
            $this->validRow([0 => 'OAZRA0-000', 1 => 'RS AZRA', 2 => 'VK01', 3 => 'Senam Hamil', 4 => 'KLS1', 5 => 'Kelas 1', 6 => '-', 7 => '-']),
            $this->validRow([0 => 'OAZRA0-000', 1 => 'RS AZRA', 2 => 'VK01', 3 => 'Senam Hamil', 4 => 'KLS1', 5 => 'Kelas 1', 6 => '-', 7 => '-', 9 => '2025-01-01', 10 => '2025-12-31']),
            // Casing beda tidak boleh gandakan master.
            $this->validRow([0 => 'oazra0-000', 1 => 'RS AZRA', 2 => 'vk01', 3 => 'Senam Hamil', 4 => 'kls1', 5 => 'Kelas 1', 6 => '', 7 => '', 9 => '2026-01-01', 10 => '2026-12-31']),
        ]);

        $stats = $this->service->commit($path, $this->jenis->id);

        $this->assertSame(3, $stats['inserted']);
        $this->assertSame(1, Provider::whereRaw('UPPER(code) = ?', ['OAZRA0-000'])->count());
        $this->assertSame(1, Service::whereRaw('UPPER(code) = ?', ['VK01'])->count());
        $this->assertNull(Tarif::orderBy('id')->first()->surgery_type);
        $this->assertNull(Tarif::orderBy('id')->first()->helper);
    }

    public function test_scan_reports_unique_candidates_not_per_row(): void
    {
        // 4 row PROVID sama + 2 service + 2 kelas => unik 1/2/2.
        $path = $this->makeFile([
            $this->validRow([0 => 'OAZRA0-000', 1 => 'RS AZRA', 2 => 'VK01', 3 => 'Senam Hamil', 4 => 'KLS1', 5 => 'Kelas 1', 9 => '2024-01-01', 10 => '2024-12-31']),
            $this->validRow([0 => 'OAZRA0-000', 1 => 'RS AZRA', 2 => 'VK01', 3 => 'Senam Hamil', 4 => 'KLX', 5 => 'Suite', 9 => '2024-01-01', 10 => '2024-12-31']),
            $this->validRow([0 => 'OAZRA0-000', 1 => 'RS AZRA', 2 => 'VK02', 3 => 'Yoga Prenatal', 4 => 'KLS1', 5 => 'Kelas 1', 9 => '2024-01-01', 10 => '2024-12-31']),
            $this->validRow([0 => 'OAZRA0-000', 1 => 'RS AZRA', 2 => 'VK02', 3 => 'Yoga Prenatal', 4 => 'KLX', 5 => 'Suite', 9 => '2024-01-01', 10 => '2024-12-31']),
        ]);
        // Kosongkan master agar semua kandidat baru.
        DB::table('tarifs')->delete();
        DB::table('providers')->delete();
        DB::table('services')->delete();
        DB::table('classes')->delete();

        $result = $this->service->scan($path, $this->jenis->id);

        $this->assertSame(1, $result['new_provider_total']);
        $this->assertSame(2, $result['new_service_total']);
        $this->assertSame(2, $result['new_class_total']);
        $this->assertCount(1, $result['new_providers']);
        $this->assertSame('OAZRA0-000', $result['new_providers'][0]['code']);
        // Status WARNING (bukan ERROR) untuk master baru.
        foreach ($result['preview'] as $row) {
            $this->assertSame('WARNING', $row['status']);
            $this->assertStringContainsString('baru akan dibuat', implode(' ', $row['messages']));
        }
        // SCAN tidak menulis database.
        $this->assertSame(0, Provider::count());
        $this->assertSame(0, Service::count());
        $this->assertSame(0, ServiceClass::count());
        $this->assertSame(0, Tarif::count());
    }

    public function test_scan_does_not_insert_and_import_creates_once(): void
    {
        DB::table('tarifs')->delete();
        DB::table('providers')->delete();
        DB::table('services')->delete();
        DB::table('classes')->delete();

        $path = $this->makeFile([
            $this->validRow([0 => 'OAZRA0-000', 1 => 'RS AZRA', 2 => 'VK01', 3 => 'Senam Hamil', 4 => 'KLS1', 5 => 'Kelas 1']),
            $this->validRow([0 => 'OAZRA0-000', 1 => 'RS AZRA', 2 => 'VK01', 3 => 'Senam Hamil', 4 => 'KLX', 5 => 'Suite']),
            $this->validRow([0 => 'OAZRA0-000', 1 => 'RS AZRA', 2 => 'VK02', 3 => 'Yoga Prenatal', 4 => 'KLS1', 5 => 'Kelas 1']),
        ]);

        $scan = $this->service->scan($path, $this->jenis->id);
        $this->assertSame(0, Provider::count());
        $this->assertSame(0, Tarif::count());

        $stats = $this->service->commit($path, $this->jenis->id);

        $this->assertSame(1, Provider::count());
        $this->assertSame(2, Service::count());
        $this->assertSame(2, ServiceClass::count());
        $this->assertSame(3, Tarif::count());
        $this->assertSame(3, $stats['inserted']);
        // Semua tarif memakai jenis_tarif pilihan user.
        foreach (Tarif::all() as $tarif) {
            $this->assertSame($this->jenis->id, $tarif->jenis_tarif_id);
        }
    }
}
