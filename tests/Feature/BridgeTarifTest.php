<?php

namespace Tests\Feature;

use App\Models\JenisTarif;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceClass;
use App\Models\Tarif;
use App\Models\User;
use App\Services\Bridge\BridgeTarifService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BridgeTarifTest extends TestCase
{
    protected JenisTarif $jenis;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();
        $this->createTables();
        $this->truncateTables();

        $this->jenis = JenisTarif::create(['code' => 'tarif', 'name' => 'Tarif', 'status' => 'active']);

        $role = Role::create(['name' => 'admin', 'display_name' => 'Admin']);
        $permission = Permission::create(['name' => 'manage_tarifs', 'display_name' => 'Manage Tarifs']);
        $role->permissions()->attach($permission->id);
        $this->user = User::create([
            'name' => 'Tester', 'email' => 'tester@example.com',
            'password' => 'secret', 'role_id' => $role->id,
        ]);

        // Master: MRI001|MRI BRAIN + MRI-K1|KELAS 1 (tunggal -> MATCHED).
        $this->createMaster('MRI001', 'MRI BRAIN', 'MRI Otak Tanpa Kontras', 'MRI-K1', 'KELAS 1');
        // Master: CT001 + CT002 berbagi DESCRIPTION + KELAS ( -> AMBIGUOUS).
        $this->createMaster('CT001', 'CT SCAN HEAD', 'CT Kepala', 'CT-K1', 'KELAS 1');
        $this->createMaster('CT002', 'CT SCAN HEAD', 'CT Kepala Kontras', 'CT-K1', 'KELAS 1');
    }

    protected function createTables(): void
    {
        foreach ([
            'roles' => fn ($t) => [$t->id(), $t->string('name')->unique(), $t->string('display_name'), $t->text('description')->nullable(), $t->timestamps()],
        ] as $table => $def) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $def);
            }
        }
        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function ($table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('role_permission')) {
            Schema::create('role_permission', function ($table) {
                $table->id();
                $table->foreignId('role_id')->constrained()->onDelete('cascade');
                $table->foreignId('permission_id')->constrained()->onDelete('cascade');
                $table->timestamps();
                $table->unique(['role_id', 'permission_id']);
            });
        }
        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->foreignId('role_id')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }
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

    protected function truncateTables(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        foreach (['tarifs', 'jenis_tarifs', 'providers', 'services', 'classes', 'users', 'roles', 'permissions', 'role_permission'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function createMaster(string $serviceCode, string $serviceName, string $serviceDesc, string $classCode, string $className): void
    {
        $provider = Provider::firstOrCreate(['code' => 'PRV1'], ['name' => 'Provider Satu', 'status' => 'active']);
        $service = Service::create(['code' => $serviceCode, 'name' => $serviceName, 'description' => $serviceDesc, 'status' => 'active']);
        $class = ServiceClass::firstOrCreate(['code' => $classCode], ['name' => $className, 'status' => 'active']);
        Tarif::create([
            'jenis_tarif_id' => $this->jenis->id,
            'provider_id' => $provider->id,
            'service_id' => $service->id,
            'class_id' => $class->id,
            'surgery_type' => null, 'helper' => null, 'tariff' => 100000,
            'valid_date_from' => '2024-01-01', 'end_date_to' => '2029-12-31',
        ]);
    }

    protected function header(): array
    {
        return ['PROVID', 'SERVICECODE', 'SERVICECODE DESCRIPTION', 'SERVICECODE KELAS', 'KELAS', 'TARIFF', 'NOTE'];
    }

    protected function tmpFile(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(array_merge([$this->header()], $rows), null, 'A1');
        $tmp = tempnam(sys_get_temp_dir(), 'bridge').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();

        return $tmp;
    }

    protected function upload(array $rows): UploadedFile
    {
        return new UploadedFile(
            $this->tmpFile($rows), 'lama.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null, true
        );
    }

    public function test_bridge_page_has_no_jenis_tarif_select(): void
    {
        $response = $this->actingAs($this->user)->get(route('bridge.index'));

        $response->assertOk();
        $response->assertDontSee('name="jenis_tarif_id"', false);
        $response->assertDontSee('Jenis Tarif');
    }

    public function test_scan_requires_file(): void
    {
        $response = $this->actingAs($this->user)->post(route('bridge.scan'), []);

        $response->assertRedirect();
        $response->assertSessionHasErrors('file');
    }

    public function test_scan_rejects_invalid_header(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['KOLOM', 'NGAWUR']], null, 'A1');
        $tmp = tempnam(sys_get_temp_dir(), 'bridge').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();
        $file = new UploadedFile($tmp, 'lama.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($this->user)->post(route('bridge.scan'), ['file' => $file]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_scan_statuses(): void
    {
        $response = $this->actingAs($this->user)->post(route('bridge.scan'), ['file' => $this->upload([
            ['PRV1', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 100000, 'a'],
            ['PRV1', 'OLD-CT', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 200000, 'b'],
            ['PRV1', 'OLD-USG', 'USG ABDOMEN', 'OLD-K1', 'KELAS 1', 50000, 'c'],
            ['PRV1', 'OLD-X', '', 'OLD-K1', 'KELAS 1', 10000, 'd'],
        ])]);

        $response->assertOk();
        $response->assertSee('MATCHED');
        $response->assertSee('AMBIGUOUS');
        $response->assertSee('NOT_FOUND');
        $response->assertSee('INVALID');
    }

    public function test_matching_is_case_and_whitespace_insensitive(): void
    {
        $service = app(BridgeTarifService::class);
        $path = Storage::path($this->storeRaw([
            ['PRV1', 'OLD1', '  mri   brain ', 'OLD-K1', '  kelas  1  ', 1, 'x'],
            ['PRV1', 'OLD2', 'Mri Brain', 'OLD-K1', 'Kelas 1', 1, 'y'],
        ]));

        $result = $service->scanFile($path);

        $this->assertSame(2, $result['summary']['matched']);
        foreach ($result['decisions'] as $decision) {
            $this->assertSame('MRI001', $decision['new_service_code']);
            $this->assertSame('MRI-K1', $decision['new_class_code']);
        }
    }

    public function test_generate_changes_only_mapping_columns(): void
    {
        $response = $this->actingAs($this->user)->post(route('bridge.scan'), ['file' => $this->upload([
            ['PRV1', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 100000, 'catatan-a'],
        ])]);

        $response->assertOk();
        $token = $this->extractToken($response);

        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect(route('bridge.download', $token));

        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));
        $dl->assertOk();

        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);
        $this->assertSame($this->header(), array_values($sheet[0]));
        $this->assertCount(2, $sheet);

        $row = array_values($sheet[1]);
        $this->assertSame('MRI001', $row[1]);
        $this->assertSame('MRI BRAIN', $row[2]);
        $this->assertSame('MRI-K1', $row[3]);
        $this->assertSame('KELAS 1', $row[4]);
        $this->assertSame('PRV1', $row[0]);
        $this->assertSame('catatan-a', $row[6]);
    }

    public function test_scan_and_generate_create_no_masters(): void
    {
        $before = [Provider::count(), Service::count(), ServiceClass::count(), Tarif::count()];

        $response = $this->actingAs($this->user)->post(route('bridge.scan'), ['file' => $this->upload([
            ['PRV1', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 100000, 'a'],
            ['PRV1', 'OLD-USG', 'USG ABDOMEN', 'OLD-X', 'VIP', 1, 'x'],
        ])]);
        $response->assertOk();
        $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $this->extractToken($response)]);

        $this->assertSame($before, [Provider::count(), Service::count(), ServiceClass::count(), Tarif::count()]);
    }

    public function test_manual_resolve_applies_to_all_same_key_rows(): void
    {
        $response = $this->actingAs($this->user)->post(route('bridge.scan'), ['file' => $this->upload([
            ['PRV1', 'OLD-A', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 1, 'a'],
            ['PRV1', 'OLD-B', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 2, 'b'],
        ])]);
        $response->assertOk();
        $token = $this->extractToken($response);

        $resolved = $this->actingAs($this->user)->post(route('bridge.resolve'), [
            'token' => $token,
            'mapping_key' => 'CT SCAN HEAD|KELAS 1',
            'candidate' => 'CT002|CT-K1',
        ]);
        $resolved->assertOk();
        $resolved->assertSee('CT002');

        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect();
        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));

        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);
        $this->assertSame('CT002', array_values($sheet[1])[1]);
        $this->assertSame('CT002', array_values($sheet[2])[1]);
    }

    public function test_scan_avoids_per_row_queries(): void
    {
        $service = app(BridgeTarifService::class);
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = ['PRV1', 'OLD'.$i, 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 1, 'x'];
        }
        $path = Storage::path($this->storeRaw($rows));

        $count = 0;
        $sqls = [];
        DB::listen(function ($e) use (&$count, &$sqls) {
            $count++;
            $sqls[] = substr($e->sql, 0, 140);
        });
        $service->scanFile($path);

        $this->assertLessThanOrEqual(5, $count);
    }

    protected function storeRaw(array $rows): string
    {
        $filename = 'bridge-inputs/'.uniqid('raw', true).'.xlsx';
        Storage::put($filename, file_get_contents($this->tmpFile($rows)));

        return $filename;
    }

    protected function extractToken(\Illuminate\Testing\TestResponse $response): string
    {
        $content = $response->getContent();
        $this->assertIsString($content);
        preg_match('/name="token" value="([a-f0-9]{32})"/', $content, $m);

        $this->assertNotEmpty($m[1] ?? null, 'Token bridge tidak ditemukan di halaman hasil.');

        return $m[1];
    }
}
