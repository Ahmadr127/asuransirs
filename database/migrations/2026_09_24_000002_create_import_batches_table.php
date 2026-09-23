<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat import tarif via queue. Satu baris = satu file yang
     * didispatch. Status: pending -> processing -> completed | failed.
     * File dipertahankan selama batch ada (untuk retry); dihapus saat
     * batch di-delete. Tarif memakai idempotency key existing sehingga
     * retry aman dari duplikat.
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->foreignId('jenis_tarif_id')->nullable()->constrained('jenis_tarifs')->nullOnDelete();
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

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
