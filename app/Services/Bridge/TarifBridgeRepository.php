<?php

namespace App\Services\Bridge;

use Illuminate\Support\Facades\DB;

/**
 * Sumber master untuk Bridge: tabel tarifs (relasi service + class).
 * Preload SEKALI lalu lookup via associative map — tanpa query per row.
 * Tidak pernah membuat/mengubah master.
 */
class TarifBridgeRepository
{
    /** @var array<string, array<int, array{code: string}>> mapping_key => pasangan unik */
    protected array $map = [];

    protected bool $loaded = false;

    public function preload(): self
    {
        if ($this->loaded) {
            return $this;
        }

        // Master bisa 200rb+ row: jangan hidrasi Eloquent sekaligus (OOM).
        // Streaming via query builder per chunk, hanya kolom yang dipakai.
        DB::table('tarifs')
            ->join('services as s', 's.id', '=', 'tarifs.service_id')
            ->join('classes as c', 'c.id', '=', 'tarifs.class_id')
            ->select([
                's.code as service_code',
                's.name as service_name',
                's.description as service_description',
                'c.code as class_code',
                'c.name as class_name',
            ])
            ->orderBy('tarifs.id')
            ->chunk(5000, function ($rows) {
                foreach ($rows as $row) {
                    $serviceKeys = array_unique(array_filter([
                        BridgeTarifRowNormalizer::normalizeKey($row->service_name),
                        BridgeTarifRowNormalizer::normalizeKey($row->service_description),
                    ]));
                    $classKey = BridgeTarifRowNormalizer::normalizeKey($row->class_name);
                    if ($serviceKeys === [] || $classKey === '') {
                        continue;
                    }

                    $pairKey = mb_strtoupper(trim((string) $row->service_code)).'|'.mb_strtoupper(trim((string) $row->class_code));
                    $pair = [
                        'service_code' => mb_strtoupper(trim((string) $row->service_code)),
                        'service_name' => $row->service_name,
                        'class_code' => mb_strtoupper(trim((string) $row->class_code)),
                        'class_name' => $row->class_name,
                    ];

                    foreach ($serviceKeys as $serviceKey) {
                        $this->map[$serviceKey.'|'.$classKey][$pairKey] = $pair;
                    }
                }
            });

        $this->loaded = true;

        return $this;
    }

    /**
     * Kandidat (pasangan service+class unik) untuk satu mapping key,
     * terurut deterministik. [] bila tidak ada.
     *
     * @return array<int, array{service_code: string, service_name: string, class_code: string, class_name: string}>
     */
    public function candidatesFor(string $mappingKey): array
    {
        $this->preload();

        $pairs = array_values($this->map[$mappingKey] ?? []);
        usort($pairs, fn ($a, $b) => [$a['service_code'], $a['class_code']] <=> [$b['service_code'], $b['class_code']]);

        return $pairs;
    }
}
