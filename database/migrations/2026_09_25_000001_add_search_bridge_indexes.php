<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index untuk pencarian tarif (TarifService::applyFilters) + bridging
     * (TarifBridgeRepository + BridgeServiceSearch::prefilter) + import dedup.
     *
     * Konteks: PostgreSQL (lihat DB_CONNECTION=pgsql di .env).
     * - WHERE = ? pada FK -> B-Tree biasa.
     * - UPPER(TRIM(code)) = ? -> expression index (B-Tree biasa tidak terpakai).
     * - LOWER(col) LIKE '%token%' (leading %) -> pg_trgm + GIN.
     *
     * Catatan: sengaja TIDAK pakai CREATE INDEX CONCURRENTLY agar bisa jalan
     * di dalam transaksi migration default Laravel/PG. Untuk tabel 200rb+ row,
     * jalankan migrate saat trafik rendah.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // --- TARIFS: FK + filter exact + sort ---
        Schema::table('tarifs', function (Blueprint $table) {
            $table->index('jenis_tarif_id', 'tarifs_jenis_id_idx');
            $table->index('provider_id', 'tarifs_provider_id_idx');
            $table->index('service_id', 'tarifs_service_id_idx');
            $table->index('class_id', 'tarifs_class_id_idx');
            $table->index('surgery_type', 'tarifs_surgery_type_idx');
            $table->index('created_at', 'tarifs_created_at_idx');
            $table->index('tariff', 'tarifs_tariff_idx');
            // Business key dedup import (loadExistingKeys/loadExistingKeysFor)
            // + covering filter kombinasi: jenis|provider|service|class|periode|tipe.
            $table->index(
                ['jenis_tarif_id', 'provider_id', 'service_id', 'class_id', 'surgery_type', 'valid_date_from', 'end_date_to'],
                'tarifs_business_key_idx'
            );
            // Overlap periode: masing-masing sisi WHERE ter-cover.
            // Composite (valid_date_from, end_date_to) sudah ada dari migration awal.
            $table->index('valid_date_from', 'tarifs_valid_from_idx');
            $table->index('end_date_to', 'tarifs_end_to_idx');
        });

        // tarifs.helper LIKE '%...%' (TarifService search).
        DB::statement('CREATE INDEX IF NOT EXISTS tarifs_helper_trgm ON tarifs USING gin (LOWER(helper) gin_trgm_ops)');

        // --- MASTER: status + orderBy name (dropdown filter + listing) ---
        foreach (['providers', 'services', 'classes', 'jenis_tarifs'] as $tbl) {
            Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                $table->index('status', $tbl.'_status_idx');
                $table->index(['status', 'name'], $tbl.'_status_name_idx');
            });
        }

        // --- MASTER: UPPER(TRIM(code)) untuk pairExists/serviceExists/import ---
        // B-Tree UNIQUE(code) existing tidak terpakai untuk query UPPER(TRIM(code)) = ?.
        DB::statement('CREATE INDEX IF NOT EXISTS providers_code_upper ON providers (UPPER(TRIM(code)))');
        DB::statement('CREATE INDEX IF NOT EXISTS services_code_upper ON services (UPPER(TRIM(code)))');
        DB::statement('CREATE INDEX IF NOT EXISTS classes_code_upper ON classes (UPPER(TRIM(code)))');
        // Fallback kelas by name (TarifMasterResolver + TarifBridgeRepository::classCodeFor).
        DB::statement('CREATE INDEX IF NOT EXISTS classes_name_upper ON classes (UPPER(TRIM(name)))');

        // --- BRIDGE prefilter + pencarian master: LOWER(col) LIKE '%token%' ---
        // BridgeServiceSearch::prefilter me-OR-kan LOWER(code/name/description), max 6 token.
        DB::statement('CREATE INDEX IF NOT EXISTS services_code_trgm ON services USING gin (LOWER(code) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS services_name_trgm ON services USING gin (LOWER(name) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS services_desc_trgm ON services USING gin (LOWER(description) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS providers_code_trgm ON providers USING gin (LOWER(code) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS providers_name_trgm ON providers USING gin (LOWER(name) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS classes_code_trgm ON classes USING gin (LOWER(code) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS classes_name_trgm ON classes USING gin (LOWER(name) gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS services_code_trgm');
        DB::statement('DROP INDEX IF EXISTS services_name_trgm');
        DB::statement('DROP INDEX IF EXISTS services_desc_trgm');
        DB::statement('DROP INDEX IF EXISTS providers_code_trgm');
        DB::statement('DROP INDEX IF EXISTS providers_name_trgm');
        DB::statement('DROP INDEX IF EXISTS classes_code_trgm');
        DB::statement('DROP INDEX IF EXISTS classes_name_trgm');

        DB::statement('DROP INDEX IF EXISTS providers_code_upper');
        DB::statement('DROP INDEX IF EXISTS services_code_upper');
        DB::statement('DROP INDEX IF EXISTS classes_code_upper');
        DB::statement('DROP INDEX IF EXISTS classes_name_upper');

        DB::statement('DROP INDEX IF EXISTS tarifs_helper_trgm');

        foreach (['providers', 'services', 'classes', 'jenis_tarifs'] as $tbl) {
            Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                $table->dropIndex($tbl.'_status_name_idx');
                $table->dropIndex($tbl.'_status_idx');
            });
        }

        Schema::table('tarifs', function (Blueprint $table) {
            $table->dropIndex('tarifs_valid_from_idx');
            $table->dropIndex('tarifs_end_to_idx');
            $table->dropIndex('tarifs_business_key_idx');
            $table->dropIndex('tarifs_tariff_idx');
            $table->dropIndex('tarifs_created_at_idx');
            $table->dropIndex('tarifs_surgery_type_idx');
            $table->dropIndex('tarifs_class_id_idx');
            $table->dropIndex('tarifs_service_id_idx');
            $table->dropIndex('tarifs_provider_id_idx');
            $table->dropIndex('tarifs_jenis_id_idx');
        });
    }
};
