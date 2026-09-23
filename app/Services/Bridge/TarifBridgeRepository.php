<?php

namespace App\Services\Bridge;

use App\Models\Tarif;
use Illuminate\Support\Collection;

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

        /** @var Collection<int, Tarif> $tarifs */
        $tarifs = Tarif::with([
            'service:id,code,name,description',
            'serviceClass:id,code,name',
        ])->get(['id', 'service_id', 'class_id']);

        foreach ($tarifs as $tarif) {
            $service = $tarif->service;
            $class = $tarif->serviceClass;
            if (! $service || ! $class) {
                continue;
            }

            $serviceKeys = array_unique(array_filter([
                BridgeTarifRowNormalizer::normalizeKey($service->name),
                BridgeTarifRowNormalizer::normalizeKey($service->description),
            ]));
            $classKey = BridgeTarifRowNormalizer::normalizeKey($class->name);
            if ($serviceKeys === [] || $classKey === '') {
                continue;
            }

            $pairKey = mb_strtoupper(trim($service->code)).'|'.mb_strtoupper(trim($class->code));
            $pair = [
                'service_code' => mb_strtoupper(trim($service->code)),
                'service_name' => $service->name,
                'class_code' => mb_strtoupper(trim($class->code)),
                'class_name' => $class->name,
            ];

            foreach ($serviceKeys as $serviceKey) {
                $key = $serviceKey.'|'.$classKey;
                $this->map[$key][$pairKey] = $pair;
            }
        }

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
