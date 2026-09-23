<?php

namespace Tests\Feature;

use App\Http\Services\TarifImportService;
use App\Jobs\ProcessTarifImport;
use App\Models\ImportBatch;
use App\Models\JenisTarif;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceClass;
use App\Models\Tarif;
use App\Services\TarifImport\TarifMasterResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportBatchTest extends TestCase
{
    protected TarifImportService $service;

    protected JenisTarif $jenis;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->createTables();
        $this->truncateTables();

        $this->jenis = JenisTarif::create(['code' => 'obat', 'name' => 'Obat', 'status' => 'active']);
        $this->service = new TarifImportService(new TarifMasterResolver);
    }

    protected function createTables(): void
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
        if (! Schema::hasTable('import_batches')) {
            Schema::create('import_batches', function ($table) {
                $table->id();
                $table->foreignId('user_id')->nullable();
                $table->string('filename');
                $table->string('path');
                $table->foreignId('jenis_tarif_id')->nullable()->constrained('jenis_tarifs');
                $table->string('status', 20)->default('pending');
                $table->unsignedBigInteger('total_rows')->default(0);
                $table->unsignedBigInteger('processed_rows')->default(0);
                $table->unsignedBigInteger('inserted')->default(0);
                $table->unsignedBigInteger('skipped_duplicate')->default(0);
                $table->unsignedBigInteger('skipped_error')->default(0);
                $table->unsignedBigInteger('providers_created')->default(0);
                $table->unsignedBigInteger('services_created')->default(0);
                $table->unsignedBigInteger('classes_created')->default(0);
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function truncateTables(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        foreach (['tarifs', 'import_batches', 'jenis_tarifs', 'providers', 'services', 'classes'] as $table) {
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

        // Simpan ke fake storage agar job bisa membacanya via Storage::path().
        $filename = 'tarif-imports/'.uniqid('tarif', true).'.xlsx';
        $tmp = tempnam(sys_get_temp_dir(), 'tarif').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();
        Storage::put($filename, file_get_contents($tmp));

        return $filename;
    }

    protected function validRow(array $over = []): array
    {
        return array_replace(
            ['OAZRA0-000', 'RS AZRA', 'VK01', 'Senam Hamil', 'KLS1', 'Kelas 1', '-', '-', 30000, '2026-09-01', '2099-12-31'],
            $over
        );
    }

    public function test_job_completes_empty_masters_with_counts(): void
    {
        $path = $this->makeFile([
            $this->validRow(),
            $this->validRow([2 => 'VK02', 3 => 'Yoga Prenatal', 4 => 'KLX', 5 => 'Suite']),
        ]);

        $batch = ImportBatch::create([
            'filename' => 'tarif.xlsx',
            'path' => $path,
            'jenis_tarif_id' => $this->jenis->id,
            'status' => ImportBatch::STATUS_PENDING,
        ]);

        (new ProcessTarifImport($batch->id))->handle($this->service);
        $batch->refresh();

        $this->assertSame(ImportBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(2, (int) $batch->total_rows);
        $this->assertSame(2, (int) $batch->processed_rows);
        $this->assertSame(2, (int) $batch->inserted);
        $this->assertSame(1, (int) $batch->providers_created);
        $this->assertSame(2, (int) $batch->services_created);
        $this->assertSame(2, (int) $batch->classes_created);
        $this->assertSame(1, Provider::count());
        $this->assertSame(2, Tarif::count());
        $this->assertSame(100, $batch->progressPercent());
        $this->assertTrue($batch->isTerminal());
    }

    public function test_job_retry_is_idempotent(): void
    {
        $path = $this->makeFile([$this->validRow()]);

        $batch = ImportBatch::create([
            'filename' => 'tarif.xlsx',
            'path' => $path,
            'jenis_tarif_id' => $this->jenis->id,
            'status' => ImportBatch::STATUS_PENDING,
        ]);

        (new ProcessTarifImport($batch->id))->handle($this->service);
        $this->assertSame(1, Tarif::count());

        // Simulasi retry: reset seperti controller, jalankan lagi.
        $batch->update(['status' => ImportBatch::STATUS_PENDING, 'processed_rows' => 0, 'inserted' => 0]);
        (new ProcessTarifImport($batch->id))->handle($this->service);
        $batch->refresh();

        $this->assertSame(ImportBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, Tarif::count());
        $this->assertSame(1, Provider::count());
        $this->assertSame(1, Service::count());
        $this->assertSame(1, (int) $batch->skipped_duplicate);
    }

    public function test_job_marks_failed_when_file_missing(): void
    {
        $batch = ImportBatch::create([
            'filename' => 'hilang.xlsx',
            'path' => 'tarif-imports/tidak-ada.xlsx',
            'jenis_tarif_id' => $this->jenis->id,
            'status' => ImportBatch::STATUS_PENDING,
        ]);

        (new ProcessTarifImport($batch->id))->handle($this->service);
        $batch->refresh();

        $this->assertSame(ImportBatch::STATUS_FAILED, $batch->status);
        $this->assertStringContainsString('tidak tersedia', (string) $batch->error_message);
        $this->assertTrue($batch->isTerminal());
    }

    public function test_progress_percent_handles_zero_total(): void
    {
        $batch = new ImportBatch(['status' => 'processing', 'total_rows' => 0, 'processed_rows' => 0]);

        $this->assertSame(0, $batch->progressPercent());

        $batch->status = 'completed';

        $this->assertSame(100, $batch->progressPercent());
    }
}
