<?php

namespace App\Services\Bridge\Ambiguous;

use App\Services\Bridge\TarifBridgeRepository;

/**
 * Infrastructure layer AMBIGUOUS: akses data kandidat.
 *
 * Membungkus TarifBridgeRepository (yang sudah preload SEKALI + sudah
 * diperkaya info tarif per pair: tariff representative + tariffs[]).
 * Sengaja TIDAK menambah query sendiri agar batas "tanpa query per row"
 * tetap terjaga — seluruh data berasal dari preload base repository.
 */
final class AmbiguousRepository
{
    public function __construct(protected TarifBridgeRepository $base) {}

    public function preload(): self
    {
        $this->base->preload();

        return $this;
    }

    /**
     * Kandidat service+class untuk satu mapping key, lengkap dengan info
     * tarif per pair. Terurut deterministik (base) — pengurutan ulang
     * berdasarkan skor dilakukan di Ranker, bukan di sini.
     *
     * @return array<int, array{service_code: string, service_name: string, class_code: string, class_name: string, tariff: ?float, tariffs: array<int, float>}>
     */
    public function findCandidates(string $mappingKey): array
    {
        return $this->base->candidatesFor($mappingKey);
    }
}
