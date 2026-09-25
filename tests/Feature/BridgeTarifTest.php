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

        // View memakai @vite; test tidak bergantung pada hasil build frontend.
        $this->withoutVite();

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

    protected function headerExt(): array
    {
        return ['PROVID', 'SERVICECODE', 'SERVICECODE DESCRIPTION', 'SERVICECODE KELAS', 'KELAS', 'TARIFF', 'TOTAL BILLED', 'QUANTITY', 'NOTE'];
    }

    protected function tmpFile(array $rows, ?array $header = null): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(array_merge([$header ?? $this->header()], $rows), null, 'A1');
        $tmp = tempnam(sys_get_temp_dir(), 'bridge').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();

        return $tmp;
    }

    protected function upload(array $rows, ?array $header = null): UploadedFile
    {
        return new UploadedFile(
            $this->tmpFile($rows, $header), 'lama.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null, true
        );
    }

    /** Scan langsung dari file tersimpan (tanpa HTTP). */
    protected function scanRows(array $rows, ?array $header = null): array
    {
        $service = app(BridgeTarifService::class);
        $path = Storage::path($this->storeRaw($rows, $header));

        return $service->scanFile($path);
    }

    public function test_bridge_page_has_no_jenis_tarif_select(): void
    {
        $response = $this->actingAs($this->user)->get(route('bridge.index'));

        $response->assertOk();
        $response->assertDontSee('name="jenis_tarif_id"', false);
        $response->assertDontSee('Jenis Tarif');
    }

    public function test_form_and_result_pages_are_separated(): void
    {
        $index = $this->actingAs($this->user)->get(route('bridge.index'));
        $index->assertOk();
        $index->assertSee('Scan Excel', false);
        $index->assertDontSee('Hasil Mapping');

        $token = $this->scanOk([
            ['PRV1', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 100000, 'a'],
        ]);

        $result = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $result->assertOk();
        $result->assertSee('Hasil Mapping');
        $result->assertSee('Kembali');
        $result->assertDontSee('Scan Excel', false);
    }

    public function test_dashboard_shows_bridge_form_and_buku_tarif_shortcut(): void
    {
        $page = $this->actingAs($this->user)->get(route('dashboard'));
        $page->assertOk();
        $page->assertSee('Scan Excel', false);
        $page->assertSee('Buku Tarif');
        $page->assertSee(route('tarifs.index'), false);
    }

    public function test_dashboard_hides_bridge_for_users_without_permission(): void
    {
        $role = Role::create(['name' => 'user', 'display_name' => 'Pengguna']);
        $other = User::create([
            'name' => 'Biasa', 'email' => 'biasa@example.com',
            'password' => 'secret', 'role_id' => $role->id,
        ]);

        $page = $this->actingAs($other)->get(route('dashboard'));
        $page->assertOk();
        $page->assertDontSee('Scan Excel', false);
        $page->assertDontSee('Buku Tarif');
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

    protected function legacyHtmlFile(array $rows): UploadedFile
    {
        $cells = fn (array $r) => '<tr>'.implode('', array_map(fn ($c) => "<td>{$c}</td>", $r)).'</tr>';
        $html = '<html><body><table>'
            .'<tr><th>PROVID</th><th>SERVICECODE</th><th>SERVICECODE DESCRIPTION</th>'
            .'<th>SERVICECODE KELAS</th><th>KELAS</th><th>TARIFF</th><th>NOTE</th></tr>'
            .implode('', array_map($cells, $rows))
            .'</table></body></html>';
        $tmp = tempnam(sys_get_temp_dir(), 'bridge').'.xls';
        file_put_contents($tmp, $html);

        return new UploadedFile($tmp, 'lama.xls', 'text/html', null, true);
    }

    public function test_scan_accepts_legacy_html_xls(): void
    {
        $response = $this->actingAs($this->user)->post(route('bridge.scan'), ['file' => $this->legacyHtmlFile([
            ['PRV1', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 100000, 'a'],
            ['PRV1', 'OLD-USG', 'USG ABDOMEN', 'OLD-K1', 'VIP', 1, 'x'],
        ])]);
        $response->assertRedirect();
        $token = $this->extractToken($response);

        $page = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $page->assertOk();
        $page->assertSee('MATCHED');
        $page->assertSee('NOT_FOUND');
    }

    public function test_legacy_html_generate_preserves_other_columns(): void
    {
        $response = $this->actingAs($this->user)->post(route('bridge.scan'), ['file' => $this->legacyHtmlFile([
            ['PRV1', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 100000, 'catatan-a'],
        ])]);
        $response->assertRedirect();
        $token = $this->extractToken($response);

        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect(route('bridge.download', $token));

        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));
        $dl->assertOk();

        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);

        $row = array_values($sheet[1]);
        $this->assertSame('MRI001', $row[1]);
        $this->assertSame('MRI-K1', $row[3]);
        $this->assertSame('PRV1', $row[0]);
        $this->assertSame('catatan-a', $row[6]);
    }

    public function test_scan_rejects_html_without_table(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bridge').'.xls';
        file_put_contents($tmp, '<html><body><p>bukan tabel</p></body></html>');
        $file = new UploadedFile($tmp, 'lama.xls', 'text/html', null, true);

        $response = $this->actingAs($this->user)->post(route('bridge.scan'), ['file' => $file]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_scan_statuses(): void
    {
        $token = $this->scanOk([
            ['PRV1', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 100000, 'a'],
            ['PRV1', 'OLD-CT', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 200000, 'b'],
            ['PRV1', 'OLD-USG', 'USG ABDOMEN', 'OLD-K1', 'KELAS 1', 50000, 'c'],
            ['PRV1', 'OLD-X', '', 'OLD-K1', 'KELAS 1', 10000, 'd'],
        ]);

        $page = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $page->assertOk();
        $page->assertSee('MATCHED');
        $page->assertSee('AMBIGUOUS');
        $page->assertSee('NOT_FOUND');
        $page->assertSee('INVALID');
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
        $token = $this->scanOk([
            ['PRV1', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 100000, 'catatan-a'],
        ]);

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

        $token = $this->scanOk([
            ['PRV1', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 100000, 'a'],
            ['PRV1', 'OLD-USG', 'USG ABDOMEN', 'OLD-X', 'VIP', 1, 'x'],
        ]);
        $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);

        $this->assertSame($before, [Provider::count(), Service::count(), ServiceClass::count(), Tarif::count()]);
    }

    public function test_manual_resolve_applies_to_all_same_key_rows(): void
    {
        $token = $this->scanOk([
            ['PRV1', 'OLD-A', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 1, 'a'],
            ['PRV1', 'OLD-B', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 2, 'b'],
        ]);

        $resolved = $this->actingAs($this->user)->post(route('bridge.resolve'), [
            'token' => $token,
            'mapping_key' => 'CT SCAN HEAD|KELAS 1',
            'candidate' => 'CT002|CT-K1',
        ]);
        $resolved->assertRedirect(route('bridge.result', $token));

        $page = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $page->assertOk();
        $page->assertSee('CT002');

        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect();
        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));

        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);
        $this->assertSame('CT002', array_values($sheet[1])[1]);
        $this->assertSame('CT002', array_values($sheet[2])[1]);
    }

    public function test_manual_resolve_not_found_applies_to_all_same_key_rows(): void
    {
        $token = $this->scanOk([
            ['PRV1', 'OLD-A', 'USG ABDOMEN', 'OLD-K1', 'VIP', 1, 'a'],
            ['PRV1', 'OLD-B', 'USG ABDOMEN', 'OLD-K1', 'VIP', 2, 'b'],
            ['PRV1', 'OLD-C', 'X-RAY THORAX', 'OLD-K2', 'KELAS 2', 3, 'c'],
        ]);

        $page = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $page->assertOk();
        $page->assertSee('Not Found');

        // Tanpa kelas: kelas ikut bawaan Excel.
        $resolved = $this->actingAs($this->user)->post(route('bridge.resolve'), [
            'token' => $token,
            'mapping_key' => 'USG ABDOMEN|VIP',
            'candidate' => 'MRI001|',
        ]);
        $resolved->assertRedirect(route('bridge.result', $token));

        // Dengan override kelas yang valid di master.
        $resolved2 = $this->actingAs($this->user)->post(route('bridge.resolve'), [
            'token' => $token,
            'mapping_key' => 'X-RAY THORAX|KELAS 2',
            'candidate' => 'MRI001|MRI-K1',
        ]);
        $resolved2->assertRedirect(route('bridge.result', $token));

        $page = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $page->assertOk();
        $page->assertSee('MRI001');

        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect();
        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));

        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);
        $this->assertSame('MRI001', array_values($sheet[1])[1]);
        $this->assertSame('OLD-K1', array_values($sheet[1])[3]);
        $this->assertSame('MRI001', array_values($sheet[2])[1]);
        $this->assertSame('OLD-K1', array_values($sheet[2])[3]);
        $this->assertSame('MRI001', array_values($sheet[3])[1]);
        $this->assertSame('MRI-K1', array_values($sheet[3])[3]);
    }

    public function test_manual_resolve_rejects_unknown_pair(): void
    {
        $token = $this->scanOk([
            ['PRV1', 'OLD-A', 'USG ABDOMEN', 'OLD-K1', 'VIP', 1, 'a'],
        ]);

        $unknownService = $this->actingAs($this->user)->post(route('bridge.resolve'), [
            'token' => $token,
            'mapping_key' => 'USG ABDOMEN|VIP',
            'candidate' => 'NOPE|',
        ]);
        $unknownService->assertRedirect();
        $unknownService->assertSessionHas('error');

        $unknownPair = $this->actingAs($this->user)->post(route('bridge.resolve'), [
            'token' => $token,
            'mapping_key' => 'USG ABDOMEN|VIP',
            'candidate' => 'MRI001|NOPE',
        ]);
        $unknownPair->assertRedirect();
        $unknownPair->assertSessionHas('error');
    }

    public function test_manual_resolve_not_found_auto_fills_class_from_master(): void
    {
        ServiceClass::create(['code' => 'VIP-1', 'name' => 'VIP', 'status' => 'active']);

        $token = $this->scanOk([
            ['PRV1', 'OLD-A', 'USG ABDOMEN', 'OLD-K1', 'VIP', 1, 'a'],
            ['PRV1', 'OLD-B', 'USG KANDUNGAN', 'OLD-K9', 'TANPA KELAS', 2, 'b'],
        ]);

        $resolved = $this->actingAs($this->user)->post(route('bridge.resolve'), [
            'token' => $token,
            'mapping_key' => 'USG ABDOMEN|VIP',
            'candidate' => 'MRI001|',
        ]);
        $resolved->assertRedirect(route('bridge.result', $token));

        // Preview ikut terupdate: service dari pilihan, kelas dari master.
        $page = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $page->assertOk();
        $page->assertSee('VIP-1');

        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect();
        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));

        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);
        $this->assertSame('MRI001', array_values($sheet[1])[1]);
        $this->assertSame('VIP-1', array_values($sheet[1])[3]);
        // Row yang tidak dipetakan + kelas tak ada di master: utuh.
        $this->assertSame('OLD-B', array_values($sheet[2])[1]);
        $this->assertSame('OLD-K9', array_values($sheet[2])[3]);
    }

    public function test_manual_resolve_matches_class_by_code_when_name_unknown(): void
    {
        // Nama kelas tidak dikenal, tetapi kode lama sama dengan kode master.
        $token = $this->scanOk([
            ['PRV1', 'OLD-A', 'USG ABDOMEN', 'MRI-K1', 'Kelas Tak Dikenal', 1, 'a'],
        ]);

        $resolved = $this->actingAs($this->user)->post(route('bridge.resolve'), [
            'token' => $token,
            'mapping_key' => 'USG ABDOMEN|KELAS TAK DIKENAL',
            'candidate' => 'MRI001|',
        ]);
        $resolved->assertRedirect(route('bridge.result', $token));

        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect();
        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));

        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);
        $this->assertSame('MRI001', array_values($sheet[1])[1]);
        $this->assertSame('MRI-K1', array_values($sheet[1])[3]);
    }

    public function test_unresolved_rows_fill_class_code_from_master(): void
    {
        ServiceClass::create(['code' => 'VIP-1', 'name' => 'VIP', 'status' => 'active']);

        // Tanpa pemetaan manual apa pun: preview langsung menampilkan
        // kode kelas master untuk row AMBIGUOUS maupun NOT_FOUND.
        $token = $this->scanOk([
            ['PRV1', 'OLD-CT', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 200000, 'b'],
            ['PRV1', 'OLD-USG', 'USG ABDOMEN', 'OLD-K1', 'VIP', 1, 'x'],
        ]);

        $page = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $page->assertOk();
        $page->assertSee('MRI-K1');
        $page->assertSee('VIP-1');

        // Export: service tetap original, kelas mengikuti master.
        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect();
        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));

        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);
        $this->assertSame('OLD-CT', array_values($sheet[1])[1]);
        $this->assertSame('MRI-K1', array_values($sheet[1])[3]);
        $this->assertSame('OLD-USG', array_values($sheet[2])[1]);
        $this->assertSame('VIP-1', array_values($sheet[2])[3]);
    }

    public function test_generate_fills_empty_provider_columns_with_defaults(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['PROVID', 'PROVIDER_NAME', 'SERVICECODE', 'SERVICECODE DESCRIPTION', 'SERVICECODE KELAS', 'KELAS', 'TARIFF', 'NOTE'],
            ['', '', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 100000, 'a'],
            ['KEEPME', 'Keep Name', 'OLD-USG', 'USG ABDOMEN', 'OLD-K1', 'VIP', 1, 'x'],
        ], null, 'A1');
        $tmp = tempnam(sys_get_temp_dir(), 'bridge').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();
        $file = new UploadedFile($tmp, 'lama.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($this->user)->post(route('bridge.scan'), ['file' => $file]);
        $response->assertRedirect();
        $token = $this->extractToken($response);

        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect();
        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));

        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);

        // Kosong -> diisi default; mapping service tetap jalan.
        $this->assertSame('OAZRA0-000', array_values($sheet[1])[0]);
        $this->assertSame('RS AZRA', array_values($sheet[1])[1]);
        $this->assertSame('MRI001', array_values($sheet[1])[2]);
        // Sudah terisi -> tidak ditimpa.
        $this->assertSame('KEEPME', array_values($sheet[2])[0]);
        $this->assertSame('Keep Name', array_values($sheet[2])[1]);
    }

    public function test_search_services_endpoint(): void
    {
        // Satu kata: cocok per kata (kode "MRI001" memuat kata "mri").
        $found = $this->actingAs($this->user)->getJson(route('bridge.search-services', ['q' => 'mri']));
        $found->assertOk();
        $found->assertJsonFragment(['service_code' => 'MRI001']);

        // Banyak kata: yang paling mirip (cocok 2 kata) di urutan pertama.
        $multi = $this->actingAs($this->user)->getJson(route('bridge.search-services', ['q' => 'mri brain kontras']));
        $multi->assertOk();
        $multi->assertJsonPath('data.0.service_code', 'MRI001');

        // Tidak mirip sama sekali: tidak ditampilkan.
        $none = $this->actingAs($this->user)->getJson(route('bridge.search-services', ['q' => 'zzz-tidak-ada']));
        $none->assertOk();
        $none->assertExactJson(['data' => []]);

        $short = $this->actingAs($this->user)->getJson(route('bridge.search-services', ['q' => 'x']));
        $short->assertOk();
        $short->assertExactJson(['data' => []]);
    }

    public function test_search_services_similar_to_description(): void
    {
        // Tanpa ketikan: daftar terisi kandidat paling mirip description.
        $similar = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'Mri Brain Tanpa Kontras',
        ]));
        $similar->assertOk();
        $similarData = $similar->json('data');
        $this->assertNotEmpty($similarData);
        $this->assertSame('MRI001', $similarData[0]['service_code']);

        // Description tidak mirip apa pun: kosong.
        $none = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'zzz tidak ada di mana pun',
        ]));
        $none->assertOk();
        $none->assertExactJson(['data' => []]);

        // Ketikan ikut menyaring, urutan tetap paling mirip dulu.
        $filtered = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'Mri Brain',
            'q' => 'otak',
        ]));
        $filtered->assertOk();
        $filtered->assertJsonFragment(['service_code' => 'MRI001']);
        $filtered->assertJsonMissing(['service_code' => 'CT001']);
        $filtered->assertJsonMissing(['service_code' => 'CT002']);
    }

    public function test_search_ranks_by_words_phrase_and_all_tokens(): void
    {
        foreach ([
            ['KM001', 'Kamar Operasi & Sarana'],
            ['KM002', 'Kamar Operasi Bedah Anak'],
            ['KM003', 'Kamar Bedah'],
            ['KM004', 'Ruang Operasi'],
            ['KM005', 'Tindakan Medis Operasi'],
        ] as [$code, $name]) {
            Service::create(['code' => $code, 'name' => $name, 'description' => $name, 'status' => 'active']);
        }

        // Exact phrase dulu, lalu yang hanya memuat sebagian token.
        $res = $this->actingAs($this->user)->getJson(route('bridge.search-services', ['q' => 'kamar operasi']));
        $res->assertOk();
        $this->assertSame(
            ['KM001', 'KM002', 'KM003', 'KM004', 'KM005'],
            array_column($res->json('data'), 'service_code')
        );

        // 3 kata: yang memuat ketiganya paling atas.
        $three = $this->actingAs($this->user)->getJson(route('bridge.search-services', ['q' => 'kamar operasi anak']));
        $three->assertOk();
        $threeData = $three->json('data');
        $this->assertSame('KM002', $threeData[0]['service_code']);
        $this->assertSame('KM001', $threeData[1]['service_code']);

        // Case-insensitive + spasi ganda: hasil identik.
        $messy = $this->actingAs($this->user)->getJson(route('bridge.search-services', ['q' => '  KAMAR   OPERASI  ']));
        $messy->assertOk();
        $this->assertSame(
            ['KM001', 'KM002', 'KM003', 'KM004', 'KM005'],
            array_column($messy->json('data'), 'service_code')
        );
    }

    protected function seedKamarAnakServices(): void
    {
        foreach ([
            ['KO1', 'Kamar Operasi Anak'],
            ['KO2', 'Kamar Operasi Anak Bedah'],
            ['KO4', 'Anak Operasi Kamar'],
            ['KO3', 'Kamar Operasi'],
            ['KO5', 'Operasi Anak'],
            ['KO6', 'Ruang Operasi'],
            ['KO7', 'Ruang Bedah'],
        ] as [$code, $name]) {
            Service::create(['code' => $code, 'name' => $name, 'description' => $name, 'status' => 'active']);
        }
    }

    public function test_similar_ranks_exact_phrase_ordered_and_partial(): void
    {
        // A–F: exact(5) > frasa/urut(4) > acak(3) > sebagian besar(2) >
        // sebagian kecil(1); tanpa token cocok tidak tampil.
        $this->seedKamarAnakServices();

        $res = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'Kamar Operasi Anak',
        ]));
        $res->assertOk();
        $this->assertSame(
            ['KO1', 'KO2', 'KO4', 'KO3', 'KO5', 'KO6'],
            array_column($res->json('data'), 'service_code')
        );
    }

    public function test_similar_prefix_match_and_no_midword_false_positive(): void
    {
        // G: "mri" cocok dengan "MRI001" (prefix).
        $prefix = $this->actingAs($this->user)->getJson(route('bridge.search-services', ['q' => 'mri']));
        $prefix->assertOk();
        $prefixData = $prefix->json('data');
        $this->assertNotEmpty($prefixData);
        $this->assertSame('MRI001', $prefixData[0]['service_code']);

        // H: "tas" TIDAK cocok dengan "instalasi" (substring tengah).
        Service::create(['code' => 'INST01', 'name' => 'Instalasi Gizi', 'description' => 'Instalasi Gizi', 'status' => 'active']);
        $falsePositive = $this->actingAs($this->user)->getJson(route('bridge.search-services', ['q' => 'tas']));
        $falsePositive->assertOk();
        $falsePositive->assertExactJson(['data' => []]);
    }

    public function test_similar_keeps_numbers_and_exact_on_top(): void
    {
        // I: angka/ukuran ("22", "75x75") tetap dihitung; string identik
        // setelah normalisasi ("S-22" vs "S 22") tetap exact teratas,
        // 2/4 token di atas 1/4 token. Isi [...] diabaikan total.
        foreach ([
            ['SG001', 'Steri Green S-22 75x75'],
            ['SG002', 'Steri Green'],
            ['SG003', 'Green'],
            ['SG999', 'Paket Khusus Mata'],
        ] as [$code, $name]) {
            Service::create(['code' => $code, 'name' => $name, 'description' => $name, 'status' => 'active']);
        }

        // J: klik tanpa mengetik — daftar terisi mirip description.
        $res = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'Steri Green S-22 75x75 [Khusus Mata]',
        ]));
        $res->assertOk();
        $this->assertSame(
            ['SG001', 'SG002', 'SG003'],
            array_column($res->json('data'), 'service_code')
        );

        // L: query dihapus (q kosong) — kembali ke ranking description.
        $cleared = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'Steri Green S-22 75x75 [Khusus Mata]',
            'q' => '',
        ]));
        $cleared->assertOk();
        $this->assertSame(
            ['SG001', 'SG002', 'SG003'],
            array_column($cleared->json('data'), 'service_code')
        );
    }

    public function test_similar_excludes_bracket_content_from_scoring(): void
    {
        Service::create(['code' => 'KO1', 'name' => 'Kamar Operasi Anak', 'description' => 'Kamar Operasi Anak', 'status' => 'active']);
        Service::create(['code' => 'VVIP01', 'name' => 'VVIP Package', 'description' => 'VVIP Package', 'status' => 'active']);
        Service::create(['code' => 'KAT01', 'name' => 'Operasi Katarak', 'description' => 'Operasi Katarak', 'status' => 'active']);
        Service::create(['code' => 'MLM01', 'name' => 'Paket Malam VIP', 'description' => 'Paket Malam VIP', 'status' => 'active']);

        // Kata yang hanya ada di dalam [...] tidak boleh memengaruhi hasil.
        $res = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'Kamar Operasi Anak [VVIP]',
        ]));
        $res->assertOk();
        $codes = array_column($res->json('data'), 'service_code');
        $this->assertSame('KO1', $codes[0]);
        $this->assertNotContains('VVIP01', $codes);

        // Beberapa blok [...] sekaligus.
        $multi = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'Operasi Katarak [VIP] [Malam]',
        ]));
        $multi->assertOk();
        $multiCodes = array_column($multi->json('data'), 'service_code');
        $this->assertSame('KAT01', $multiCodes[0]);
        $this->assertNotContains('MLM01', $multiCodes);
    }

    public function test_similar_rejects_irrelevant_antebrachi_case(): void
    {
        // Kasus screenshot: isi [...] (nama dokter dkk) tidak boleh jadi
        // kata kunci; "Drainase Abses" dkk tidak punya token yang cocok
        // sehingga wajib tidak tampil (common token = 0).
        foreach ([
            ['DRN01', 'Drainase Abses - Dokter Anestesi'],
            ['DRN02', 'Drainase Abses Skrotum'],
            ['TND01', 'Tindakan Medis Operasi Bedah Anak'],
            ['ANS01', 'Anestesi Umum'],
            ['ANT01', 'Antebrachi Dextra'],
        ] as [$code, $name]) {
            Service::create(['code' => $code, 'name' => $name, 'description' => $name, 'status' => 'active']);
        }

        $res = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'ANTEBRACHI DEXTRA [Adhi Rommy Setyawan., dr., Sp. Rad]',
        ]));
        $res->assertOk();
        $codes = array_column($res->json('data'), 'service_code');
        $this->assertSame(['ANT01'], $codes);
    }

    public function test_similar_surshield_case_insensitive_and_rejects_short_token_noise(): void
    {
        // Kasus laporan: description Excel vs kandidat Surshield + noise
        // "III" (nama) dan "Ladd's Procedure" (artefak 1-huruf "s").
        foreach ([
            ['SUR20', 'Surshield Surflo II Safety', 'Surshield Surflo II Safety No. 20 Depot (Abocat 20) - JS'],
            ['SUR18', 'Surshield Surflo II Safety', 'Surshield Surflo II Safety No. 18 (Abocat 18)'],
            ['SUR22', 'Surshield Surflo II Safety', "Surshield Surflo II Safety No. 22'25 (Abocat 22)"],
            ['OKANK-A-043-016', 'Golongan Besar Khusus III', 'Tindakan Medis Operasi Bedah Anak'],
            ['LADD01', "Ladd's Procedure", "Ladd's Procedure"],
        ] as [$code, $name, $desc]) {
            Service::create(['code' => $code, 'name' => $name, 'description' => $desc, 'status' => 'active']);
        }
        $excelDesc = "Surshield Surflo II Safety No. 20'32 (TM061)";

        // Mode default (klik tanpa mengetik): kandidat relevan terurut,
        // noise tidak tampil.
        $res = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => $excelDesc,
        ]));
        $res->assertOk();
        $this->assertSame(
            ['SUR20', 'SUR18', 'SUR22'],
            array_column($res->json('data'), 'service_code')
        );

        // Case variants pada q HARUS menghasilkan hasil identik.
        $lists = [];
        foreach ([
            'surshield surflo safety',
            'SURSHIELD SURFLO SAFETY',
            'Surshield Surflo Safety',
            'SuRsHiElD SuRfLo SaFeTy',
        ] as $q) {
            $r = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
                'description' => $excelDesc,
                'q' => $q,
            ]));
            $r->assertOk();
            $lists[$q] = array_column($r->json('data'), 'service_code');
        }
        $this->assertSame(
            ['SUR20', 'SUR18', 'SUR22'],
            $lists['surshield surflo safety']
        );
        $this->assertSame($lists['surshield surflo safety'], $lists['SURSHIELD SURFLO SAFETY']);
        $this->assertSame($lists['surshield surflo safety'], $lists['Surshield Surflo Safety']);
        $this->assertSame($lists['surshield surflo safety'], $lists['SuRsHiElD SuRfLo SaFeTy']);

        // Query lengkap dengan "II": kandidat II/III-only tidak ikut.
        $full = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => $excelDesc,
            'q' => 'SURSHIELD SURFLO II SAFETY',
        ]));
        $full->assertOk();
        $fullCodes = array_column($full->json('data'), 'service_code');
        $this->assertContains('SUR20', $fullCodes);
        $this->assertContains('SUR18', $fullCodes);
        $this->assertContains('SUR22', $fullCodes);
        $this->assertNotContains('OKANK-A-043-016', $fullCodes);
        $this->assertNotContains('LADD01', $fullCodes);
    }

    public function test_search_services_suggest_ranks_by_class_and_tariff(): void
    {
        $provider = Provider::firstOrCreate(['code' => 'PRV1'], ['name' => 'Provider Satu', 'status' => 'active']);
        $makeService = fn (string $code, string $name, string $desc) => Service::create([
            'code' => $code, 'name' => $name, 'description' => $desc, 'status' => 'active',
        ]);
        $makeClass = fn (string $code, string $name) => ServiceClass::firstOrCreate(
            ['code' => $code], ['name' => $name, 'status' => 'active']
        );
        $makeTarif = function ($service, $class, float $amount) use ($provider) {
            return Tarif::create([
                'jenis_tarif_id' => $this->jenis->id,
                'provider_id' => $provider->id,
                'service_id' => $service->id,
                'class_id' => $class->id,
                'surgery_type' => null, 'helper' => null, 'tariff' => $amount,
                'valid_date_from' => '2024-01-01', 'end_date_to' => '2029-12-31',
            ]);
        };

        $sug1 = $makeService('SUG1', 'Infusan NS', 'Infusan NS 500 ml Sanbe');
        $sug2 = $makeService('SUG2', 'Infusan NS', 'Infusan NS 500 ml Otsuka');
        $vvip = $makeClass('KLV2', 'VVIP');
        $kelas3 = $makeClass('KLS3', 'KELAS 3');
        $makeTarif($sug1, $vvip, 36053);
        $makeTarif($sug1, $kelas3, 30000);
        $makeTarif($sug2, $vvip, 50000);

        // Konteks VVIP + 36053: pasangan kelas cocok + tarif persis teratas.
        $res = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'Infusan NS 500 ml',
            'class' => 'VVIP',
            'tariff' => 36053,
        ]));
        $res->assertOk();
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        $this->assertSame('SUG1', $data[0]['service_code']);
        $this->assertSame('KLV2', $data[0]['class_code']);
        $this->assertEquals(36053, $data[0]['tariff']);
        $this->assertSame('SUG2', $data[1]['service_code']);

        // Konteks KELAS 3 + 30000: pasangan kelas lain naik ke teratas.
        $res2 = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'Infusan NS 500 ml',
            'class' => 'KELAS 3',
            'tariff' => 30000,
        ]));
        $res2->assertOk();
        $data2 = $res2->json('data');
        $this->assertSame('SUG1', $data2[0]['service_code']);
        $this->assertSame('KLS3', $data2[0]['class_code']);

        // Tanpa konteks: perilaku lama persis (urutan similar, tanpa
        // field kelas/tarif).
        $plain = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'Infusan NS 500 ml',
        ]));
        $plain->assertOk();
        $plainData = $plain->json('data');
        $this->assertSame(['SUG1', 'SUG2'], array_column($plainData, 'service_code'));
        $this->assertArrayNotHasKey('tariff', $plainData[0]);
        $this->assertArrayNotHasKey('class_code', $plainData[0]);
    }

    public function test_notfound_group_carries_suggestions_to_modal_and_manual(): void
    {
        $provider = Provider::firstOrCreate(['code' => 'PRV1'], ['name' => 'Provider Satu', 'status' => 'active']);
        $sug1 = Service::create(['code' => 'SUG1', 'name' => 'Infusan NS', 'description' => 'Infusan NS 500 ml Sanbe', 'status' => 'active']);
        $sug2 = Service::create(['code' => 'SUG2', 'name' => 'Infusan NS', 'description' => 'Infusan NS 500 ml Otsuka', 'status' => 'active']);
        $vvip = ServiceClass::firstOrCreate(['code' => 'KLV2'], ['name' => 'VVIP', 'status' => 'active']);
        $kelas3 = ServiceClass::firstOrCreate(['code' => 'KLS3'], ['name' => 'KELAS 3', 'status' => 'active']);
        foreach ([[$sug1, $vvip, 36053], [$sug1, $kelas3, 30000], [$sug2, $vvip, 50000]] as [$svc, $cls, $amount]) {
            Tarif::create([
                'jenis_tarif_id' => $this->jenis->id,
                'provider_id' => $provider->id,
                'service_id' => $svc->id,
                'class_id' => $cls->id,
                'surgery_type' => null, 'helper' => null, 'tariff' => $amount,
                'valid_date_from' => '2024-01-01', 'end_date_to' => '2029-12-31',
            ]);
        }

        $result = $this->scanRows([
            ['PRV1', 'OLD-A', 'Infusan NS 500 ml', 'OLD-K1', 'VVIP', '', 36053, 1, 'a'],
        ], $this->headerExt());

        $this->assertSame(1, $result['summary']['not_found']);
        $group = $result['groups']['INFUSAN NS 500 ML|VVIP'];
        $this->assertTrue($group['manual']);
        $this->assertNotEmpty($group['suggestions']);
        $this->assertLessThanOrEqual(10, count($group['suggestions']));
        $this->assertSame('SUG1', $group['suggestions'][0]['service_code']);
        $this->assertSame('KLV2', $group['suggestions'][0]['class_code']);
        // Preview row membawa saran yang sama untuk modal analisa.
        $this->assertSame('NOT_FOUND', $result['preview'][0]['status']);
        $this->assertSame('SUG1', $result['preview'][0]['suggestions'][0]['service_code']);
        // Saran teratas langsung masuk New Code (status tetap NOT_FOUND).
        $this->assertSame('SUG1', $result['preview'][0]['new_service_code']);
        $this->assertTrue($result['preview'][0]['suggested_applied']);
        $this->assertSame(1, $result['summary']['suggested']);

        // Petakan Manual menampilkan saran yang bisa diklik.
        $token = $this->scanOk([
            ['PRV1', 'OLD-A', 'Infusan NS 500 ml', 'OLD-K1', 'VVIP', '', 36053, 1, 'a'],
        ], $this->headerExt());
        $page = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $page->assertOk();
        $page->assertSee('Saran (klik untuk memilih):', false);
        $page->assertSee('SUG1 | Infusan NS 500 ml Sanbe', false);
        $page->assertSee('(VVIP • Rp 36.053', false);
        $page->assertDontSee('(KLV2 • Rp 36.053', false);
    }

    public function test_search_services_suggest_prefers_relevant_description_over_closer_tariff(): void
    {
        // ROW 31: Bedah Tulang vs Splenectomy/Drainase yang tarifnya
        // justru lebih dekat — description relevan harus tetap di atas.
        $provider = Provider::firstOrCreate(['code' => 'PRV1'], ['name' => 'Provider Satu', 'status' => 'active']);
        $vvip = ServiceClass::firstOrCreate(['code' => 'KLV2'], ['name' => 'VVIP', 'status' => 'active']);
        $seed = function (string $code, string $name, string $desc, float $tariff) use ($provider, $vvip) {
            $service = Service::create(['code' => $code, 'name' => $name, 'description' => $desc, 'status' => 'active']);
            Tarif::create([
                'jenis_tarif_id' => $this->jenis->id,
                'provider_id' => $provider->id,
                'service_id' => $service->id,
                'class_id' => $vvip->id,
                'surgery_type' => null, 'helper' => null, 'tariff' => $tariff,
                'valid_date_from' => '2024-01-01', 'end_date_to' => '2029-12-31',
            ]);
        };
        $seed('BEDAH01', 'Tindakan Reposisi Fraktur', 'Reposisi Terbuka Fiksasi Interna Fraktur Tulang', 5900000);
        $seed('SPL01', 'Splenectomy', 'Splenectomy Bedah Minor', 5680000);
        $seed('DRA01', 'Drainase Abses', 'Drainase Abses Bedah Skrotum', 5700000);

        $res = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'Bedah Tulang Ortohopedi Reposisi Terbuka Fiksasi Interna Fraktur Panjang',
            'class' => 'VVIP',
            'tariff' => 5600000,
        ]));
        $res->assertOk();
        $codes = array_column($res->json('data'), 'service_code');

        $this->assertContains('BEDAH01', $codes);
        $this->assertContains('SPL01', $codes);
        $this->assertContains('DRA01', $codes);
        // Walau SPL01/DRA01 tarifnya lebih dekat (1,4%/1,8% vs 5,1%),
        // BEDAH01 yang description-nya relevan harus teratas.
        $this->assertSame('BEDAH01', $codes[0]);
    }

    public function test_search_services_suggest_row31_action_core_beats_closer_tariff(): void
    {
        // ROW 31: inti tindakan "Reposisi ... Fraktur Tulang Panjang"
        // harus menang atas Sphincterotomi yang tarifnya justru lebih
        // dekat (1,4% vs 6,7%/8,2%).
        $provider = Provider::firstOrCreate(['code' => 'PRV1'], ['name' => 'Provider Satu', 'status' => 'active']);
        $vvip = ServiceClass::firstOrCreate(['code' => 'KLV2'], ['name' => 'VVIP', 'status' => 'active']);
        $seed = function (string $code, string $name, string $desc, float $tariff) use ($provider, $vvip) {
            $service = Service::create(['code' => $code, 'name' => $name, 'description' => $desc, 'status' => 'active']);
            Tarif::create([
                'jenis_tarif_id' => $this->jenis->id,
                'provider_id' => $provider->id,
                'service_id' => $service->id,
                'class_id' => $vvip->id,
                'surgery_type' => null, 'helper' => null, 'tariff' => $tariff,
                'valid_date_from' => '2024-01-01', 'end_date_to' => '2029-12-31',
            ]);
        };
        $seed('OKORT-AN', 'Tindakan Reposisi Fraktur', 'Golongan Khusus 1 - Tindakan Medis Bedah Orthopedi - Reposisi Terbuka Dan Fiksasi Interna Fraktur Tulang Panjang - Dokter Anestesi', 6100000);
        $seed('OKORT-OP', 'Tindakan Reposisi Fraktur', 'Golongan Khusus 1 - Tindakan Medis Bedah Orthopedi - Reposisi Terbuka Dan Fiksasi Interna Fraktur Tulang Panjang - Dokter Operator', 6000000);
        $seed('SPL01', 'Sphincterotomi', 'Golongan Besar Khusus I - Tindakan Medis Operasi Bedah Anak - Sphincterotomi Internal - Dokter Anestesi', 5680000);

        $res = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'BEDAH TULANG / ORTOHOPEDI - Reposisi Terbuka Dan Fiksasi Interna Fraktur Tulang Panjang, anasthesy, (Puja Laksana Maqbul, dr., Sp.An., FIPM)',
            'class' => 'VVIP',
            'tariff' => 5600000,
        ]));
        $res->assertOk();
        $codes = array_column($res->json('data'), 'service_code');

        $this->assertContains('OKORT-AN', $codes);
        $this->assertContains('OKORT-OP', $codes);
        $this->assertNotContains('SPL01', $codes);
        // Di antara yang relevan, tarif terdekat (operator, 6jt) teratas.
        $this->assertSame('OKORT-OP', $codes[0]);
        $this->assertSame('OKORT-AN', $codes[1]);
    }

    public function test_search_services_suggest_noise_keywords_share_same_core(): void
    {
        // Varian noise (sedasi/operator/narkose dalam parens) harus
        // bermuara ke inti tindakan yang sama: OKORT teratas, SPL01 hilang.
        $provider = Provider::firstOrCreate(['code' => 'PRV1'], ['name' => 'Provider Satu', 'status' => 'active']);
        $vvip = ServiceClass::firstOrCreate(['code' => 'KLV2'], ['name' => 'VVIP', 'status' => 'active']);
        $seed = function (string $code, string $name, string $desc, float $tariff) use ($provider, $vvip) {
            $service = Service::create(['code' => $code, 'name' => $name, 'description' => $desc, 'status' => 'active']);
            Tarif::create([
                'jenis_tarif_id' => $this->jenis->id,
                'provider_id' => $provider->id,
                'service_id' => $service->id,
                'class_id' => $vvip->id,
                'surgery_type' => null, 'helper' => null, 'tariff' => $tariff,
                'valid_date_from' => '2024-01-01', 'end_date_to' => '2029-12-31',
            ]);
        };
        $seed('OKORT-OP', 'Tindakan Reposisi Fraktur', 'Golongan Khusus 1 - Tindakan Medis Bedah Orthopedi - Reposisi Terbuka Dan Fiksasi Interna Fraktur Tulang Panjang - Dokter Operator', 6000000);
        $seed('SPL01', 'Sphincterotomi', 'Golongan Besar Khusus I - Tindakan Medis Operasi Bedah Anak - Sphincterotomi Internal - Dokter Anestesi', 5680000);

        foreach ([
            'Reposisi Terbuka Fiksasi Interna Fraktur Tulang dengan sedasi',
            'Bedah Tulang - Reposisi Terbuka Fiksasi Interna Fraktur, Operator',
            'Reposisi Terbuka Fiksasi Interna Fraktur Tulang (narkose)',
        ] as $desc) {
            $res = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
                'description' => $desc,
                'class' => 'VVIP',
                'tariff' => 5600000,
            ]));
            $res->assertOk();
            $codes = array_column($res->json('data'), 'service_code');
            $this->assertSame('OKORT-OP', $codes[0], "Gagal untuk description: $desc");
            $this->assertNotContains('SPL01', $codes, "Noise lolos untuk description: $desc");
        }
    }

    public function test_notfound_consensus_code_goes_to_new_code_and_generate(): void
    {
        // Satu-satunya kandidat (3 kelas, 1 kode) -> konsensus CON1.
        $provider = Provider::firstOrCreate(['code' => 'PRV1'], ['name' => 'Provider Satu', 'status' => 'active']);
        $service = Service::create(['code' => 'CON1', 'name' => 'Cortical Screw', 'description' => 'Cortical Screw Test 18 mm', 'status' => 'active']);
        foreach ([['KLV2', 'VVIP', 180264], ['KLS1', 'KELAS 1', 174048], ['KLS2', 'KELAS 2', 174048]] as [$cc, $cn, $amount]) {
            $class = ServiceClass::firstOrCreate(['code' => $cc], ['name' => $cn, 'status' => 'active']);
            Tarif::create([
                'jenis_tarif_id' => $this->jenis->id,
                'provider_id' => $provider->id,
                'service_id' => $service->id,
                'class_id' => $class->id,
                'surgery_type' => null, 'helper' => null, 'tariff' => $amount,
                'valid_date_from' => '2024-01-01', 'end_date_to' => '2029-12-31',
            ]);
        }

        $rows = [['PRV1', 'OLD-A', 'CORTICAL SCREW TEST 18 (OST-22104-0139)', 'OLD-K1', 'VVIP', 277639, 277639, 1, 'a']];
        $result = $this->scanRows($rows, $this->headerExt());
        $row = $result['preview'][0];

        // Konsensus kode saran -> status valid (MATCHED), fitur saran +
        // modal + grup manual tetap dipertahankan.
        $this->assertSame('MATCHED', $row['status']);
        $this->assertSame('CON1', $row['new_service_code']);
        $this->assertTrue($row['suggested_applied']);
        $this->assertSame(1, $result['summary']['matched']);
        $this->assertSame(0, $result['summary']['not_found']);
        $this->assertSame(1, $result['summary']['suggested']);
        $this->assertSame('MATCHED', $result['decisions'][2]['status']);
        $this->assertTrue($result['decisions'][2]['suggested_applied']);
        $this->assertSame('CON1', $result['decisions'][2]['new_service_code']);
        $this->assertNotEmpty($row['suggestions']);
        $this->assertArrayHasKey('CORTICAL SCREW TEST 18 (OST-22104-0139)|VVIP', $result['groups']);

        // Generate menulis kode konsensus, TARIFF asli tidak berubah.
        $token = $this->scanOk($rows, $this->headerExt());
        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect(route('bridge.download', $token));

        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));
        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);
        $outRow = array_values($sheet[1]);
        $this->assertSame('CON1', $outRow[1]);
        $this->assertEquals(277639, $outRow[5]);
    }

    public function test_similar_rejects_midword_substring_despite_like_recall(): void
    {
        // LIKE %operasi% mengenai "Praoperasional", tetapi rank kata = 0
        // sehingga tetap dibuang (recall -> rank -> filter).
        Service::create(['code' => 'PRA01', 'name' => 'Praoperasional Minor', 'description' => 'Praoperasional Minor', 'status' => 'active']);

        $res = $this->actingAs($this->user)->getJson(route('bridge.search-services', [
            'description' => 'Operasi',
        ]));
        $res->assertOk();
        $res->assertExactJson(['data' => []]);
    }

    public function test_ambiguous_options_hide_name_duplicated_from_group_description(): void
    {
        // CT003: nama persis == description grup -> nama tidak diulang.
        // CT001: nama beda -> nama tetap ditampilkan pembeda.
        $this->createMaster('CT003', 'CT Kepala', 'CT Scan Kepala X', 'CT-K1', 'KELAS 1');

        $token = $this->scanOk([
            ['PRV1', 'OLD-CT', 'CT Kepala', 'OLD-K1', 'KELAS 1', 100000, 'b'],
        ]);

        $page = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $page->assertOk();
        $page->assertSee('CT003 | CT-K1 | Rp 100.000', false);
        $page->assertDontSee('CT003 | CT Kepala', false);
        $page->assertSee('CT001 | CT SCAN HEAD | CT-K1 | Rp 100.000', false);
    }

    public function test_resolve_batch_applies_multiple_groups_at_once(): void
    {
        $token = $this->scanOk([
            ['PRV1', 'OLD-A', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 1, 'a'],
            ['PRV1', 'OLD-B', 'USG ABDOMEN', 'OLD-K1', 'VIP', 2, 'b'],
        ]);

        $batch = $this->actingAs($this->user)->post(route('bridge.resolve-batch'), [
            'token' => $token,
            'rows' => [
                ['key' => 'CT SCAN HEAD|KELAS 1', 'candidate' => 'CT001|CT-K1'],
                ['key' => 'USG ABDOMEN|VIP', 'candidate' => 'MRI001|'],
                ['key' => 'USG ABDOMEN|VIP', 'candidate' => ''],
            ],
        ]);
        $batch->assertRedirect(route('bridge.result', $token));

        $page = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $page->assertOk();
        $page->assertSee('CT001');
        $page->assertSee('MRI001');

        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect();
        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));
        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);
        $this->assertSame('CT001', array_values($sheet[1])[1]);
        $this->assertSame('MRI001', array_values($sheet[2])[1]);
        $this->assertSame('OLD-K1', array_values($sheet[2])[3]);
    }

    public function test_resolve_batch_rejects_empty_selection(): void
    {
        $token = $this->scanOk([
            ['PRV1', 'OLD-A', 'USG ABDOMEN', 'OLD-K1', 'VIP', 1, 'a'],
        ]);

        $batch = $this->actingAs($this->user)->post(route('bridge.resolve-batch'), [
            'token' => $token,
            'rows' => [['key' => 'USG ABDOMEN|VIP', 'candidate' => '']],
        ]);
        $batch->assertRedirect();
        $batch->assertSessionHas('error');
    }

    public function test_result_page_is_refresh_safe(): void
    {
        $token = $this->scanOk([
            ['PRV1', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 100000, 'a'],
        ]);

        $this->actingAs($this->user)->get(route('bridge.result', $token))->assertOk();
        // Refresh berulang tetap GET 200 (tidak 405).
        $this->actingAs($this->user)->get(route('bridge.result', $token))->assertOk();

        $expired = $this->actingAs($this->user)->get(route('bridge.result', '0123456789abcdef0123456789abcdef'));
        $expired->assertRedirect(route('bridge.index'));
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

    public function test_ambiguous_stays_ambiguous_with_suggestion_and_analysis(): void
    {
        // Bedakan tarif kedua master ambigu agar ada pemenang tarif.
        $ct2 = Service::where('code', 'CT002')->firstOrFail();
        Tarif::where('service_id', $ct2->id)->update(['tariff' => 250000]);

        $service = app(BridgeTarifService::class);
        $path = Storage::path($this->storeRaw([
            ['PRV1', 'OLD-CT', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 250000, 'b'],
        ]));

        $result = $service->scanFile($path);

        $this->assertSame(1, $result['summary']['ambiguous']);
        $this->assertSame(0, $result['summary']['matched']);

        $row = $result['preview'][0];
        $this->assertSame('AMBIGUOUS', $row['status']);
        // Kandidat terurut: pemenang tarif di urutan pertama.
        $this->assertSame('CT002', $row['candidates'][0]['service_code']);
        // Rekomendasi ada tapi status TETAP ambiguous (tidak auto-matched).
        $this->assertSame('CT002', $row['suggested']['service_code'] ?? null);
        // Saran langsung masuk ke new code.
        $this->assertTrue($row['suggested_applied']);
        $this->assertSame('CT002', $row['new_service_code']);
        $this->assertSame('CT-K1', $row['new_class_code']);
        $this->assertSame(1, $result['summary']['suggested']);
        $this->assertNotEmpty($row['analysis']);
        $this->assertStringContainsString('AMBIGUOUS', $row['analysis']);
    }

    public function test_generate_applies_ambiguous_suggestion_to_output(): void
    {
        $ct2 = Service::where('code', 'CT002')->firstOrFail();
        Tarif::where('service_id', $ct2->id)->update(['tariff' => 250000]);

        $token = $this->scanOk([
            ['PRV1', 'OLD-CT', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 250000, 'b'],
        ]);

        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect(route('bridge.download', $token));

        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));
        $dl->assertOk();

        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);
        $row = array_values($sheet[1]);
        $this->assertSame('CT002', $row[1]);
        $this->assertSame('CT-K1', $row[3]);
    }

    public function test_ambiguous_exact_tariff_match_suggests_despite_narrow_gap(): void
    {
        // Kasus nyata: best cocok persis 0% (36053), runner-up hanya
        // 4,91pp di belakang (34282) — di bawah gap normal 5pp, tapi
        // cocok persis cukup dengan gap >= 1pp sehingga ada saran.
        $ct1 = Service::where('code', 'CT001')->firstOrFail();
        $ct2 = Service::where('code', 'CT002')->firstOrFail();
        Tarif::where('service_id', $ct1->id)->update(['tariff' => 36053]);
        Tarif::where('service_id', $ct2->id)->update(['tariff' => 34282]);

        $service = app(BridgeTarifService::class);
        $path = Storage::path($this->storeRaw([
            ['PRV1', 'OLD-CT', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 36053, 'b'],
        ]));

        $result = $service->scanFile($path);
        $row = $result['preview'][0];

        $this->assertSame('AMBIGUOUS', $row['status']);
        $this->assertSame('CT001', $row['suggested']['service_code'] ?? null);
        $this->assertSame('CT001', $row['new_service_code']);
    }

    public function test_ambiguous_without_clear_winner_has_analysis_but_no_suggestion(): void
    {
        // Kedua master CT bertarif sama (100000) -> seri, tanpa saran.
        $service = app(BridgeTarifService::class);
        $path = Storage::path($this->storeRaw([
            ['PRV1', 'OLD-CT', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 200000, 'b'],
        ]));

        $result = $service->scanFile($path);
        $row = $result['preview'][0];

        $this->assertSame('AMBIGUOUS', $row['status']);
        $this->assertCount(2, $row['candidates']);
        $this->assertNull($row['suggested']);
        $this->assertNotEmpty($row['analysis']);
    }

    public function test_notfound_preview_has_analysis(): void
    {
        $service = app(BridgeTarifService::class);
        $path = Storage::path($this->storeRaw([
            ['PRV1', 'OLD-USG', 'USG ABDOMEN', 'OLD-K1', 'KELAS 1', 50000, 'c'],
        ]));

        $result = $service->scanFile($path);
        $row = $result['preview'][0];

        $this->assertSame('NOT_FOUND', $row['status']);
        $this->assertSame([], $row['candidates']);
        $this->assertNotEmpty($row['analysis']);
        $this->assertStringContainsString('NOT_FOUND', $row['analysis']);
    }

    public function test_result_page_shows_analysis_buttons(): void
    {
        $token = $this->scanOk([
            ['PRV1', 'OLD-CT', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', 200000, 'b'],
            ['PRV1', 'OLD-USG', 'USG ABDOMEN', 'OLD-K1', 'KELAS 1', 50000, 'c'],
        ]);

        $page = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $page->assertOk();
        $page->assertSee('data-ba-open', false);
        $page->assertSee('Klik baris berstatus', false);
        $page->assertSee('data-ba-store', false);
        $page->assertSee('Perbandingan Kandidat', false);
        // Tarif Excel terbawa ke store modal analisa.
        $page->assertSee('200000', false);
        $page->assertSee('Tarif Excel', false);
        // Kolom presentase rekomendasi + sinyal tarif per saran.
        $page->assertSee('Rekomendasi', false);
        $page->assertSee('tariff_diff', false);
        $page->assertSee('class_match', false);
    }

    public function test_effective_tariff_prioritizes_excel_tariff(): void
    {
        $result = $this->scanRows([
            ['PRV1', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 100000, 200000, 2, 'a'],
        ], $this->headerExt());
        $row = $result['preview'][0];

        $this->assertSame(100000.0, $row['tariff']);
        $this->assertSame(100000.0, $row['effective_tariff']);
        $this->assertSame('excel', $row['tariff_source']);
    }

    public function test_effective_tariff_computed_from_total_billed_divided_by_quantity(): void
    {
        $result = $this->scanRows([
            ['PRV1', 'OLD-A', 'USG ABDOMEN', 'OLD-K1', 'VIP', '', 63898, 2, 'a'],
        ], $this->headerExt());
        $row = $result['preview'][0];

        $this->assertNull($row['tariff']);
        $this->assertSame(31949.0, $row['effective_tariff']);
        $this->assertSame('total_billed_quantity', $row['tariff_source']);
    }

    public function test_effective_tariff_null_when_quantity_zero_or_negative(): void
    {
        $result = $this->scanRows([
            ['PRV1', 'OLD-A', 'USG ABDOMEN', 'OLD-K1', 'VIP', '', 63898, 0, 'a'],
            ['PRV1', 'OLD-B', 'USG ABDOMEN', 'OLD-K1', 'VIP', '', 63898, -2, 'b'],
            ['PRV1', 'OLD-C', 'USG ABDOMEN', 'OLD-K1', 'VIP', '', 0, 2, 'c'],
        ], $this->headerExt());

        foreach ($result['preview'] as $row) {
            $this->assertNull($row['effective_tariff']);
            $this->assertNull($row['tariff_source']);
        }
    }

    public function test_effective_tariff_null_when_total_billed_missing(): void
    {
        $result = $this->scanRows([
            ['PRV1', 'OLD-A', 'USG ABDOMEN', 'OLD-K1', 'VIP', '', '', 2, 'a'],
        ], $this->headerExt());
        $row = $result['preview'][0];

        $this->assertNull($row['effective_tariff']);
        $this->assertNull($row['tariff_source']);
    }

    public function test_ambiguous_uses_effective_tariff_for_suggestion_but_stays_ambiguous(): void
    {
        $ct1 = Service::where('code', 'CT001')->firstOrFail();
        $ct2 = Service::where('code', 'CT002')->firstOrFail();
        Tarif::where('service_id', $ct1->id)->update(['tariff' => 31949]);
        Tarif::where('service_id', $ct2->id)->update(['tariff' => 60703]);

        $result = $this->scanRows([
            ['PRV1', 'OLD-CT', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', '', 63898, 2, 'b'],
        ], $this->headerExt());
        $row = $result['preview'][0];

        $this->assertSame('AMBIGUOUS', $row['status']);
        $this->assertSame(31949.0, $row['effective_tariff']);
        $this->assertSame('total_billed_quantity', $row['tariff_source']);
        $this->assertSame('CT001', $row['suggested']['service_code'] ?? null);
        $this->assertSame('CT001', $row['new_service_code']);
        $this->assertTrue($row['suggested_applied']);
    }

    public function test_notfound_keeps_effective_tariff_and_mentions_it_in_analysis(): void
    {
        $result = $this->scanRows([
            ['PRV1', 'OLD-A', 'USG ABDOMEN', 'OLD-K1', 'VIP', '', 50000, 2, 'a'],
        ], $this->headerExt());
        $row = $result['preview'][0];

        $this->assertSame('NOT_FOUND', $row['status']);
        $this->assertSame(25000.0, $row['effective_tariff']);
        $this->assertSame('total_billed_quantity', $row['tariff_source']);
        $this->assertStringContainsStringIgnoringCase('tarif efektif', $row['analysis']);
        $this->assertStringContainsString('TOTAL BILLED', $row['analysis']);
    }

    public function test_notfound_without_any_tariff_keeps_old_behavior(): void
    {
        $result = $this->scanRows([
            ['PRV1', 'OLD-A', 'USG ABDOMEN', 'OLD-K1', 'VIP', '', 'a'],
        ]);
        $row = $result['preview'][0];

        $this->assertSame('NOT_FOUND', $row['status']);
        $this->assertNull($row['effective_tariff']);
        $this->assertNull($row['tariff_source']);
        $this->assertStringContainsStringIgnoringCase('tidak tersedia', $row['analysis']);
    }

    public function test_duplicate_mapping_key_rows_keep_own_effective_tariff(): void
    {
        $result = $this->scanRows([
            ['PRV1', 'OLD-A', 'INFUSAN NACL 100 ML SANBE', 'OLD-K1', 'VVIP', '', 63898, 2, 'a'],
            ['PRV1', 'OLD-B', 'INFUSAN NACL 100 ML SANBE', 'OLD-K1', 'VVIP', 31949, 31949, 1, 'b'],
            ['PRV1', 'OLD-C', 'INFUSAN NACL 100 ML SANBE', 'OLD-K1', 'VVIP', '', 63898, 2, 'c'],
        ], $this->headerExt());

        $this->assertCount(3, $result['preview']);
        [$a, $b, $c] = $result['preview'];

        $this->assertSame(31949.0, $a['effective_tariff']);
        $this->assertSame('total_billed_quantity', $a['tariff_source']);
        $this->assertSame(31949.0, $b['effective_tariff']);
        $this->assertSame('excel', $b['tariff_source']);
        $this->assertSame(31949.0, $c['effective_tariff']);
        $this->assertSame('total_billed_quantity', $c['tariff_source']);
        // Nilai asli tidak diubah.
        $this->assertNull($a['tariff']);
        $this->assertSame(31949.0, $b['tariff']);
    }

    public function test_group_tariff_fallback_when_row_has_no_tariff_at_all(): void
    {
        $result = $this->scanRows([
            ['PRV1', 'OLD-A', 'INFUSAN NACL 100 ML SANBE', 'OLD-K1', 'VVIP', 50000, 50000, 1, 'a'],
            ['PRV1', 'OLD-B', 'INFUSAN NACL 100 ML SANBE', 'OLD-K1', 'VVIP', '', '', '', 'b'],
        ], $this->headerExt());
        [$a, $b] = $result['preview'];

        $this->assertSame('excel', $a['tariff_source']);
        $this->assertSame(50000.0, $b['effective_tariff']);
        $this->assertSame('group', $b['tariff_source']);
        $this->assertNull($b['tariff']);
    }

    public function test_number_formats_and_quantity_parsing(): void
    {
        $this->assertSame(31949.0, \App\Services\Bridge\BridgeTarifRowNormalizer::parseTariff('31.949'));
        $this->assertSame(1250000.0, \App\Services\Bridge\BridgeTarifRowNormalizer::parseTariff('1.250.000'));
        $this->assertSame(1250000.5, \App\Services\Bridge\BridgeTarifRowNormalizer::parseTariff('1.250.000,50'));
        $this->assertSame(1250000.0, \App\Services\Bridge\BridgeTarifRowNormalizer::parseTariff('1,250,000.00'));
        $this->assertSame(63898.0, \App\Services\Bridge\BridgeTarifRowNormalizer::parseTariff('63.898'));
        $this->assertSame(2.0, \App\Services\Bridge\BridgeTarifRowNormalizer::parseQuantity('2'));
        $this->assertSame(2.5, \App\Services\Bridge\BridgeTarifRowNormalizer::parseQuantity('2.5'));
        $this->assertSame(1.5, \App\Services\Bridge\BridgeTarifRowNormalizer::parseQuantity('1,5'));
        $this->assertNull(\App\Services\Bridge\BridgeTarifRowNormalizer::parseQuantity('0'));
        $this->assertNull(\App\Services\Bridge\BridgeTarifRowNormalizer::parseQuantity('-1'));
        $this->assertNull(\App\Services\Bridge\BridgeTarifRowNormalizer::parseQuantity(''));

        $result = $this->scanRows([
            ['PRV1', 'OLD-A', 'USG ABDOMEN', 'OLD-K1', 'VIP', '', 100000, 2.5, 'a'],
        ], $this->headerExt());

        $this->assertEqualsWithDelta(40000.0, $result['preview'][0]['effective_tariff'], 0.001);
    }

    public function test_real_case_infusan_nacl_effective_tariffs(): void
    {
        $result = $this->scanRows([
            ['PRV1', 'OLD-A', 'Infusan Nacl 100 ml Sanbe', '2', 'VVIP', '', 63898, 2, 'a'],
            ['PRV1', 'OLD-B', 'Infusan Nacl 100 ml Sanbe', '2', 'VVIP', 31949, 31949, 1, 'b'],
            ['PRV1', 'OLD-C', 'Infusan NS (Cairan Nacl 500 ml) Sanbe', '2', 'VVIP', '', 72106, 2, 'c'],
        ], $this->headerExt());
        [$a, $b, $c] = $result['preview'];

        $this->assertSame(31949.0, $a['effective_tariff']);
        $this->assertSame('total_billed_quantity', $a['tariff_source']);
        $this->assertSame(31949.0, $b['effective_tariff']);
        $this->assertSame('excel', $b['tariff_source']);
        $this->assertSame(36053.0, $c['effective_tariff']);
        $this->assertSame('total_billed_quantity', $c['tariff_source']);
    }

    public function test_result_page_modal_shows_effective_tariff_source(): void
    {
        $token = $this->scanOk([
            ['PRV1', 'OLD-CT', 'CT SCAN HEAD', 'OLD-K1', 'KELAS 1', '', 200000, 2, 'b'],
            ['PRV1', 'OLD-USG', 'USG ABDOMEN', 'OLD-K1', 'KELAS 1', '', 50000, 2, 'c'],
        ], $this->headerExt());

        $page = $this->actingAs($this->user)->get(route('bridge.result', $token));
        $page->assertOk();
        $page->assertSee('Tarif Efektif', false);
        $page->assertSee('total_billed_quantity', false);
        $page->assertSee('TOTAL BILLED', false);
    }

    public function test_generate_does_not_change_original_tariff_column(): void
    {
        $token = $this->scanOk([
            ['PRV1', 'OLD-MRI', 'MRI BRAIN', 'OLD-K1', 'KELAS 1', 31949, 31949, 1, 'a'],
            ['PRV1', 'OLD-USG', 'USG ABDOMEN', 'OLD-K1', 'VIP', '', 50000, 2, 'b'],
        ], $this->headerExt());

        $gen = $this->actingAs($this->user)->post(route('bridge.generate'), ['token' => $token]);
        $gen->assertRedirect(route('bridge.download', $token));

        $dl = $this->actingAs($this->user)->get(route('bridge.download', $token));
        $dl->assertOk();

        $out = tempnam(sys_get_temp_dir(), 'bout').'.xlsx';
        file_put_contents($out, $dl->streamedContent() ?: $dl->getContent());
        $sheet = IOFactory::load($out)->getActiveSheet()->toArray(null, true, true, false);

        $matched = array_values($sheet[1]);
        $notFound = array_values($sheet[2]);
        // SERVICECODE berubah (MATCHED), TARIFF asli tetap.
        $this->assertSame('MRI001', $matched[1]);
        $this->assertEquals(31949, $matched[5]);
        // NOT_FOUND: SERVICECODE lama tetap, TARIFF kosong tetap kosong.
        $this->assertSame('OLD-USG', $notFound[1]);
        $this->assertTrue($notFound[5] === null || $notFound[5] === '');
    }

    protected function storeRaw(array $rows, ?array $header = null): string
    {
        $filename = 'bridge-inputs/'.uniqid('raw', true).'.xlsx';
        Storage::put($filename, file_get_contents($this->tmpFile($rows, $header)));

        return $filename;
    }

    protected function extractToken(\Illuminate\Testing\TestResponse $response): string
    {
        // Alur PRG: token ada di Location header hasil redirect scan.
        $location = $response->headers->get('Location', '');
        if (is_string($location) && preg_match('#/bridge/result/([A-Za-z0-9]{16,64})$#', $location, $m)) {
            return $m[1];
        }

        $content = $response->getContent();
        $this->assertIsString($content);
        preg_match('/name="token" value="([a-f0-9]{32})"/', $content, $m);

        $this->assertNotEmpty($m[1] ?? null, 'Token bridge tidak ditemukan di halaman hasil.');

        return $m[1];
    }

    protected function scanOk(array $rows, ?array $header = null): string
    {
        $response = $this->actingAs($this->user)->post(route('bridge.scan'), ['file' => $this->upload($rows, $header)]);
        $response->assertRedirect();

        return $this->extractToken($response);
    }
}
