<?php

namespace App\Services\Bridge;

use App\Models\Service;

/**
 * Pencarian service master untuk pemetaan manual NOT_FOUND.
 *
 * WORD/TOKEN BASED, bukan fuzzy per karakter:
 * - Description/query dibersihkan dulu: seluruh blok [...] beserta
 *   isinya DIHAPUS sebelum tokenisasi ("ANTEBRACHI DEXTRA [dr. X]"
 *   -> ["antebrachi", "dextra"]). Data master TIDAK diubah, hanya
 *   sisi pencarian.
 * - Query dinormalisasi (lowercase, trim, spasi ganda -> satu) lalu
 *   ditokenisasi per whitespace. Token < 2 huruf dibuang; numerik
 *   >= 2 digit ("22", "75x75") tetap lolos.
 * - Recall: prefilter LIKE PER TOKEN (OR) di DB, pool max 500 —
 *   LIKE tidak pernah dipakai untuk frasa utuh ("LIKE '%kamar
 *   operasi%'") dan tidak menentukan urutan. Pipeline:
 *   description -> hapus [...] -> normalize -> tokenize ->
 *   SQL broad recall -> rank PHP -> filter threshold -> sort -> LIMIT.
 * - Ranking (relevance) dihitung di PHP per kandidat, HANYA terhadap
 *   DESCRIPTION master (nama tidak ikut; kode hanya data hasil):
 *     5 = exact: description sama persis dengan query setelah normalisasi
 *     4 = frasa query muncul persis berurutan, atau semua token
 *         ditemukan dan berurutan (query 1 kata yang cocok = tier ini)
 *     3 = semua token ditemukan tetapi tidak berurutan
 *     2 = sebagian besar token ditemukan (>= setengah, tidak semua)
 *     1 = sebagian kecil token ditemukan (< setengah)
 *     0 = tidak ada yang cocok (tidak ditampilkan)
 *   Dalam tier yang sama: lebih banyak token cocok dulu, lalu kode.
 *   Jumlah token selalu memengaruhi ranking: 4/4 > 2/4 > 1/4.
 * - Mode default (klik tanpa mengetik) KETAT: partial tanpa satu pun
 *   whole-word match persis dibuang (prefix-only tidak cukup).
 *   Bila ketat menghasilkan kosong dan token >= 3, dilonggarkan sekali.
 *   Mode mengetik (q): prefix matching biasa, q primer + description
 *   sekunder.
 * - Cocok per token = sama persis atau prefix (salah satu mengawali
 *   yang lain, mis. "mri" ~ "mri001"). Substring tengah ("tas" di
 *   "instalasi", "antebrachi" vs "anestesi") TIDAK dihitung. Kata
 *   1 huruf di sisi data diabaikan; bila query memuat token utama
 *   (> 2 huruf), kandidat wajib cocok minimal satu token utama —
 *   token pendek ("II") hanya bonus, dan dikeluarkan dari recall
 *   LIKE bila ada token utama.
 *
 * Catatan performa (PostgreSQL, 13rb+ service): prefilter hanya
 * mengambil max 500 kandidat dalam SATU query (bukan per token/
 * per karakter); penskoran O(kandidat x token) di PHP dalam
 * milidetik. Bila data tumbuh ke jutaan row, pertimbangkan
 * pg_trgm + index GIN untuk prefilter (ranking PHP tetap sama).
 */
class BridgeServiceSearch
{
    public const TIER_EXACT = 5;

    public const TIER_ALL_ORDERED = 4;

    public const TIER_ALL_UNORDERED = 3;

    public const TIER_MOST = 2;

    public const TIER_FEW = 1;

    /**
     * Normalisasi: HAPUS dulu seluruh blok [...] beserta isinya (nama
     * dokter, kelas, keterangan — tidak boleh memengaruhi similarity),
     * lalu lowercase + trim + spasi ganda menjadi satu +
     * non-alfanumerik menjadi pemisah spasi.
     * "ANTEBRACHI DEXTRA [Adhi Rommy S., dr., Sp. Rad]" -> "antebrachi dextra".
     */
    public static function normalize(string $text): string
    {
        $text = (string) preg_replace('/\[[^\]]*\]/u', ' ', $text);
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Pecah teks menjadi token unik (min. 2 karakter agar kata
     * penghubung 1 huruf terbuang).
     *
     * @return array<int, string>
     */
    public static function words(string $text): array
    {
        $words = array_unique(array_filter(
            explode(' ', self::normalize($text)),
            fn ($w) => mb_strlen($w) >= 2
        ));

        return array_values($words);
    }

    /**
     * Satu token query cocok dengan satu kata data bila sama persis
     * atau salah satunya mengawali yang lain (prefix, mis. "mri" ~
     * "mri001"). Bukan substring tengah.
     */
    protected static function tokenMatches(string $token, string $word): bool
    {
        return $word === $token
            || str_starts_with($word, $token)
            || str_starts_with($token, $word);
    }

    /**
     * Nilai relevance [tier, matched, hasExact] token query terhadap
     * satu teks data. $hasExact = ada token yang sama persis dengan
     * kata data (whole-word, bukan sekadar prefix) — dipakai sebagai
     * syarat tampil untuk partial pada default suggestion.
     *
     * @param  array<int, string>  $queryTokens
     * @return array{int, int, bool} [tier, jumlah token cocok, ada exact-word]
     */
    public static function rank(array $queryTokens, string $haystack): array
    {
        if ($queryTokens === []) {
            return [0, 0, false];
        }

        $normHay = self::normalize($haystack);
        // Kata 1 huruf di sisi data diabaikan (simetris dengan words()
        // yang membuang token 1 huruf di sisi query): artefak seperti
        // "s" dari "Ladd's" tidak boleh mem-prefix-match semua token.
        $hayWords = $normHay === ''
            ? []
            : array_values(array_filter(
                explode(' ', $normHay),
                fn ($w) => mb_strlen($w) >= 2
            ));
        $total = count($queryTokens);

        // Frasa query muncul persis berurutan dengan batas kata.
        if ($total > 1) {
            $phrase = implode(' ', $queryTokens);
            if (preg_match('/(?<!\w)'.preg_quote($phrase, '/').'(?!\w)/', $normHay)) {
                return [self::TIER_ALL_ORDERED, $total, true];
            }
        }

        // Petakan tiap token ke indeks kata yang cocok (greedy: kemunculan
        // pertama = posisi paling awal, optimal untuk deteksi urutan).
        $matched = 0;
        $hasExact = false;
        $positions = [];
        $matchedTokens = [];
        foreach ($queryTokens as $token) {
            $foundAt = null;
            foreach ($hayWords as $i => $word) {
                if ($word === $token) {
                    $foundAt = $i;
                    $hasExact = true;
                    break;
                }
                if ($foundAt === null && self::tokenMatches($token, $word)) {
                    $foundAt = $i;
                }
            }
            if ($foundAt === null) {
                continue;
            }
            $matched++;
            $matchedTokens[] = $token;
            $positions[] = $foundAt;
        }

        if ($matched === 0) {
            return [0, 0, false];
        }

        // Token pendek (<= 2 huruf, mis. "II") hanya bonus: bila query
        // memuat token utama (> 2 huruf, mis. SURSHIELD/SURFLO/SAFETY),
        // kandidat wajib cocok minimal satu token utama. Tanpa ini,
        // "SURSHIELD SURFLO II SAFETY" menampilkan baris yang hanya
        // cocok "II" ~ "III". Bila seluruh token pendek (mis. query
        // "AB 12"), aturan ini dilewati seperti behavior lama.
        $hasMainMatch = false;
        $hasMainToken = false;
        foreach ($queryTokens as $token) {
            if (mb_strlen($token) <= 2) {
                continue;
            }
            $hasMainToken = true;
            if (in_array($token, $matchedTokens, true)) {
                $hasMainMatch = true;
                break;
            }
        }
        if ($hasMainToken && ! $hasMainMatch) {
            return [0, 0, false];
        }

        if ($matched === $total) {
            $ordered = true;
            foreach ($positions as $i => $pos) {
                if ($i > 0 && $pos <= $positions[$i - 1]) {
                    $ordered = false;
                    break;
                }
            }

            return [$ordered ? self::TIER_ALL_ORDERED : self::TIER_ALL_UNORDERED, $matched, $hasExact];
        }

        // Sebagian: >= setengah = TIER_MOST, di bawah itu TIER_FEW.
        return [$matched / $total >= 0.5 ? self::TIER_MOST : self::TIER_FEW, $matched, $hasExact];
    }

    /**
     * Rank satu kandidat terhadap query mentah, HANYA berdasarkan
     * DESCRIPTION master (mode NOT_FOUND). service_name tidak dipakai
     * agar nama generik ("... III") tidak mengangkat kandidat tak
     * relevan; code hanya data hasil (tetap dikembalikan, tidak
     * diranking). $code/$name dipertahankan di signature untuk
     * kompatibilitas pemanggil.
     * Tier 5 bila description sama persis dengan query setelah
     * normalisasi (mis. description "Steri Green S-22 75x75" vs master
     * "Steri Green S 22 75x75" — keduanya ternormalisasi identik).
     *
     * @param  array<int, string>  $queryTokens
     * @return array{int, int, bool} [tier, jumlah token cocok, ada exact-word]
     */
    public static function rankCandidate(array $queryTokens, string $queryRaw, string $code, ?string $name, ?string $description): array
    {
        $normQuery = self::normalize($queryRaw);
        if ($normQuery !== '' && self::normalize((string) $description) === $normQuery) {
            return [self::TIER_EXACT, count($queryTokens), true];
        }

        return self::rank($queryTokens, (string) $description);
    }

    /**
     * Skor lama (jumlah kata cocok) — dipertahankan untuk kompatibilitas,
     * didelegasikan ke rank().
     */
    public static function score(array $queryWords, array $hayWords): int
    {
        return self::rank($queryWords, implode(' ', $hayWords))[1];
    }

    /**
     * @return array<int, array{service_code: string, service_name: ?string, service_description: ?string}>
     */
    public static function search(string $query, int $limit = 20): array
    {
        $queryTokens = self::words($query);
        if ($queryTokens === []) {
            return [];
        }

        $rows = self::prefilter($queryTokens);

        $scored = [];
        foreach ($rows as $service) {
            [$tier, $matched] = self::rankCandidate(
                $queryTokens, $query, $service->code, $service->name, $service->description
            );
            if ($tier > 0) {
                $scored[] = [$tier, $matched, (string) $service->code, $service];
            }
        }

        usort($scored, fn ($a, $b) => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);

        return array_map(
            fn ($row) => [
                'service_code' => $row[3]->code,
                'service_name' => $row[3]->name,
                'service_description' => $row[3]->description,
            ],
            array_slice($scored, 0, $limit)
        );
    }

    /**
     * Kandidat paling mirip dengan DESCRIPTION baris Excel (grup NOT_FOUND).
     * Dipakai mengisi daftar awal saat select diklik (q kosong), atau
     * dikombinasi saat user mengetik: q menyaring, description menentukan
     * urutan kemiripan.
     *
     * Mode default (tanpa ketikan) KETAT: partial (tidak semua token cocok)
     * hanya tampil bila ada whole-word match persis; prefix-only dibuang.
     * Bila mode ketat menghasilkan KOSONG dan description >= 3 token,
     * dilonggarkan sekali (prefix partial diizinkan) agar tetap ada saran.
     * Mode mengetik (q terisi): prefix matching seperti biasa.
     *
     * @return array<int, array{service_code: string, service_name: ?string, service_description: ?string}>
     */
    public static function similar(string $description, ?string $query = null, int $limit = 20): array
    {
        $descTokens = self::words($description);
        if ($descTokens === []) {
            return [];
        }
        $query = $query ?? '';
        $queryTokens = self::words($query);

        // Prefilter: kandidat harus mengandung kata description (agar pool
        // relevan), dan bila user mengetik juga harus mengandung kata q.
        $rows = self::prefilter($descTokens, $queryTokens === [] ? null : $queryTokens);

        $scored = [];
        foreach ($rows as $service) {
            [$descTier, $descMatched, $descExact] = self::rankCandidate(
                $descTokens, $description, $service->code, $service->name, $service->description
            );
            if ($queryTokens !== []) {
                [$qTier, $qMatched] = self::rankCandidate(
                    $queryTokens, $query, $service->code, $service->name, $service->description
                );
                if ($qTier === 0) {
                    continue;
                }
                $scored[] = [$qTier, $qMatched, $descTier, $descMatched, $descExact, (string) $service->code, $service];
            } elseif ($descTier > 0) {
                $scored[] = [0, 0, $descTier, $descMatched, $descExact, (string) $service->code, $service];
            }
        }

        if ($queryTokens === []) {
            // Mode default ketat: buang partial yang hanya prefix (tanpa
            // satu pun whole-word match). Semua-token-cocok (tier >= 3)
            // selalu lolos.
            $strict = array_values(array_filter(
                $scored,
                fn ($row) => $row[2] >= self::TIER_ALL_UNORDERED || $row[4]
            ));
            if ($strict !== [] || count($descTokens) < 3) {
                $scored = $strict;
            }
            // else: fallback longgar (prefix partial diizinkan).
        }

        usort($scored, fn ($a, $b) => [$b[0], $b[1], $b[2], $b[3], $a[5]] <=> [$a[0], $a[1], $a[2], $a[3], $b[5]]);

        return array_map(
            fn ($row) => [
                'service_code' => $row[6]->code,
                'service_name' => $row[6]->name,
                'service_description' => $row[6]->description,
            ],
            array_slice($scored, 0, $limit)
        );
    }

    /**
     * Recall pool: baris yang mengandung SALAH SATU token (OR) — hanya
     * untuk recall, urutan ditentukan rank(). Bila $andTokens diisi,
     * kandidat juga wajib mengandung salah satu tokennya.
     * Token pendek (<= 2 huruf, mis. "II") dikeluarkan dari recall bila
     * ada token utama: LIKE '%ii%' mengenai ribuan baris dan bisa
     * mendesak kandidat relevan keluar dari pool 500.
     *
     * @param  array<int, string>  $orTokens
     * @param  array<int, string>|null  $andTokens
     * @return \Illuminate\Support\Collection<int, Service>
     */
    protected static function prefilter(array $orTokens, ?array $andTokens = null)
    {
        $long = array_values(array_filter($orTokens, fn ($t) => mb_strlen($t) > 2));
        if ($long !== []) {
            $orTokens = $long;
        }
        if ($andTokens !== null) {
            $longAnd = array_values(array_filter($andTokens, fn ($t) => mb_strlen($t) > 2));
            if ($longAnd !== []) {
                $andTokens = $longAnd;
            }
        }

        $query = Service::query();
        $query->where(function ($w) use ($orTokens) {
            foreach (array_slice($orTokens, 0, 6) as $token) {
                $like = '%'.$token.'%';
                $w->orWhereRaw('LOWER(code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$like]);
            }
        });
        if ($andTokens !== null && $andTokens !== []) {
            $query->where(function ($w) use ($andTokens) {
                foreach (array_slice($andTokens, 0, 6) as $token) {
                    $like = '%'.$token.'%';
                    $w->orWhereRaw('LOWER(code) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(description) LIKE ?', [$like]);
                }
            });
        }

        return $query->limit(500)->get(['code', 'name', 'description']);
    }
}
