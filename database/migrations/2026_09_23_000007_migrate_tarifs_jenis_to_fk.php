<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pindahkan jenis_tarif dari enum string menjadi FK ke master jenis_tarifs.
     * Data existing di-backfill berdasarkan code, lalu kolom string dihapus.
     */
    public function up(): void
    {
        $defaults = [
            ['code' => 'tarif', 'name' => 'Tarif', 'status' => 'active'],
            ['code' => 'obat', 'name' => 'Obat', 'status' => 'active'],
            ['code' => 'alkes', 'name' => 'Alkes', 'status' => 'active'],
            ['code' => 'bhp', 'name' => 'BHP', 'status' => 'active'],
            ['code' => 'makanan', 'name' => 'Makanan', 'status' => 'active'],
        ];

        foreach ($defaults as $row) {
            if (!DB::table('jenis_tarifs')->where('code', $row['code'])->exists()) {
                DB::table('jenis_tarifs')->insert(array_merge($row, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        Schema::table('tarifs', function (Blueprint $table) {
            $table->foreignId('jenis_tarif_id')->nullable()->after('id')
                ->constrained('jenis_tarifs')->restrictOnDelete()->cascadeOnUpdate();
        });

        // Backfill FK dari nilai string lama
        $tarifs = DB::table('tarifs')->select('id', 'jenis_tarif')->get();
        foreach ($tarifs as $tarif) {
            $jenisId = DB::table('jenis_tarifs')->where('code', $tarif->jenis_tarif)->value('id');
            if ($jenisId) {
                DB::table('tarifs')->where('id', $tarif->id)->update(['jenis_tarif_id' => $jenisId]);
            }
        }

        Schema::table('tarifs', function (Blueprint $table) {
            $table->dropColumn('jenis_tarif');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tarifs', function (Blueprint $table) {
            $table->string('jenis_tarif')->nullable()->after('id');
        });

        $tarifs = DB::table('tarifs')->select('tarifs.id', 'jenis_tarifs.code')
            ->leftJoin('jenis_tarifs', 'jenis_tarifs.id', '=', 'tarifs.jenis_tarif_id')
            ->get();
        foreach ($tarifs as $tarif) {
            DB::table('tarifs')->where('id', $tarif->id)->update(['jenis_tarif' => $tarif->code]);
        }

        Schema::table('tarifs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jenis_tarif_id');
        });
    }
};
