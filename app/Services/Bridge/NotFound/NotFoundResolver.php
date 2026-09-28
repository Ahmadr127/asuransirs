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
        // Pola visite + nama dokter ditulis ulang menjadi frasa kanonis
        // ("VISITE DOKTER SPESIALIS/UMUM") dan dipakai langsung — tanpa
        // lewat ekstraksi inti (kata "spesialis" sendiri noise di sana).
        // Bukan pola visite -> ekstraksi inti tindakan seperti semula.
        $searchDesc = VisiteQueryRule::rewrite($description)
            ?? $this->coreAction($description);

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

        
        $descTokens = BridgeServiceSearch::contentWords($searchDesc);
        $queryTokens = $query !== null && trim($query) !== ''
            ? BridgeServiceSearch::words($query)
            : [];

        $pairedCodes = [];
        $ranked = [];
        // Bobot IDF per token query (di atas pool recall): token langka
        // ("varicocele") lebih menentukan daripada token umum
        // ("laparoscopy") bila tier + jumlah cocok seri — kasus Row 25.
        $idfs = $this->idfWeights($descTokens, $services);
        $descTotal = count($descTokens);
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
            [$descTier, $descMatched, $descExact] = BridgeServiceSearch::rankCandidate(
                $descTokens, $searchDesc,
                (string) ($service['service_code'] ?? ''), $service['service_name'] ?? null,
                (string) ($service['service_description'] ?? '')
            );
            [$qTier, $qMatched, $qExact] = $queryTokens === []
                ? [0, 0, false]
                : BridgeServiceSearch::rankCandidate(
                    $queryTokens, (string) $query,
                    (string) ($service['service_code'] ?? ''), $service['service_name'] ?? null,
                    (string) ($service['service_description'] ?? '')
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
                'desc_total' => $descTotal,
                'text_idf' => $descTier > 0
                    ? $this->matchedIdf($descTokens, (string) ($service['service_description'] ?? ''), $idfs)
                    : 0.0,
                '_order' => $service['_order'],
            ];
        }


        foreach ($byCode as $key => $service) {
            if (isset($pairedCodes[$key])) {
                continue;
            }
            [$descTier, $descMatched, $descExact] = BridgeServiceSearch::rankCandidate(
                $descTokens, $searchDesc,
                (string) ($service['service_code'] ?? ''), $service['service_name'] ?? null,
                (string) ($service['service_description'] ?? '')
            );
            [$qTier, $qMatched, $qExact] = $queryTokens === []
                ? [0, 0, false]
                : BridgeServiceSearch::rankCandidate(
                    $queryTokens, (string) $query,
                    (string) ($service['service_code'] ?? ''), $service['service_name'] ?? null,
                    (string) ($service['service_description'] ?? '')
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
                'desc_total' => $descTotal,
                'text_idf' => $descTier > 0
                    ? $this->matchedIdf($descTokens, (string) ($service['service_description'] ?? ''), $idfs)
                    : 0.0,
                '_order' => $service['_order'],
            ];
        }

        usort($ranked, function ($a, $b) {
            foreach (['q_tier', 'q_matched', 'q_exact', 'desc_tier', 'desc_matched', 'desc_exact'] as $k) {
                if ($a[$k] !== $b[$k]) {
                    return $b[$k] <=> $a[$k];
                }
            }
            // Seri teks (tier + jumlah + exact sama): token langka
            // menang sebelum sinyal kelas/tarif — mis. "varicocele"
            // mengalahkan "laparoscopy" pada Row 25.
            if (abs($a['text_idf'] - $b['text_idf']) > 1e-9) {
                return $b['text_idf'] <=> $a['text_idf'];
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
            unset($row['_order'], $row['q_tier'], $row['q_matched'], $row['q_exact'], $row['desc_exact']);
            // Sinyal teks dipertahankan dengan nama publik agar UI bisa
            // memecah "% rekomendasi" (kelas+tarif) dari kecocokan teks,
            // dan processor bisa mensyaratkan bukti teks minimal (P5).

            return $row;
        }, array_slice($ranked, 0, $limit));
    }

    /**
     * Bobot IDF per token di atas pool recall: log((N+1)/(df+1)) + 1.
     * Token yang muncul di sedikit kandidat bernilai lebih tinggi.
     *
     * @param  array<int, string>  $tokens
     * @param  array<int, array{service_description: ?string}>  $services
     * @return array<string, float>
     */
    protected function idfWeights(array $tokens, array $services): array
    {
        $n = count($services);
        $weights = [];
        foreach ($tokens as $token) {
            $df = 0;
            foreach ($services as $service) {
                if (BridgeServiceSearch::rank([$token], (string) ($service['service_description'] ?? ''))[1] > 0) {
                    $df++;
                }
            }
            $weights[$token] = log(($n + 1) / ($df + 1)) + 1.0;
        }

        return $weights;
    }

    /**
     * Jumlah bobot IDF token query yang cocok di satu description
     * kandidat (aturan cocok = prefix persis seperti rank()).
     *
     * @param  array<int, string>  $tokens
     * @param  array<string, float>  $idfs
     */
    protected function matchedIdf(array $tokens, string $description, array $idfs): float
    {
        $normHay = BridgeServiceSearch::normalize($description);
        $hayWords = $normHay === ''
            ? []
            : array_values(array_filter(
                explode(' ', $normHay),
                fn ($w) => mb_strlen($w) >= 2
            ));
        $sum = 0.0;
        foreach ($tokens as $token) {
            foreach ($hayWords as $word) {
                if ($word === $token || str_starts_with($word, $token) || str_starts_with($token, $word)) {
                    $sum += $idfs[$token] ?? 0.0;
                    break;
                }
            }
        }

        return $sum;
    }

    /**
     * Ekstraksi inti tindakan: buang (...) nama dokter, potong sejak
     * kata-noise, lalu tangani delimiter TEPAT " - " (spasi-hyphen-spasi,
     * bukan "-" umum) sebagai SINYAL STRUKTUR tambahan:
     * - segmen PERTAMA = PREFIX SPESIALISASI bila memuat kata "bedah"
     *   ("BEDAH UMUM", "BEDAH TULANG / ORTOHOPEDI") lalu dibuang;
     * - segmen terakhir = ROLE/KONTEKS hanya bila dikenali (daftar
     *   eksplisit: Dokter Operator/Anestesi/..., Kamar/Ruang Operasi,
     *   ... — dalam bentuk ternormalisasi), lalu dibuang;
     * - sisa segmen DIGABUNG tanpa asumsi posisi (bukan "selalu segmen
     *   ke-2/ke-3/terakhir") — similarity existing yang memverifikasi
     *   mana yang cocok, exact/phrase/token tetap penentu utama.
     * Struktur tak jelas (satu segmen, sisa < 2 token utama) ->
     * description mentah (fallback aman).
     */
    protected function coreAction(string $description): string
    {
        $core = (string) preg_replace('/\([^)]*\)/u', ' ', $description);
        $core = trim((string) preg_replace(
            '/\b(anasthesy|anestesi|anastesi|anesthesi|anasthesi|narkose|sedasi|bius|dokter|operator|bidan|spesialis|dpjp|konsulen)\b.*/ius',
            '',
            $core
        ));
        if (str_contains($core, ' - ')) {
            $segments = array_values(array_filter(
                array_map(fn ($s) => trim((string) $s), explode(' - ', $core)),
                fn ($s) => $s !== ''
            ));
            if (count($segments) >= 2) {
                // Prefix spesialisasi ("BEDAH UMUM - ...",
                // "BEDAH TULANG / ORTOHOPEDI - ..."): segmen pertama yang
                // memuat kata "bedah" dibuang agar token generik tidak
                // mencemari query dan mendongkrak kandidat salah (Row 25:
                // "BEDAH UMUM" membuat Appendektomi Bedah Anak menang).
                $firstNorm = ' '.BridgeServiceSearch::normalize((string) $segments[0]).' ';
                if (str_contains($firstNorm, ' bedah ')) {
                    array_shift($segments);
                }
                if ($segments === []) {
                    return $description;
                }
                // Role yang dikenali (bentuk sudah dinormalisasi, karena
                // "&" hilang saat normalisasi: "kamar operasi sarana").
                $roles = [
                    'dokter operator', 'dokter anestesi', 'dokter umum',
                    'dokter spesialis', 'dokter', 'kamar operasi',
                    'kamar operasi sarana', 'ruang operasi', 'ruang bedah',
                ];
                if (in_array(BridgeServiceSearch::normalize((string) end($segments)), $roles, true)) {
                    array_pop($segments);
                }
                $joined = trim(implode(' ', $segments));
                if ($joined !== '') {
                    $joinedMains = array_values(array_filter(
                        BridgeServiceSearch::words($joined),
                        fn ($t) => mb_strlen($t) > 2
                    ));
                    if (count($joinedMains) >= 2) {
                        return $joined;
                    }

                    return $description;
                }
            }
        }
        $coreMains = array_values(array_filter(
            BridgeServiceSearch::words($core),
            fn ($t) => mb_strlen($t) > 2
        ));

        return count($coreMains) >= 2 ? $core : $description;
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
