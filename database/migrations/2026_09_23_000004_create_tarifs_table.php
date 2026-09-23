<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * tarifs hanya menyimpan FK ke master (provider/service/class),
     * tanpa duplikasi code/name. Status dihitung otomatis dari periode.
     */
    public function up(): void
    {
        Schema::create('tarifs', function (Blueprint $table) {
            $table->id();
            $table->string('jenis_tarif');
            $table->foreignId('provider_id')->constrained('providers')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('surgery_type');
            $table->string('helper')->nullable();
            $table->decimal('tariff', 15, 2);
            $table->date('valid_date_from');
            $table->date('end_date_to');
            $table->timestamps();

            $table->index(['jenis_tarif', 'surgery_type']);
            $table->index(['valid_date_from', 'end_date_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tarifs');
    }
};
