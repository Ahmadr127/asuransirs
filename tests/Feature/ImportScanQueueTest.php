<?php

namespace Tests\Feature;

use App\Http\Services\TarifImportService;
use App\Jobs\ProcessTarifImport;
use App\Jobs\ScanTarifImport;
use App\Models\ImportBatch;
use App\Models\JenisTarif;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceClass;
use App\Models\Tarif;
use App\Models\User;
use App\Services\TarifImport\TarifMasterResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Scan async: UPLOAD -> QUEUE SCAN -> PREVIEW -> QUEUE IMPORT.
 * HTTP tidak menjalankan proses Excel berat; worker satu-satunya
 * tempat processing. Tanpa hard limit jumlah row.
 */
class ImportScanQueueTest extends TestCase
{
    protected TarifImportService $service;

    protected JenisTarif $jenis;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // View memakai @vite; test tidak bergantung pada hasil build frontend.
        $this->withoutVite();

        Storage::fake('local');
        $this->createTables();
        $this->truncateTables();

        $this->jenis = JenisTarif::create(['code' => 'obat', 'name' => 'Obat', 'status' => 'active']);

        $role = Role::create(['name' => 'admin', 'display_name' => 'Admin']);
        $permission = Permission::create(['name' => 'manage_tarifs', 'display_name' => 'Manage Tarifs']);
        $role->permissions()->attach($permission->id);
        $this->user = User::create([
            'name' => 'Tester', 'email' => 'tester@example.com',
            'password' => 'secret', 'role_id' => $role->id,
        ]);

        $this->service = new TarifImportService(new TarifMasterResolver);
    }

    protected function createTables(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function ($table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name');
                $table->text('description')->nullable();
                $table->timestamps();
            });
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
        if (! Schema::hasTable('import_batches')) {
            Schema::create('import_batches', function ($table) {
                $table->id();
                $table->foreignId('user_id')->nullable();
                $table->string('filename');
                $table->string('path');
                $table->foreignId('jenis_tarif_id')->nullable()->constrained('jenis_tarifs');
                $table->string('status', 20)->default('pending_scan');
                $table->unsignedBigInteger('total_rows')->default(0);
                $table->unsignedBigInteger('processed_rows')->default(0);
                $table->unsignedBigInteger('inserted')->default(0);
                $table->unsignedBigInteger('skipped_duplicate')->default(0);
                $table->unsignedBigInteger('skipped_error')->default(0);
                $table->unsignedBigInteger('providers_created')->default(0);
                $table->unsignedBigInteger('services_created')->default(0);
                $table->unsignedBigInteger('classes_created')->default(0);
                $table->text('error_message')->nullable();
                $table->unsignedBigInteger('scan_total_rows')->default(0);
                $table->unsignedBigInteger('scan_processed_rows')->default(0);
                $table->json('scan_summary')->nullable();
                $table->json('scan_candidates')->nullable();
                $table->json('scan_preview')->nullable();
                $table->json('scan_errors')->nullable();
                $table->text('scan_error_message')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function truncateTables(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        foreach (['tarifs', 'import_batches', 'jenis_tarifs', 'providers', 'services', 'classes', 'users', 'roles', 'permissions', 'role_permission'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function header(): array
    {
        return [
            'PROVID', 'PROVIDER_NAME', 'SERVICECODE', 'SERVICECODE DESCRIPTION',
            'SERVICECODE_KELAS', 'KELAS',
            'RUANG BEDAH (SURGERY)/NON RUANG BEDAH (NON SURGERY)',
            'HELPER', 'TARIFF', 'VALID DATE FROM', 'END DATE TO',
        ];
    }

    protected function validRow(array $over = []): array
    {
        return array_replace(
            ['OAZRA0-000', 'RS AZRA', 'VK01', 'Senam Hamil', 'KLS1', 'Kelas 1', '-', '-', 30000, '2026-09-01', '2099-12-31'],
            $over
        );
    }

    /** Simpan xlsx ke fake storage, kembalikan path storage. */
    protected function storeFile(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(array_merge([$this->header()], $rows), null, 'A1');
        $tmp = tempnam(sys_get_temp_dir(), 'tarif').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();

        $filename = 'tarif-imports/'.uniqid('tarif', true).'.xlsx';
        Storage::put($filename, file_get_contents($tmp));

        return $filename;
    }

    protected function upload(string $tmpPath): UploadedFile
    {
        return new UploadedFile(
            $tmpPath, 'tarif.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null, true
        );
    }

    protected function makeBatch(array $rows, string $status = 'pending_scan'): ImportBatch
    {
        return ImportBatch::create([
            'user_id' => $this->user->id,
            'filename' => 'tarif.xlsx',
            'path' => $this->storeFile($rows),
            'jenis_tarif_id' => $this->jenis->id,
            'status' => $status,
        ]);
    }

    public function test_scan_endpoint_dispatches_job_without_sync_scan(): void
    {
        Queue::fake();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$this->header(), $this->validRow()], null, 'A1');
        $tmp = tempnam(sys_get_temp_dir(), 'tarif').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();

        $response = $this->actingAs($this->user)->post(route('tarif-import.scan'), [
            'jenis_tarif_id' => $this->jenis->id,
            'file' => $this->upload($tmp),
        ]);

        $batch = ImportBatch::first();
        $this->assertNotNull($batch);
        $response->assertRedirect(route('tarif-import.batches.show', $batch));
        // Feedback dispatch via Floating Process Manager, bukan flash toast.
        $response->assertSessionMissing('info');
        $this->assertSame(ImportBatch::STATUS_PENDING_SCAN, $batch->status);

        Queue::assertPushed(ScanTarifImport::class);
        Queue::assertNotPushed(ProcessTarifImport::class);

        // HTTP tidak menjalankan scan: master + tarif tetap kosong.
        $this->assertSame(0, Provider::count());
        $this->assertSame(0, Service::count());
        $this->assertSame(0, Tarif::count());
    }

    public function test_scan_job_processes_chunked_without_row_limit(): void
    {
        // 2500 baris (> SCAN_SLICE lama 2000): tetap diproses penuh.
        $rows = [];
        for ($i = 0; $i < 2500; $i++) {
            $rows[] = $this->validRow([2 => 'VK'.str_pad((string) ($i % 50), 4, '0', STR_PAD_LEFT), 9 => '2026-09-01', 10 => '2099-12-31']);
        }
        $batch = $this->makeBatch($rows);

        (new ScanTarifImport($batch->id))->handle($this->service);
        $batch->refresh();

        $this->assertSame(ImportBatch::STATUS_SCAN_COMPLETED, $batch->status);
        $this->assertSame(2500, (int) $batch->scan_processed_rows);
        $this->assertSame(2500, (int) $batch->scan_total_rows);
        // Hasil bounded: preview + error disampel, kandidat unik.
        $this->assertLessThanOrEqual(200, count($batch->scan_preview ?? []));
        $this->assertSame(1, count($batch->scan_candidates['providers'] ?? []));
        $this->assertSame(50, count($batch->scan_candidates['services'] ?? []));
        $this->assertSame(0, Provider::count());
        $this->assertSame(0, Tarif::count());
    }

    public function test_scan_job_marks_failed_on_bad_header(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['KOLOM', 'NGAWUR']], null, 'A1');
        $tmp = tempnam(sys_get_temp_dir(), 'tarif').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();
        $filename = 'tarif-imports/'.uniqid('bad', true).'.xlsx';
        Storage::put($filename, file_get_contents($tmp));

        $batch = ImportBatch::create([
            'filename' => 'bad.xlsx', 'path' => $filename,
            'jenis_tarif_id' => $this->jenis->id, 'status' => 'pending_scan',
        ]);

        (new ScanTarifImport($batch->id))->handle($this->service);
        $batch->refresh();

        $this->assertSame(ImportBatch::STATUS_SCAN_FAILED, $batch->status);
        $this->assertNotEmpty($batch->scan_error_message);
    }

    public function test_scan_completed_can_fetch_preview(): void
    {
        $batch = $this->makeBatch([$this->validRow(), $this->validRow([2 => 'VK02', 3 => 'Yoga'])]);
        (new ScanTarifImport($batch->id))->handle($this->service);

        $response = $this->actingAs($this->user)->get(route('tarif-import.batches.show', $batch));
        $response->assertOk();
        $response->assertSee('tarif.xlsx');
        $response->assertSee('Import Data');

        $json = $this->actingAs($this->user)->getJson(route('tarif-import.batches.preview', $batch));
        $json->assertOk()->assertJsonPath('scan_ready', true);
        $this->assertCount(2, $json->json('preview'));
    }

    public function test_status_endpoint_not_captured_by_batch_binding(): void
    {
        $this->makeBatch([$this->validRow()]);

        $response = $this->actingAs($this->user)->getJson(route('tarif-import.batches.status'));

        $response->assertOk();
        $response->assertJsonStructure(['batches' => [['id', 'status', 'scan_percent', 'percent']]]);
        $this->assertSame(1, count($response->json('batches')));
    }

    public function test_show_binds_numeric_id(): void
    {
        $batch = $this->makeBatch([$this->validRow()]);

        $this->actingAs($this->user)->get(route('tarif-import.batches.show', $batch))->assertOk();
        $this->actingAs($this->user)->getJson(route('tarif-import.batches.preview', $batch))->assertOk();
    }

    public function test_delete_button_visible_on_history_show_and_result(): void
    {
        $batch = $this->makeBatch([$this->validRow()], 'completed');

        $history = $this->actingAs($this->user)->get(route('tarif-import.batches'));
        $history->assertOk();
        $history->assertSee('Delete');
        $history->assertSee(route('tarif-import.batches.destroy', $batch), false);

        $scanning = $this->makeBatch([$this->validRow()], 'pending_scan');
        $show = $this->actingAs($this->user)->get(route('tarif-import.batches.show', $scanning));
        $show->assertOk();
        $show->assertSee('Delete');

        $scanned = $this->makeBatch([$this->validRow()], 'scan_completed');
        $result = $this->actingAs($this->user)->get(route('tarif-import.batches.show', $scanned));
        $result->assertOk();
        $result->assertSee('Delete');
    }

    public function test_scan_job_failed_hook_marks_terminal(): void
    {
        $batch = $this->makeBatch([$this->validRow()], 'processing_scan');
        ImportBatch::where('id', $batch->id)->update(['scan_processed_rows' => 4200, 'scan_total_rows' => 10000]);

        (new \App\Jobs\ScanTarifImport($batch->id))->failed(new \RuntimeException('Simulasi worker timeout.'));
        $batch->refresh();

        $this->assertSame(ImportBatch::STATUS_SCAN_FAILED, $batch->status);
        $this->assertStringContainsString('Simulasi worker timeout', (string) $batch->scan_error_message);
        $this->assertSame(4200, (int) $batch->scan_processed_rows);
        $this->assertSame(10000, (int) $batch->scan_total_rows);
    }

    public function test_import_job_failed_hook_marks_terminal(): void
    {
        $batch = $this->makeBatch([$this->validRow()], 'processing_import');
        ImportBatch::where('id', $batch->id)->update(['processed_rows' => 500, 'total_rows' => 1000]);

        (new \App\Jobs\ProcessTarifImport($batch->id))->failed(new \RuntimeException('Simulasi worker timeout.'));
        $batch->refresh();

        $this->assertSame(ImportBatch::STATUS_FAILED, $batch->status);
        $this->assertStringContainsString('Simulasi worker timeout', (string) $batch->error_message);
        $this->assertSame(500, (int) $batch->processed_rows);
    }

    public function test_stale_processing_batches_reaped_on_status_poll(): void
    {
        $staleScan = $this->makeBatch([$this->validRow()], 'processing_scan');
        ImportBatch::where('id', $staleScan->id)->update(['updated_at' => now()->subHours(2)]);
        $staleImport = $this->makeBatch([$this->validRow()], 'processing_import');
        ImportBatch::where('id', $staleImport->id)->update(['updated_at' => now()->subHours(2)]);
        $fresh = $this->makeBatch([$this->validRow()], 'processing_scan');
        $recentlyActive = $this->makeBatch([$this->validRow()], 'processing_import');
        ImportBatch::where('id', $recentlyActive->id)->update(['updated_at' => now()->subMinutes(4)]);
        $pending = $this->makeBatch([$this->validRow()], 'pending_scan');
        ImportBatch::where('id', $pending->id)->update(['updated_at' => now()->subHours(5)]);

        $response = $this->actingAs($this->user)->getJson(route('tarif-import.batches.status'));
        $response->assertOk();

        $this->assertSame(ImportBatch::STATUS_SCAN_FAILED, $staleScan->fresh()->status);
        $this->assertNotEmpty($staleScan->fresh()->scan_error_message);
        $this->assertSame(ImportBatch::STATUS_FAILED, $staleImport->fresh()->status);
        $this->assertSame('processing_scan', $fresh->fresh()->status);
        $this->assertSame('processing_import', $recentlyActive->fresh()->status);
        $this->assertSame('pending_scan', $pending->fresh()->status);

        // Payload ikut terminal agar polling berhenti.
        $byId = collect($response->json('batches'))->keyBy('id');
        $this->assertTrue($byId[$staleScan->id]['is_terminal']);
    }

    public function test_jobs_declare_1800s_timeout(): void
    {
        $this->assertSame(1800, (new \App\Jobs\ScanTarifImport(1))->timeout);
        $this->assertSame(1800, (new \App\Jobs\ProcessTarifImport(1))->timeout);
    }

    public function test_scan_stops_at_formatted_empty_tail(): void
    {
        // 5 baris data + format yang di-drag sampai baris 5000 (tanpa nilai).
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(array_merge([$this->header(), $this->validRow(), $this->validRow([2 => 'VK02', 9 => '2025-01-01', 10 => '2025-12-31']), $this->validRow([9 => '2026-01-01', 10 => '2026-12-31']), $this->validRow([2 => 'VK03', 9 => '2027-01-01', 10 => '2027-12-31']), $this->validRow([2 => 'VK04', 9 => '2028-01-01', 10 => '2028-12-31'])]), null, 'A1');
        $sheet->getStyle('A2:K5000')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $tmp = tempnam(sys_get_temp_dir(), 'tarif').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();
        $filename = 'tarif-imports/'.uniqid('tail', true).'.xlsx';
        Storage::put($filename, file_get_contents($tmp));

        $batch = ImportBatch::create([
            'user_id' => $this->user->id,
            'filename' => 'ekor.xlsx',
            'path' => $filename,
            'jenis_tarif_id' => $this->jenis->id,
            'status' => 'pending_scan',
        ]);

        (new \App\Jobs\ScanTarifImport($batch->id))->handle($this->service);
        $batch->refresh();

        $this->assertSame(ImportBatch::STATUS_SCAN_COMPLETED, $batch->status);
        // Total dikoreksi ke baris berisi data, bukan dimensi sheet.
        $this->assertSame(5, (int) $batch->scan_total_rows);
        $this->assertSame(5, (int) $batch->scan_processed_rows);
    }

    public function test_retry_scan_redispatches(): void
    {
        Queue::fake();

        $batch = $this->makeBatch([$this->validRow()], 'scan_failed');

        $response = $this->actingAs($this->user)->post(route('tarif-import.batches.retry-scan', $batch));

        $response->assertRedirect();
        $this->assertSame(ImportBatch::STATUS_PENDING_SCAN, $batch->fresh()->status);
        Queue::assertPushed(ScanTarifImport::class);
    }

    public function test_commit_requires_scan_completed(): void
    {
        Queue::fake();

        $batch = $this->makeBatch([$this->validRow()], 'pending_scan');

        $response = $this->actingAs($this->user)->post(route('tarif-import.commit'), [
            'batch_id' => $batch->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        Queue::assertNotPushed(ProcessTarifImport::class);
        $this->assertSame('pending_scan', $batch->fresh()->status);
    }

    public function test_commit_dispatches_import_after_scan_completed(): void
    {
        Queue::fake();

        $batch = $this->makeBatch([$this->validRow()], 'scan_completed');

        $response = $this->actingAs($this->user)->post(route('tarif-import.commit'), [
            'batch_id' => $batch->id,
        ]);

        $response->assertRedirect(route('tarif-import.batches'));
        $response->assertSessionMissing('info');
        $this->assertSame(ImportBatch::STATUS_PENDING_IMPORT, $batch->fresh()->status);
        Queue::assertPushed(ProcessTarifImport::class);
        // HTTP tidak membaca Excel: belum ada insert.
        $this->assertSame(0, Tarif::count());
    }
}
