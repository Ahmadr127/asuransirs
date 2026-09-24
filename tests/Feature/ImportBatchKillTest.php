<?php

namespace Tests\Feature;

use App\Http\Services\TarifImportService;
use App\Jobs\ProcessTarifImport;
use App\Jobs\ScanTarifImport;
use App\Models\ImportBatch;
use App\Models\JenisTarif;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\TarifImport\TarifMasterResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Kill proses scan/import: hapus job antrean milik batch (pending +
 * reserved) dari jobs & failed_jobs, tandai DIBATALKAN, worker yang
 * telanjur jalan berhenti kooperatif di checkpoint (tanpa ghost).
 */
class ImportBatchKillTest extends TestCase
{
    protected JenisTarif $jenis;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

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
                $table->foreignId('role_id');
                $table->foreignId('permission_id');
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
        if (! Schema::hasTable('jenis_tarifs')) {
            Schema::create('jenis_tarifs', function ($table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('import_batches')) {
            Schema::create('import_batches', function ($table) {
                $table->id();
                $table->foreignId('user_id')->nullable();
                $table->string('filename');
                $table->string('path');
                $table->foreignId('jenis_tarif_id')->nullable();
                $table->string('status', 20)->default('pending_scan');
                $table->boolean('cancel_requested')->default(false);
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
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function ($table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function ($table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    protected function truncateTables(): void
    {
        foreach (['jobs', 'failed_jobs', 'import_batches', 'jenis_tarifs', 'users', 'roles', 'permissions', 'role_permission'] as $table) {
            DB::table($table)->delete();
        }
    }

    protected function makeBatch(string $status = 'pending_scan'): ImportBatch
    {
        Storage::put('tarif-imports/dummy.xlsx', 'dummy');

        return ImportBatch::create([
            'user_id' => $this->user->id,
            'filename' => 'tarif.xlsx',
            'path' => 'tarif-imports/dummy.xlsx',
            'jenis_tarif_id' => $this->jenis->id,
            'status' => $status,
        ]);
    }

    protected function pushJob(object $job): void
    {
        Queue::connection('database')->push($job);
    }

    protected function insertFailedJob(object $job): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['data' => ['command' => serialize($job)]]),
            'exception' => 'test',
        ]);
    }

    public function test_kill_pending_batch_deletes_its_jobs_and_marks_cancelled(): void
    {
        $batch = $this->makeBatch('pending_scan');
        $other = $this->makeBatch('pending_scan');

        $this->pushJob(new ScanTarifImport($batch->id));
        $this->pushJob(new ScanTarifImport($other->id));
        $this->insertFailedJob(new ScanTarifImport($batch->id));

        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $response = $this->actingAs($this->user)
            ->post(route('tarif-import.batches.kill', $batch));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Hanya job milik batch lain yang tersisa (tidak salah hapus).
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $batch->refresh();
        $this->assertSame(ImportBatch::STATUS_CANCELLED, $batch->status);
        $this->assertTrue((bool) $batch->cancel_requested);
        $this->assertNotEmpty($batch->scan_error_message);
        $this->assertTrue($batch->isTerminal());
    }

    public function test_kill_removes_reserved_running_job(): void
    {
        $batch = $this->makeBatch('processing_import');

        $this->pushJob(new ProcessTarifImport($batch->id));
        DB::table('jobs')->update(['reserved_at' => time()]);

        $response = $this->actingAs($this->user)
            ->post(route('tarif-import.batches.kill', $batch));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(ImportBatch::STATUS_CANCELLED, $batch->fresh()->status);
    }

    public function test_kill_terminal_batch_is_rejected(): void
    {
        $batch = $this->makeBatch('completed');

        $response = $this->actingAs($this->user)
            ->post(route('tarif-import.batches.kill', $batch));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_scan_job_exits_early_when_cancel_requested(): void
    {
        $batch = $this->makeBatch('pending_scan');
        $batch->update(['cancel_requested' => true]);

        $service = new TarifImportService(new TarifMasterResolver);
        (new ScanTarifImport($batch->id))->handle($service);

        $batch->refresh();
        $this->assertSame(ImportBatch::STATUS_CANCELLED, $batch->status);
    }

    public function test_import_job_exits_early_when_cancel_requested(): void
    {
        $batch = $this->makeBatch('pending_import');
        $batch->update(['cancel_requested' => true]);

        $service = new TarifImportService(new TarifMasterResolver);
        (new ProcessTarifImport($batch->id))->handle($service);

        $batch->refresh();
        $this->assertSame(ImportBatch::STATUS_CANCELLED, $batch->status);
    }

    public function test_retry_import_from_cancelled(): void
    {
        Queue::fake();

        $batch = $this->makeBatch('cancelled');
        $batch->update(['cancel_requested' => true]);

        $response = $this->actingAs($this->user)
            ->post(route('tarif-import.batches.retry', $batch));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame(ImportBatch::STATUS_PENDING_IMPORT, $batch->status);
        $this->assertFalse((bool) $batch->cancel_requested);
    }
}
