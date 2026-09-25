<?php

namespace App\Services\Bridge\Ambiguous;

/**
 * Domain layer AMBIGUOUS: penskoran murni (tanpa DB, tanpa I/O).
 *
 * Sinyal penimbang untuk "pencarian service code" saat kandidat > 1:
 *  1. KELAS — kode kelas Excel (SERVICECODE KELAS, kode lama) vs kode
 *     kelas master kandidat. Exact match = +1.0. Sinyal kuat tapi jarang
 *     kena (kode lama umumnya beda dengan kode master).
 *  2. TARIF — tarif Excel (kolom TARIFF, opsional) vs daftar tarif master
 *     per pair. Skor = (1 - selisih relatif terkecil) x 2.0. Ini sinyal
 *     utama saat deskripsi+kelas sama persis (mis. CT001 vs CT002).
 *
 * Total = kelas*1.0 + tarif*2.0. Sort: total desc, selisih tarif asc
 * (null paling belakang), lalu service_code asc agar deterministik.
 */
final class AmbiguousRanker
{
    public const WEIGHT_CLASS = 1.0;

    public const WEIGHT_TARIFF = 2.0;

    /** Toleransi dianggap "sama persis" untuk auto-match (1%). */
    public const TARIFF_EXACT_TOLERANCE = 0.01;

    /** Gap minimal best vs runner-up agar berani memberi saran (5pp). */
    public const TARIFF_MIN_GAP = 0.05;

    /**
     * Gap minimal yang dilonggarkan bila best cocok PERSIS 0% dengan tarif
     * Excel (1pp): cocok persis adalah sinyal penentu, kecuali kandidat
     * lain juga berada dalam toleransi 1%.
     */
    public const TARIFF_MIN_GAP_WHEN_EXACT = 0.01;

    /**
     * Beri skor + metadata (_score, _class_match, _tariff_diff) lalu urutkan.
     *
     * @param  array<int, array<string, mixed>>  $candidates  dari repository
     * @param  array<string, mixed>  $normalized  hasil RowNormalizer
     * @return array<int, array<string, mixed>>
     */
    public function rank(array $candidates, array $normalized): array
    {
        $excelClass = mb_strtoupper(trim((string) ($normalized['service_class_code'] ?? '')));
        // WAJIB effective_tariff (mencakup TARIFF Excel, TOTAL BILLED /
        // QUANTITY, maupun fallback grup); fallback ke tariff mentah hanya
        // untuk pemanggil lama yang belum menyediakan effective_tariff.
        $excelTariff = $normalized['effective_tariff'] ?? ($normalized['tariff'] ?? null);
        $excelTariff = is_numeric($excelTariff) ? (float) $excelTariff : null;
        if ($excelTariff !== null && $excelTariff <= 0) {
            $excelTariff = null;
        }

        $ranked = [];
        foreach ($candidates as $candidate) {
            $candidateClass = mb_strtoupper(trim((string) ($candidate['class_code'] ?? '')));
            $classMatch = ($excelClass !== '' && $candidateClass !== '' && $excelClass === $candidateClass) ? 1 : 0;

            $tariffDiff = $this->bestTariffDiff($excelTariff, $candidate['tariffs'] ?? ($candidate['tariff'] ?? null));
            $tariffScore = $tariffDiff === null ? 0.0 : 1.0 - $tariffDiff;

            $ranked[] = array_merge($candidate, [
                '_class_match' => $classMatch,
                '_tariff_diff' => $tariffDiff,
                '_score' => $classMatch * self::WEIGHT_CLASS + $tariffScore * self::WEIGHT_TARIFF,
            ]);
        }

        usort($ranked, function ($a, $b) {
            if ($a['_score'] !== $b['_score']) {
                return $b['_score'] <=> $a['_score'];
            }
            // Selisih tarif kecil dulu; null (tanpa sinyal) paling belakang.
            $da = $a['_tariff_diff'] ?? PHP_FLOAT_MAX;
            $db = $b['_tariff_diff'] ?? PHP_FLOAT_MAX;
            if ($da !== $db) {
                return $da <=> $db;
            }

            return [$a['service_code'], $a['class_code']] <=> [$b['service_code'], $b['class_code']];
        });

        return $ranked;
    }

    /**
     * Konservatif: saran hanya bila tarif Excel ada, kandidat terbaik
     * nyaris sama persis (<= 1%) DAN runner-up jelas lebih jauh, serta
     * tidak menabrak sinyal kelas (best.class >= runner-up.class).
     * Gap yang dituntut berlapis: cocok PERSIS 0% cukup gap >= 1pp
     * (sinyal penentu, kecuali kandidat lain juga dalam 1%), sedangkan
     * yang hanya dekat (<= 1%) tetap butuh gap >= 5pp.
     * Seri tarif (selisih sama persis): penentu = tanggal master terbaru
     * (valid_from maksimal per pair); bila tanggal juga seri/tak ada ->
     * null = tetap AMBIGUOUS tanpa saran.
     * Hasil: rekomendasi saja (status scan selalu tetap AMBIGUOUS).
     *
     * @param  array<int, array<string, mixed>>  $ranked  hasil rank()
     * @param  array<string, mixed>  $normalized
     */
    public function shouldAutoMatch(array $ranked, array $normalized): ?array
    {
        $excelTariff = $normalized['effective_tariff'] ?? ($normalized['tariff'] ?? null);
        $excelTariff = is_numeric($excelTariff) ? (float) $excelTariff : null;
        if ($excelTariff === null || $excelTariff <= 0 || count($ranked) < 2) {
            return null;
        }

        $best = $ranked[0];
        $second = $ranked[1];
        $bestDiff = $best['_tariff_diff'] ?? null;
        $secondDiff = $second['_tariff_diff'] ?? null;
        if ($bestDiff === null || $bestDiff > self::TARIFF_EXACT_TOLERANCE) {
            return null;
        }
        if (($best['_class_match'] ?? 0) < ($second['_class_match'] ?? 0)) {
            return null;
        }
        $requiredGap = $bestDiff <= 1e-9 ? self::TARIFF_MIN_GAP_WHEN_EXACT : self::TARIFF_MIN_GAP;
        if ($secondDiff === null || ($secondDiff - $bestDiff) >= $requiredGap) {
            $best['_decided_by'] = 'tariff';

            return $best;
        }

        return $this->newestByMasterDate($ranked, $bestDiff);
    }

    /**
     * Tie-break seri tarif: di antara kandidat yang selisih tarifnya SAMA
     * PERSIS dengan yang terbaik (dan dalam toleransi), pilih SATU yang
     * tanggal master-nya (valid_from) paling baru. null bila tanggal tak
     * ada/seri, atau sinyal kelas bertentangan.
     *
     * @param  array<int, array<string, mixed>>  $ranked  hasil rank()
     */
    protected function newestByMasterDate(array $ranked, float $bestDiff): ?array
    {
        $contenders = [];
        foreach ($ranked as $candidate) {
            $diff = $candidate['_tariff_diff'] ?? null;
            if ($diff === null || abs($diff - $bestDiff) > 1e-9) {
                continue;
            }
            $date = trim((string) ($candidate['valid_from'] ?? ''));
            if ($date === '') {
                return null;
            }
            $contenders[] = $candidate + ['_valid_from' => $date];
        }
        if (count($contenders) < 2) {
            return null;
        }
        usort($contenders, fn ($a, $b) => $b['_valid_from'] <=> $a['_valid_from']);
        if ($contenders[0]['_valid_from'] === $contenders[1]['_valid_from']) {
            return null;
        }
        $winner = $contenders[0];
        foreach ($contenders as $other) {
            if (($winner['_class_match'] ?? 0) < ($other['_class_match'] ?? 0)) {
                return null;
            }
        }
        $winner['_decided_by'] = 'master_date';

        return $winner;
    }

    /**
     * Penjelasan analisa (Indonesia) untuk modal detail per baris.
     * Menyebut jumlah kandidat, sinyal yang dipakai (kelas + tarif),
     * dan alasan ada/tidaknya rekomendasi.
     *
     * @param  array<int, array<string, mixed>>  $ranked  hasil rank()
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>|null  $suggested  hasil shouldAutoMatch()
     */
    public function explain(array $ranked, array $normalized, ?array $suggested): string
    {
        $n = count($ranked);
        $excelTariff = $normalized['effective_tariff'] ?? ($normalized['tariff'] ?? null);
        $excelTariff = is_numeric($excelTariff) ? (float) $excelTariff : null;
        $sourceLabel = \App\Services\Bridge\BridgeTarifEffectiveTariff::sourceLabel(
            $normalized['tariff_source'] ?? null
        );
        $base = "Ditemukan {$n} kandidat master dengan description + kelas yang sama setelah normalisasi, sehingga baris ini berstatus AMBIGUOUS dan tidak otomatis dipetakan. ";
        $base .= 'Kandidat diurutkan berdasarkan kecocokan kode kelas lama Excel (bobot 1,0) dan kedekatan tarif efektif vs tarif master (bobot 2,0). ';

        if ($excelTariff === null || $excelTariff <= 0) {
            return $base.'Tarif tidak tersedia (TARIFF kosong dan TOTAL BILLED ÷ QUANTITY tidak bisa dihitung) sehingga pembanding tarif tidak digunakan — urutan hanya mengandalkan kecocokan kelas lalu kode service. Lengkapi tarif atau pilih manual.';
        }

        if ($suggested !== null) {
            $code = $suggested['service_code'] ?? '-';
            $diff = $suggested['_tariff_diff'] ?? null;
            $pct = $diff === null ? '?' : number_format($diff * 100, 2, ',', '.').'%';
            $reason = "selisih tarif {$pct} terhadap tarif efektif Rp ".number_format($excelTariff, 0, ',', '.')." sumber {$sourceLabel}, paling dekat dan gap jelas dari kandidat lain";
            if (($suggested['_decided_by'] ?? null) === 'master_date') {
                $date = trim((string) ($suggested['valid_from'] ?? $suggested['_valid_from'] ?? ''));
                $reason = "tarif seri dengan kandidat lain (selisih {$pct}), dipilih yang periode master terbaru"
                    .($date !== '' ? " (berlaku sejak {$date})" : '');
            }

            return $base."Rekomendasi: {$code} ({$reason}). Rekomendasi ini langsung masuk ke New Code, tetapi status tetap AMBIGUOUS — periksa dan timpa via manual bila tidak setuju.";
        }

        $best = $ranked[0] ?? null;
        $second = $ranked[1] ?? null;
        if ($best !== null && $second !== null) {
            $bd = $best['_tariff_diff'] ?? null;
            $sd = $second['_tariff_diff'] ?? null;
            if ($bd !== null && $bd <= self::TARIFF_EXACT_TOLERANCE) {
                $need = $bd <= 1e-9 ? self::TARIFF_MIN_GAP_WHEN_EXACT : self::TARIFF_MIN_GAP;
                $needPct = rtrim(rtrim(number_format($need * 100, 2, ',', '.'), '0'), ',');
                if ($sd !== null && ($sd - $bd) < $need) {
                    return $base."Belum ada rekomendasi: dua kandidat teratas selisih tarifnya terlalu dekat (gap < {$needPct}pp), sehingga tidak ada pemenang yang jelas. Bandingkan tabel di bawah lalu pilih manual.";
                }
            }
            if ($bd !== null && $bd > self::TARIFF_EXACT_TOLERANCE) {
                return $base.'Belum ada rekomendasi: tarif Excel tidak cocok dekat (<= 1%) dengan kandidat mana pun. Periksa kemungkinan tarif berubah / beda periode, lalu pilih manual.';
            }
        }

        return $base.'Belum ada rekomendasi yang cukup yakin. Bandingkan tabel di bawah lalu pilih manual.';
    }

    /**
     * Selisih relatif terkecil Excel vs daftar tarif master:
     * |excel - master| / max(excel, master). null bila tak ada sinyal.
     *
     * @param  array<int, float>|float|null  $masterTariffs
     */
    public function bestTariffDiff(?float $excelTariff, mixed $masterTariffs): ?float
    {
        if ($excelTariff === null || $excelTariff <= 0) {
            return null;
        }
        $list = is_array($masterTariffs) ? $masterTariffs : [$masterTariffs];
        $list = array_values(array_filter(
            $list,
            fn ($t) => is_numeric($t) && (float) $t > 0
        ));
        if ($list === []) {
            return null;
        }

        $best = null;
        foreach ($list as $master) {
            $master = (float) $master;
            $diff = abs($excelTariff - $master) / max($excelTariff, $master);
            if ($best === null || $diff < $best) {
                $best = $diff;
            }
        }

        return $best;
    }
}
