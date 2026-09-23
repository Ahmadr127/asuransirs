<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lifecycle scan async: UPLOAD -> QUEUE SCAN -> PREVIEW -> QUEUE IMPORT.
     * Hasil scan disimpan bounded (ringkasan + kandidat unik + sampel
     * preview/error) agar tanpa hard limit jumlah row. Import tetap
     * membaca ulang Excel via chunk di worker.
     */
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('scan_total_rows')->default(0);
            $table->unsignedBigInteger('scan_processed_rows')->default(0);
            $table->json('scan_summary')->nullable();
            $table->json('scan_candidates')->nullable();
            $table->json('scan_preview')->nullable();
            $table->json('scan_errors')->nullable();
            $table->text('scan_error_message')->nullable();
        });

        // Baris lama (alur import sync): pending/processing = antrean import.
        DB::table('import_batches')->where('status', 'pending')->update(['status' => 'pending_import']);
        DB::table('import_batches')->where('status', 'processing')->update(['status' => 'processing_import']);
    }

    public function down(): void
    {
        DB::table('import_batches')->where('status', 'pending_import')->update(['status' => 'pending']);
        DB::table('import_batches')->where('status', 'processing_import')->update(['status' => 'processing']);

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn([
                'scan_total_rows', 'scan_processed_rows', 'scan_summary',
                'scan_candidates', 'scan_preview', 'scan_errors', 'scan_error_message',
            ]);
        });
    }
};
