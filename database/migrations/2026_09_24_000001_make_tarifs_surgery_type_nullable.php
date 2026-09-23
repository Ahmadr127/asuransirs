<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RUANG BEDAH (surgery_type) dan HELPER nullable: kosong / "-" di
     * Excel dianggap NULL tanpa error. Helper sudah nullable sejak awal,
     * yang diubah hanya surgery_type.
     */
    public function up(): void
    {
        Schema::table('tarifs', function (Blueprint $table) {
            $table->string('surgery_type')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tarifs', function (Blueprint $table) {
            $table->string('surgery_type')->nullable(false)->change();
        });
    }
};
