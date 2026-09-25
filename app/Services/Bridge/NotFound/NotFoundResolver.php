<?php

namespace App\Services\Bridge\NotFound;

use App\Services\Bridge\Ambiguous\AmbiguousRanker;
use App\Services\Bridge\BridgeServiceSearch;


final class NotFoundResolver
{
    public function __construct(
        protected NotFoundRepository $repository,
        protected ?AmbiguousRanker $ranker = null,
    ) {
        $this->ranker ??= new AmbiguousRanker();
    }

    public function repository(): NotFoundRepository
    {
        return $this->repository;
    }

    /** @param  array<string, mixed>  $normalized */
    public function resolve(array $normalized): NotFoundResult
    {
        return NotFoundResult::manual();
    }

    public function suggest(
        string $description,
        ?string $query = null,
        ?string $className = null,
        ?float $effectiveTariff = null,
        int $limit = 20,
    ): array {
        
        
        $core = (string) preg_replace('/\([^)]*\)/u', ' ', $description);
        $core = trim((string) preg_replace(
            '/\b(anasthesy|anestesi|anastesi|anesthesi|anasthesi|narkose|sedasi|bius|dokter|operator|bidan|spesialis|dpjp|konsulen)\b.*/ius',
            '',
            $core
        ));
        if (str_contains($core, ' - ')) {
            $parts = explode(' - ', $core);
            $tail = trim((string) end($parts));
            if ($tail !== '') {
                $core = $tail;
            }
        }
        $coreMains = array_values(array_filter(
            BridgeServiceSearch::words($core),
            fn ($t) => mb_strlen($t) > 2
        ));
        // Fallback aman: inti tak dapat ditentukan -> description mentah.
        $searchDesc = count($coreMains) >= 2 ? $core : $description;

        $services = BridgeServiceSearch::similar($searchDesc, $query, $limit * 2);
        if ($services === [] && $searchDesc !== $description) {
            $searchDesc = $description;
            $services = BridgeServiceSearch::similar($searchDesc, $query, $limit * 2);
        }
        if ($services === []) {
            return [];
        }
        $className = $className !== null && trim($className) !== '' ? trim($className) : null;
        $effectiveTariff = is_numeric($effectiveTariff) && (float) $effectiveTariff > 0
            ? (float) $effectiveTariff
            : null;

        if ($className === null && $effectiveTariff === null) {
            return array_slice($services, 0, $limit);
        }

        $byCode = [];
        foreach ($services as $i => $service) {
            $byCode[mb_strtoupper(trim((string) $service['service_code']))] = $service + ['_order' => $i];
        }
        $pairs = $this->repository->pairsForServices(array_keys($byCode));

        
        $descTokens = BridgeServiceSearch::words($searchDesc);
        $queryTokens = $query !== null && trim($query) !== ''
            ? BridgeServiceSearch::words($query)
            : [];

        $pairedCodes = [];
        $ranked = [];
        foreach ($pairs as $pair) {
            $key = mb_strtoupper(trim($pair['service_code']));
            $service = $byCode[$key] ?? null;
            if ($service === null) {
                continue;
            }
            $pairedCodes[$key] = true;
            $classMatch = $className !== null
                && BridgeServiceSearch::normalize((string) ($pair['class_name'] ?? ''))
                    === BridgeServiceSearch::normalize($className)
                ? 1 : 0;
            $tariffDiff = $this->ranker->bestTariffDiff($effectiveTariff, $pair['tariffs'] ?? null);
            [$descTier, $descMatched, $descExact] = BridgeServiceSearch::rank(
                $descTokens, (string) ($service['service_description'] ?? '')
            );
            [$qTier, $qMatched, $qExact] = $queryTokens === []
                ? [0, 0, false]
                : BridgeServiceSearch::rank(
                    $queryTokens, (string) ($service['service_description'] ?? '')
                );
            $ranked[] = [
                'service_code' => $service['service_code'],
                'service_name' => $service['service_name'] ?? null,
                'service_description' => $service['service_description'] ?? null,
                'class_code' => $pair['class_code'],
                'class_name' => $pair['class_name'],
                'tariff' => $pair['tariff'],
                '_class_match' => $classMatch,
                '_tariff_diff' => $tariffDiff,
                '_score' => $classMatch * AmbiguousRanker::WEIGHT_CLASS
                    + ($tariffDiff === null ? 0.0 : (1.0 - $tariffDiff) * AmbiguousRanker::WEIGHT_TARIFF),
                'q_tier' => $qTier,
                'q_matched' => $qMatched,
                'q_exact' => $qExact,
                'desc_tier' => $descTier,
                'desc_matched' => $descMatched,
                'desc_exact' => $descExact,
                '_order' => $service['_order'],
            ];
        }


        foreach ($byCode as $key => $service) {
            if (isset($pairedCodes[$key])) {
                continue;
            }
            [$descTier, $descMatched, $descExact] = BridgeServiceSearch::rank(
                $descTokens, (string) ($service['service_description'] ?? '')
            );
            [$qTier, $qMatched, $qExact] = $queryTokens === []
                ? [0, 0, false]
                : BridgeServiceSearch::rank(
                    $queryTokens, (string) ($service['service_description'] ?? '')
                );
            $ranked[] = [
                'service_code' => $service['service_code'],
                'service_name' => $service['service_name'] ?? null,
                'service_description' => $service['service_description'] ?? null,
                'class_code' => null,
                'class_name' => null,
                'tariff' => null,
                '_class_match' => 0,
                '_tariff_diff' => null,
                '_score' => 0.0,
                'q_tier' => $qTier,
                'q_matched' => $qMatched,
                'q_exact' => $qExact,
                'desc_tier' => $descTier,
                'desc_matched' => $descMatched,
                'desc_exact' => $descExact,
                '_order' => $service['_order'],
            ];
        }

        usort($ranked, function ($a, $b) {
            foreach (['q_tier', 'q_matched', 'q_exact', 'desc_tier', 'desc_matched', 'desc_exact'] as $k) {
                if ($a[$k] !== $b[$k]) {
                    return $b[$k] <=> $a[$k];
                }
            }
            if ($a['_score'] !== $b['_score']) {
                return $b['_score'] <=> $a['_score'];
            }
            $da = $a['_tariff_diff'] ?? PHP_FLOAT_MAX;
            $db = $b['_tariff_diff'] ?? PHP_FLOAT_MAX;
            if ($da !== $db) {
                return $da <=> $db;
            }

            return $a['_order'] <=> $b['_order'];
        });

        return array_map(function ($row) {
            unset($row['_order'], $row['q_tier'], $row['q_matched'], $row['q_exact'], $row['desc_tier'], $row['desc_matched'], $row['desc_exact']);

            return $row;
        }, array_slice($ranked, 0, $limit));
    }


    public function explain(array $normalized, ?string $masterClassCode): string
    {
        $desc = trim((string) ($normalized['service_description'] ?? ''));
        $kelas = trim((string) ($normalized['class_name'] ?? ''));
        $key = (string) ($normalized['mapping_key'] ?? '');

        $text = "Kunci '{$key}' (description \"{$desc}\" + kelas \"{$kelas}\", setelah uppercase + rapikan spasi) tidak ditemukan persis di master Tarif, sehingga baris ini berstatus NOT_FOUND. ";
        $text .= 'Pencarian master memakai kecocokan persis terhadap nama maupun deskripsi service + nama kelas — bukan pencarian mirip. ';
        $text .= $masterClassCode !== null
            ? "Kelasnya sendiri dikenali master (kode {$masterClassCode}), jadi kolom SERVICECODE KELAS tetap bisa diperbaiki saat generate; yang hilang hanya kode service-nya. "
            : 'Kelasnya pun tidak dikenali master, sehingga kolom SERVICECODE KELAS memakai bawaan Excel. ';
        $text .= $this->tariffSentence($normalized).' ';
        $text .= 'Kemungkinan penyebab: beda penulisan/singkatan vs master, layanan baru yang belum ada di master, atau salah kolom kelas. ';
        $text .= 'Langkah: buka Petakan Manual dan gunakan tarif efektif di atas sebagai pembanding saat mencari service yang benar — kelas ikut otomatis dari master.';

        return $text;
    }

    /**
     * Kalimat tarif efektif sesuai sumbernya (excel / hitung /
     * grup / tak tersedia).
     *
     * @param  array<string, mixed>  $normalized
     */
    protected function tariffSentence(array $normalized): string
    {
        $excel = isset($normalized['tariff']) && is_numeric($normalized['tariff'])
            ? (float) $normalized['tariff']
            : null;
        $effective = $normalized['effective_tariff'] ?? null;
        $effective = is_numeric($effective) ? (float) $effective : null;
        $source = $normalized['tariff_source'] ?? null;
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');

        if ($effective !== null && $source === \App\Services\Bridge\BridgeTarifEffectiveTariff::SOURCE_EXCEL) {
            return "Tarif efektif menggunakan nilai TARIFF Excel sebesar {$rp($effective)}.";
        }
        if ($effective !== null && $source === \App\Services\Bridge\BridgeTarifEffectiveTariff::SOURCE_COMPUTED) {
            $total = isset($normalized['total_billed']) && is_numeric($normalized['total_billed'])
                ? $rp($normalized['total_billed']) : '-';
            $qty = $normalized['quantity'] ?? '-';
            $qtyText = is_numeric($qty) ? (string) ((float) $qty == (int) $qty ? (int) $qty : (float) $qty) : '-';

            return "Kolom TARIFF pada row ini kosong. Sistem menggunakan tarif efektif {$rp($effective)} yang dihitung dari TOTAL BILLED {$total} ÷ QUANTITY {$qtyText} sebagai referensi tarif.";
        }
        if ($effective !== null && $source === \App\Services\Bridge\BridgeTarifEffectiveTariff::SOURCE_GROUP) {
            return "Kolom TARIFF dan TOTAL BILLED ÷ QUANTITY row ini kosong. Sistem memakai tarif efektif {$rp($effective)} dari row lain dengan description + kelas yang sama sebagai referensi tarif.";
        }

        return 'Tarif tidak tersedia (TARIFF kosong dan TOTAL BILLED ÷ QUANTITY tidak bisa dihitung) sehingga pembanding tarif tidak digunakan.';
    }
}
