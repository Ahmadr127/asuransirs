<?php

namespace App\Services\Bridge\NotFound;

use App\Services\Bridge\BridgeTarifEffectiveTariff;
use App\Services\Bridge\TarifBridgeRepository;
use Illuminate\Support\Facades\DB;

/**
 * Infrastructure layer NOT_FOUND: data pasangan service+kelas+tarif
 * dari tabel tarifs untuk kandidat hasil BridgeServiceSearch.
 *
 * Sengaja TANPA preload seluruh master: hanya SATU query sempit
 * (whereIn kode service hasil similarity, biasanya <= 40) per
 * pemanggilan suggest — dipanggil dari endpoint dropdown (per
 * interaksi user), bukan per row saat scan.
 */
final class NotFoundRepository
{
    public function __construct(protected TarifBridgeRepository $base) {}

    public function preload(): self
    {
        $this->base->preload();

        return $this;
    }

    /**
     * Pasangan unik service+kelas beserta daftar tarifnya untuk
     * sekumpulan kode service. Bentuk sama dengan kandidat Ambiguous
     * agar bisa diranking dengan sinyal yang sama (kelas + tarif).
     *
     * @param  array<int, string>  $serviceCodes
     * @return array<int, array{service_code: string, class_code: string, class_name: string, tariff: ?float, tariffs: array<int, float>}>
     */
    public function pairsForServices(array $serviceCodes): array
    {
        $serviceCodes = array_values(array_unique(array_filter(array_map(
            fn ($c) => mb_strtoupper(trim((string) $c)),
            $serviceCodes
        ))));
        if ($serviceCodes === []) {
            return [];
        }

        $rows = DB::table('tarifs')
            ->join('services as s', 's.id', '=', 'tarifs.service_id')
            ->join('classes as c', 'c.id', '=', 'tarifs.class_id')
            ->whereIn(DB::raw('UPPER(TRIM(s.code))'), $serviceCodes)
            ->select([
                's.code as service_code',
                'c.code as class_code',
                'c.name as class_name',
                'tarifs.tariff as tariff',
            ])
            ->orderBy('s.code')
            ->orderBy('c.code')
            ->get();

        /** @var array<string, array{service_code: string, class_code: string, class_name: string, tariff: ?float, tariffs: array<int, float>}> $pairs */
        $pairs = [];
        foreach ($rows as $row) {
            $key = mb_strtoupper(trim((string) $row->service_code)).'|'.mb_strtoupper(trim((string) $row->class_code));
            $pairs[$key] ??= [
                'service_code' => mb_strtoupper(trim((string) $row->service_code)),
                'class_code' => mb_strtoupper(trim((string) $row->class_code)),
                'class_name' => (string) $row->class_name,
                'tariff' => null,
                'tariffs' => [],
            ];
            if (is_numeric($row->tariff) && (float) $row->tariff > 0
                && ! in_array((float) $row->tariff, $pairs[$key]['tariffs'], true)
            ) {
                $pairs[$key]['tariffs'][] = (float) $row->tariff;
            }
        }
        foreach ($pairs as $key => $pair) {
            $pairs[$key]['tariff'] = BridgeTarifEffectiveTariff::median($pair['tariffs']);
        }

        return array_values($pairs);
    }
}
