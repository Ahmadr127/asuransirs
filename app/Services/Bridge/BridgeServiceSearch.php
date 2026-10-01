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
 * - Recall: prefilter LIKE PER TOKEN (OR) di DB dengan isi kuota
 *   TERURUT SKOR trigram (PostgreSQL + index GIN) — LIKE tidak pernah
 *   dipakai untuk frasa utuh ("LIKE '%kamar
 *   operasi%'") dan tidak menentukan urutan akhir. Token keluarga peran
 *   ("anestesi") dikeluarkan dari recall (recallTokens) agar tidak
 *   membanjiri pool. Pipeline:
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
 * - Sumbu ranking tersendiri: kecocokan KODE (codeScore 0/1/2) diurut
 *   sebelum tier description agar pencarian kode ("CT001", "KMK2")
 *   diranking berdasarkan code; presisi (unmatchedExtras: kata master
 *   yang tak dicari, makin kecil makin baik) menjadi tie-break sebelum
 *   kode ASC agar atribut tambahan yang tak dicari tidak mengalahkan
 *   exact action.
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
     * Stopword taksonomi master — nilai default; efektif dibaca dari
     * config('bridge.search.stopwords') agar kasus baru cukup edit
     * config tanpa sentuh kode. Hanya untuk token query description
     * NOT_FOUND, bukan ketikan user (q) dan bukan sisi data.
     */
    public const STOPWORDS_DESC = [
        'golongan', 'tindakan', 'medis', 'besar', 'khusus',
        'kecil', 'sedang', 'umum', 'layanan', 'jasa',
    ];

    /**
     * Token keluarga peran — nilai default; efektif dibaca dari
     * config('bridge.search.recall_excluded_role'). Dikeluarkan dari
     * recall SAJA; scoring tetap memakai token penuh.
     */
    public const RECALL_EXCLUDED_ROLE = [
        'anestesi', 'anasthesy', 'anasthesi', 'anesthesia', 'anesthesy',
        'anastesi', 'narkose', 'sedasi',
    ];

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
     * Alias klinis Inggris -> istilah master — nilai default; efektif
     * dibaca dari config('bridge.search.clinical_aliases').
     * Query-side saja (words() hanya dipakai untuk token query).
     */
    public const CLINICAL_ALIASES = [
        'varicocelectomy' => 'varicocele',
        'anasthesy' => 'anestesi',
        'anasthesi' => 'anestesi',
        'anesthesia' => 'anestesi',
        'anesthesy' => 'anestesi',
        'ortohopedi' => 'ortopedi',
        'sedasi' => 'anestesi',
        'narkose' => 'anestesi',
        'bius' => 'anestesi',
    ];

    /** @return array<int, string> */
    protected static function stopwords(): array
    {
        $configured = config('bridge.search.stopwords');

        return is_array($configured) && $configured !== [] ? array_values($configured) : self::STOPWORDS_DESC;
    }

    /** @return array<string, string> */
    protected static function aliases(): array
    {
        $configured = config('bridge.search.clinical_aliases');

        return is_array($configured) && $configured !== [] ? $configured : self::CLINICAL_ALIASES;
    }

    /** @return array<int, string> */
    protected static function recallExcluded(): array
    {
        $configured = config('bridge.search.recall_excluded_role');

        return is_array($configured) && $configured !== [] ? array_values($configured) : self::RECALL_EXCLUDED_ROLE;
    }

    /**
     * Pecah teks menjadi token unik. Kata 1 huruf dibuang, tetapi digit
     * 1 angka dipertahankan ("IGD 2" vs "IGD 3", "Kelas 1/2/3" — Row 15).
     * Token query dinormalisasi via alias klinis (config).
     *
     * @return array<int, string>
     */
    public static function words(string $text): array
    {
        $words = array_unique(array_filter(
            array_map(
                fn ($w) => self::clinicalAlias($w),
                explode(' ', self::normalize($text))
            ),
            fn ($w) => mb_strlen($w) >= 2 || ctype_digit($w)
        ));

        return array_values($words);
    }

    /**
     * Petakan satu token ke istilah master: alias config dulu (nilai
     * di-lowercase agar tulisan config tak merusak kecocokan
     * case-sensitive), lalu generik akhiran "-ectomy" ke akarnya.
     */
    public static function clinicalAlias(string $token): string
    {
        $aliases = self::aliases();
        if (isset($aliases[$token])) {
            return mb_strtolower((string) $aliases[$token]);
        }
        if (mb_strlen($token) > 10 && str_ends_with($token, 'ectomy')) {
            $root = substr($token, 0, -6);

            return mb_strlen($root) >= 4 ? $root : $token;
        }

        return $token;
    }

    /**
     * Token isi untuk query description NOT_FOUND: words() minus
     * stopword (config). Fallback ke words() bila habis semua.
     *
     * @return array<int, string>
     */
    public static function contentWords(string $text): array
    {
        $words = self::words($text);
        $stopwords = self::stopwords();
        $filtered = array_values(array_filter(
            $words,
            fn ($w) => ! in_array($w, $stopwords, true)
        ));

        return $filtered !== [] ? $filtered : $words;
    }

    /**
     * Token recall (klausa OR LIKE): contentWords minus keluarga peran
     * (config). Fallback ke token penuh bila habis.
     *
     * @param  array<int, string>  $tokens
     * @return array<int, string>
     */
    public static function recallTokens(array $tokens): array
    {
        $excluded = self::recallExcluded();
        $filtered = array_values(array_filter(
            $tokens,
            fn ($w) => ! in_array($w, $excluded, true)
        ));

        return $filtered !== [] ? $filtered : $tokens;
    }

    /**
     * True bila ada kata teks data yang diawali token query DENGAN
     * perpanjangan >= 3 huruf (bentuk penuh klinis: "varicocelectomy"
     * memuat "varicocele"). Varian pendek (+1/+2 huruf, mis. "interna"
     * vs "internal") sering kali kata berbeda sehingga tidak dihitung.
     * Dipakai sebagai exact di gerbang ketat similar() dan ranking
     * suggest(): bukti akarnya sama kuat dengan kata persis. Arah
     * sebaliknya (token memuat kata) tidak dihitung: query yang lebih
     * panjang dari kata data adalah klaim lebih lemah.
     *
     * @param  array<int, string>  $tokens
     */
    public static function extendsToken(array $tokens, string $haystack): bool
    {
        $normHay = self::normalize($haystack);
        if ($normHay === '') {
            return false;
        }
        $hayWords = array_filter(
            explode(' ', $normHay),
            fn ($w) => mb_strlen($w) >= 2
        );
        foreach ($tokens as $token) {
            foreach ($hayWords as $word) {
                if ($word !== $token
                    && str_starts_with($word, $token)
                    && mb_strlen($word) - mb_strlen($token) >= 3
                ) {
                    return true;
                }
            }
        }

        return false;
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
     * Skor kecocokan kode service terhadap query mentah, TANPA daftar kata:
     * 2 = kode sama persis (spasi diabaikan, mis. "CT001"),
     * 1 = salah satunya mengawali yang lain (mis. "CT00" ~ "CT001"),
     * 0 = tidak ada hubungan kode. Dipakai sebagai sumbu ranking
     * tersendiri agar pencarian kode diranking berdasarkan code,
     * bukan tenggelam di ranking description.
     */
    public static function codeScore(string $queryRaw, ?string $code): int
    {
        $q = (string) preg_replace('/\s+/', '', self::normalize($queryRaw));
        $c = (string) preg_replace('/\s+/', '', self::normalize((string) $code));
        if ($q === '' || $c === '') {
            return 0;
        }
        if ($c === $q) {
            return 2;
        }
        if (str_starts_with($c, $q) || str_starts_with($q, $c)) {
            return 1;
        }

        return 0;
    }

    /**
     * Jumlah kata description master yang TIDAK dicari user (presisi):
     * kata description (panjang ≥2 / digit) yang tak cocok dengan satu
     * pun token query. Makin kecil makin baik — kandidat yang action-nya
     * persis tanpa atribut tambahan yang tak dicari menang atas yang
     * beratribut ekstra. Murni hitungan token, tanpa daftar kata,
     * tanpa stopword (kata taksonomi yang sama-sama ada di semua
     * kandidat sekeluarga saling meniadakan; yang membedakan justru
     * kata atribut pembeda seperti peran/komponen).
     *
     * @param  array<int, string>  $queryTokens
     */
    public static function unmatchedExtras(array $queryTokens, ?string $haystack): int
    {
        if ($queryTokens === []) {
            return 0;
        }
        $normHay = self::normalize((string) $haystack);
        if ($normHay === '') {
            return 0;
        }
        $hayWords = array_values(array_filter(
            explode(' ', $normHay),
            fn ($w) => mb_strlen($w) >= 2 || ctype_digit($w)
        ));
        $extras = 0;
        foreach ($hayWords as $word) {
            $hit = false;
            foreach ($queryTokens as $token) {
                if (self::tokenMatches($token, $word)) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                $extras++;
            }
        }

        return $extras;
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
        // Simetris dengan words(): kata 1 huruf dibuang, digit 1 angka
        // dipertahankan ("IGD 2" vs "IGD 3" harus terbedakan).
        $hayWords = $normHay === ''
            ? []
            : array_values(array_filter(
                explode(' ', $normHay),
                fn ($w) => mb_strlen($w) >= 2 || ctype_digit($w)
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
     * Elemen ke-4 = codeScore (0/1/2, lihat codeScore()): sumbu
     * ranking tersendiri untuk pencarian kode ("CT001", "KMK2").
     *
     * @param  array<int, string>  $queryTokens
     * @return array{int, int, bool, int} [tier, jumlah token cocok, ada exact-word, skor kode]
     */
    public static function rankCandidate(array $queryTokens, string $queryRaw, string $code, ?string $name, ?string $description): array
    {
        $codeScore = self::codeScore($queryRaw, $code);
        $normQuery = self::normalize($queryRaw);
        if ($normQuery !== '' && self::normalize((string) $description) === $normQuery) {
            return [self::TIER_EXACT, count($queryTokens), true, $codeScore];
        }

        [$tier, $matched, $hasExact] = self::rank($queryTokens, (string) $description);

        return [$tier, $matched, $hasExact, $codeScore];
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
            [$tier, $matched, , $codeScore] = self::rankCandidate(
                $queryTokens, $query, $service->code, $service->name, $service->description
            );
            if ($tier > 0 || $codeScore > 0) {
                $scored[] = [$codeScore, $tier, $matched,
                    self::unmatchedExtras($queryTokens, (string) $service->description),
                    (string) $service->code, $service];
            }
        }

        // Urutan evidence: kecocokan kode dulu (pencarian "CT001"/"KMK2"),
        // lalu tier + coverage description, lalu presisi (extras kecil dulu),
        // lalu kode ASC agar deterministik.
        usort($scored, fn ($a, $b) => [$b[0], $b[1], $b[2], $a[3], $a[4]] <=> [$a[0], $a[1], $a[2], $b[3], $b[4]]);

        return array_map(
            fn ($row) => [
                'service_code' => $row[5]->code,
                'service_name' => $row[5]->name,
                'service_description' => $row[5]->description,
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
     * hanya tampil bila ada whole-word match persis atau bentuk penuh
     * (extendsToken); prefix-only selain itu dibuang.
     * Bila mode ketat menghasilkan KOSONG dan description >= 3 token,
     * dilonggarkan sekali (prefix partial diizinkan) agar tetap ada saran.
     * Mode mengetik (q terisi): prefix matching seperti biasa.
     *
     * @return array<int, array{service_code: string, service_name: ?string, service_description: ?string}>
     */
    public static function similar(string $description, ?string $query = null, int $limit = 20): array
    {
        // Token description memakai contentWords (stopword taksonomi
        // dibuang) agar kata generik ("umum", "tindakan", ...) tidak
        // mendongkrak kandidat salah. Token ketikan user (q) tetap
        // words() penuh agar pencarian eksplisit tetap presisi.
        $descTokens = self::contentWords($description);
        if ($descTokens === []) {
            return [];
        }
        $query = $query ?? '';
        $queryTokens = self::words($query);

        // Prefilter: kandidat harus mengandung kata description (agar pool
        // relevan), dan bila user mengetik juga harus mengandung kata q.
        // Recall memakai recallTokens (tanpa keluarga peran) agar token
        // ubiquitous ("anestesi") tidak membanjiri pool 500 dan mendesak
        // baris prosedur langka; scoring di bawah tetap memakai
        // $descTokens penuh.
        $rows = self::prefilter(self::recallTokens($descTokens), $queryTokens === [] ? null : $queryTokens);

        $scored = [];
        foreach ($rows as $service) {
            [$descTier, $descMatched, $descExact, $descCode] = self::rankCandidate(
                $descTokens, $description, $service->code, $service->name, $service->description
            );
            // Bentuk penuh kata master ("varicocelectomy" memuat
            // "varicocele") dihitung setara exact agar tak terbuang di
            // gerbang ketat di bawah.
            $descExtended = $descExact || self::extendsToken($descTokens, (string) $service->description);
            if ($queryTokens !== []) {
                [$qTier, $qMatched, , $qCode] = self::rankCandidate(
                    $queryTokens, $query, $service->code, $service->name, $service->description
                );
                if ($qTier === 0 && $qCode === 0) {
                    continue;
                }
                $scored[] = [$qTier, $qMatched, $descTier, $descMatched, $descExtended,
                    max($qCode, $descCode),
                    self::unmatchedExtras($descTokens, (string) $service->description),
                    (string) $service->code, $service];
            } elseif ($descTier > 0 || $descCode > 0) {
                $scored[] = [0, 0, $descTier, $descMatched, $descExtended, $descCode,
                    self::unmatchedExtras($descTokens, (string) $service->description),
                    (string) $service->code, $service];
            }
        }

        if ($queryTokens === []) {
            // Mode default ketat: buang partial yang hanya prefix (tanpa
            // satu pun whole-word match maupun bentuk penuh). Semua-token-
            // cocok (tier >= 3) selalu lolos.
            $strict = array_values(array_filter(
                $scored,
                fn ($row) => $row[2] >= self::TIER_ALL_UNORDERED || $row[4]
            ));
            if ($strict !== [] || count($descTokens) < 3) {
                $scored = $strict;
            }
            // else: fallback longgar (prefix partial diizinkan).
        }

        usort($scored, fn ($a, $b) => [$b[0], $b[1], $b[2], $b[3], $b[5], $a[6], $a[7]] <=> [$a[0], $a[1], $a[2], $a[3], $a[5], $b[6], $b[7]]);

        return array_map(
            fn ($row) => [
                'service_code' => $row[8]->code,
                'service_name' => $row[8]->name,
                'service_description' => $row[8]->description,
            ],
            array_slice($scored, 0, $limit)
        );
    }

    /**
     * Batas aman memori (bukan penentu relevansi): keanggotaan pool
     * ditentukan urutan skor trigram per token di bawah, bukan
     * urutan heap arbitrer.
     */
    public const MAX_CANDIDATES = 1000;

    /**
     * Recall pool: baris yang mengandung SALAH SATU token (OR) — hanya
     * untuk recall, urutan ditentukan rank(). Bila $andTokens diisi,
     * kandidat juga wajib mengandung salah satu tokennya.
     * Token pendek (<= 2 huruf, mis. "II") dikeluarkan dari recall bila
     * ada token utama: LIKE '%ii%' mengenai ribuan baris dan bisa
     * mendesak kandidat relevan keluar dari pool.
     *
     * Kuota per token: tiap token mengambil jatahnya sendiri
     * (ceil(500/jumlah token)) lalu digabung — token langka ("reposisi",
     * "varicocele") dijamin kebagian pool walau token umum ("tulang",
     * "anestesi") cocok ribuan baris (kasus produksi Row 21 & Row 40:
     * baris exact hilang total dari saran).
     *
     * Isi tiap kuota TERURUT SKOR trigram (PostgreSQL, memakai index
     * GIN yang sudah ada untuk LIKE; fallback tanpa urutan di driver
     * lain seperti sqlite) sehingga kandidat teratas per token adalah
     * yang paling mirip — bukan baris terlama di heap.
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

        $orTokens = array_values(array_slice($orTokens, 0, 6));
        if ($andTokens !== null) {
            $andTokens = array_values(array_slice($andTokens, 0, 6));
        }
        $quota = (int) max(50, ceil(500 / max(1, count($orTokens))));

        $rankedPrefilter = Service::query()->getConnection()->getDriverName() === 'pgsql';

        $merged = [];
        foreach ($orTokens as $token) {
            $like = '%'.$token.'%';
            $query = Service::query();
            $query->where(function ($w) use ($like) {
                $w->whereRaw('LOWER(code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$like]);
            });
            if ($andTokens !== null && $andTokens !== []) {
                $query->where(function ($w) use ($andTokens) {
                    foreach ($andTokens as $andToken) {
                        $alike = '%'.$andToken.'%';
                        $w->orWhereRaw('LOWER(code) LIKE ?', [$alike])
                            ->orWhereRaw('LOWER(name) LIKE ?', [$alike])
                            ->orWhereRaw('LOWER(description) LIKE ?', [$alike]);
                    }
                });
            }
            if ($rankedPrefilter) {
                $query->orderByRaw(
                    'GREATEST(similarity(LOWER(description), ?), similarity(LOWER(name), ?), similarity(LOWER(code), ?)) DESC',
                    [$token, $token, $token]
                );
            }

            foreach ($query->limit($quota)->get(['code', 'name', 'description']) as $service) {
                $merged[mb_strtoupper(trim((string) $service->code))] = $service;
            }
            if (count($merged) >= self::MAX_CANDIDATES) {
                break;
            }
        }

        return collect(array_values($merged))->take(self::MAX_CANDIDATES)->values();
    }
}
