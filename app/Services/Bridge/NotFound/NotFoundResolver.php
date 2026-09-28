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
        // Pola room charge ("Room Charge KELAS 2" -> "KAMAR PERAWATAN
        // KELAS 2") ditulis ulang menjadi frasa kanonis master dan dipakai
        // langsung — tanpa lewat ekstraksi inti (bedah-bahasa + angka level
        // yang terbuang di tokenisasi membuat query mentah tak berguna).
        // Bukan pola room -> pola visite -> ekstraksi inti seperti semula.
        $searchDesc = RoomChargeQueryRule::rewrite($description, $className)
            ?? VisiteQueryRule::rewrite($description)
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
        // Sinyal specialty + role dibaca dari description MENTAH (masih
        // memuat prefix "BEDAH UROLOGI" dan kata peran "anasthesy"):
        // specialty kuat mengalahkan tarif (Row 21: urologi vs anak/umum),
        // role hanya pemecah seri di bawah tarif agar perilaku Row 31
        // (operator menang via tarif walau query menyebut anasthesy)
        // tetap hijau.
        $querySpec = self::detectSpecialty($description);
        $roleHint = self::detectRole($description);
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
            // Kata master yang memuat UTUH token query ("Varicocelectomy"
            // memuat "varicocele", kasus OKURO Row 18) dihitung exact juga:
            // bukti akarnya sama kuat dengan kata persis, dan tidak boleh
            // kalah dari kandidat yang kebetulan ejaannya pendek.
            $descExact = $descExact || BridgeServiceSearch::extendsToken(
                $descTokens, (string) ($service['service_description'] ?? '')
            );
            [$qTier, $qMatched, $qExact] = $queryTokens === []
                ? [0, 0, false]
                : BridgeServiceSearch::rankCandidate(
                    $queryTokens, (string) $query,
                    (string) ($service['service_code'] ?? ''), $service['service_name'] ?? null,
                    (string) ($service['service_description'] ?? '')
                );
            if ($queryTokens !== []) {
                $qExact = $qExact || BridgeServiceSearch::extendsToken(
                    $queryTokens, (string) ($service['service_description'] ?? '')
                );
            }
            // Tier efektif: kandidat yang specialty-nya TERBUKTI beda dari
            // query didemosi 2 tingkat (Row 21: OKANK tier 4 -> 2 sehingga
            // jatuh di bawah OKURO tier 2). Tier mentah tetap disimpan
            // untuk display/P5; yang di-sort adalah tier efektif.
            $candSpec = self::detectSpecialty((string) ($service['service_description'] ?? ''));
            $effDescTier = $this->effTier($descTier, $querySpec, $candSpec);
            $effQier = $this->effTier($qTier, $querySpec, $candSpec);
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
                'eff_q_tier' => $effQier,
                'eff_desc_tier' => $effDescTier,
                'spec_score' => $this->specScore(
                    $descTokens, $querySpec, (string) ($service['service_description'] ?? '')
                ),
                'role_match' => ($roleHint !== null
                    && self::detectRole((string) ($service['service_description'] ?? '')) === $roleHint) ? 1 : 0,
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
            $descExact = $descExact || BridgeServiceSearch::extendsToken(
                $descTokens, (string) ($service['service_description'] ?? '')
            );
            [$qTier, $qMatched, $qExact] = $queryTokens === []
                ? [0, 0, false]
                : BridgeServiceSearch::rankCandidate(
                    $queryTokens, (string) $query,
                    (string) ($service['service_code'] ?? ''), $service['service_name'] ?? null,
                    (string) ($service['service_description'] ?? '')
                );
            if ($queryTokens !== []) {
                $qExact = $qExact || BridgeServiceSearch::extendsToken(
                    $queryTokens, (string) ($service['service_description'] ?? '')
                );
            }
            $candSpec = self::detectSpecialty((string) ($service['service_description'] ?? ''));
            $effDescTier = $this->effTier($descTier, $querySpec, $candSpec);
            $effQier = $this->effTier($qTier, $querySpec, $candSpec);
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
                'eff_q_tier' => $effQier,
                'eff_desc_tier' => $effDescTier,
                'spec_score' => $this->specScore(
                    $descTokens, $querySpec, (string) ($service['service_description'] ?? '')
                ),
                'role_match' => ($roleHint !== null
                    && self::detectRole((string) ($service['service_description'] ?? '')) === $roleHint) ? 1 : 0,
                'text_idf' => $descTier > 0
                    ? $this->matchedIdf($descTokens, (string) ($service['service_description'] ?? ''), $idfs)
                    : 0.0,
                '_order' => $service['_order'],
            ];
        }

        usort($ranked, function ($a, $b) {
            // Tier EFEKTIF dulu (tier mentah minus demosi specialty),
            // lalu kecocokan query, agar kandidat beda spesialisasi tidak
            // naik hanya karena cocok banyak token generik.
            foreach (['eff_q_tier', 'eff_desc_tier'] as $k) {
                if ($a[$k] !== $b[$k]) {
                    return $b[$k] <=> $a[$k];
                }
            }
            // Procedure + specialty di atas kelas/tarif (Row 21): kandidat
            // se-spesialisasi menang mutlak atas beda spesialisasi walau
            // tarifnya lebih jauh; penalti -2 untuk specialty berbeda.
            if (abs($a['spec_score'] - $b['spec_score']) > 1e-9) {
                return $b['spec_score'] <=> $a['spec_score'];
            }
            // Component/role (anestesi/operator/kamar) berbobot tinggi:
            // memisahkan komponen dalam satu keluarga prosedur.
            if ($a['role_match'] !== $b['role_match']) {
                return $b['role_match'] <=> $a['role_match'];
            }
            foreach (['q_matched', 'q_exact', 'desc_matched', 'desc_exact'] as $k) {
                if ($a[$k] !== $b[$k]) {
                    return $b[$k] <=> $a[$k];
                }
            }
            // Seri teks: token langka menang sebelum sinyal kelas/tarif —
            // mis. "varicocele" mengalahkan "laparoscopy" pada Row 25.
            if (abs($a['text_idf'] - $b['text_idf']) > 1e-9) {
                return $b['text_idf'] <=> $a['text_idf'];
            }
            // Kelas + tarif hanya validasi sekunder: tak boleh mengalahkan
            // kecocokan procedure + specialty + component di atas.
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

        // Aturan sibling KAMAR (mode default, tanpa ketikan user): bila
        // baris Excel adalah tarif kamar operasi, jawabannya adalah
        // pasangan "Kamar Operasi & Sarana" dari prosedur (service) yang
        // paling cocok teksnya — bukan pair operator/anestesi walau
        // tarifnya lebih dekat. Contoh: dari keluarga OKURO-O/A/K yang
        // naik adalah OKURO-K.
        $ranked = KamarSiblingRule::promote($ranked, $roleHint, $queryTokens);

        return array_map(function ($row) {
            unset($row['_order'], $row['eff_q_tier'], $row['eff_desc_tier'], $row['q_tier'], $row['q_matched'], $row['q_exact'], $row['desc_exact']);
            // Sinyal teks dipertahankan dengan nama publik agar UI bisa
            // memecah "% rekomendasi" (kelas+tarif) dari kecocokan teks,
            // dan processor bisa mensyaratkan bukti teks minimal (P5).

            return $row;
        }, $this->diverseSlice($ranked, $limit));
    }

    /**
     * Tier efektif: demosi 2 tingkat bila specialty kandidat TERBUKTI
     * beda dari query (keduanya terdeteksi dan berbeda). Tanpa info di
     * salah satu sisi → tier mentah (netral, bukan penalti).
     */
    protected function effTier(int $tier, ?string $querySpec, ?string $candSpec): int
    {
        if ($tier > 0 && $querySpec !== null && $candSpec !== null && $querySpec !== $candSpec) {
            return $tier - 2;
        }

        return $tier;
    }

    /**
     * Ambil $limit teratas dengan batas maks 3 pasangan per kode service.
     * Tanpa ini, 10 slot rekomendasi bisa banjir oleh 1-2 kode yang punya
     * banyak pasangan kelas (kasus Row 18: 10 baris OKANK semua) sehingga
     * prosedur yang benar (OKURO) tak terlihat sama sekali.
     *
     * @param  array<int, array<string, mixed>>  $ranked  sudah terurut
     * @return array<int, array<string, mixed>>
     */
    protected function diverseSlice(array $ranked, int $limit): array
    {
        $counts = [];
        $out = [];
        foreach ($ranked as $row) {
            if (count($out) >= $limit) {
                break;
            }
            $code = mb_strtoupper(trim((string) ($row['service_code'] ?? '')));
            if (($counts[$code] ?? 0) >= 3) {
                continue;
            }
            $counts[$code] = ($counts[$code] ?? 0) + 1;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Kata generik yang dibuang saat membangun query prosedur jangkar —
     * nilai default; efektif dibaca dari config('bridge.search.
     * sibling_generic').
     */
    private const SIBLING_GENERIC = [
        'golongan', 'tindakan', 'medis', 'besar', 'khusus',
        'kecil', 'sedang', 'umum', 'layanan', 'jasa',
        'operasi', 'bedah', 'kamar', 'ruang', 'dokter', 'sarana',
        'biaya', 'sewa', 'charge', 'paket', 'pemakaian',
    ];

    /** @return array<int, string> */
    private static function siblingGeneric(): array
    {
        $configured = config('bridge.search.sibling_generic');

        return is_array($configured) && $configured !== [] ? array_values($configured) : self::SIBLING_GENERIC;
    }

    /**
     * True bila description adalah tarif kamar TANPA prosedur
     * ("Kamar Operasi", "BIAYA KAMAR OPERASI"): role kamar terdeteksi
     * tetapi setelah kata generik dibuang tak tersisa token prosedur.
     * Baris seperti ini butuh jangkar prosedur dari baris se-kasus di
     * file yang sama (fase jangkar di processor). Beda dengan "BEDAH
     * UROLOGI - Varicocelectomy - Kamar Operasi" (prosedur ada di baris
     * yang sama → false, jalur sibling promotion biasa).
     */
    public static function isBareKamar(string $description): bool
    {
        if (self::detectRole($description) !== 'kamar') {
            return false;
        }
        foreach (BridgeServiceSearch::contentWords($description) as $token) {
            if (! in_array($token, self::siblingGeneric(), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Saran saudara kamar dari prosedur jangkar: description master
     * prosedur (mis. "... Varicocelectomy - Dokter Operator" milik baris
     * se-kasus) dipangkas ekor perannya, dibangun query sintetis
     * "<spesialisasi + inti> - Kamar Operasi", lalu seluruh mesin
     * suggest() dipakai ulang — termasuk sibling promotion, diversity,
     * dan sinyal teks. Tanpa inti prosedur yang tersisa: [].
     */
    public function suggestKamarSibling(
        string $anchorDesc,
        ?string $className = null,
        ?float $effectiveTariff = null,
        int $limit = 10,
    ): array {
        $proc = self::stripDoctorMentions($anchorDesc);
        $proc = trim($proc);
        if (str_contains($proc, ' - ')) {
            $segments = array_values(array_filter(
                array_map(fn ($s) => trim((string) $s), explode(' - ', $proc)),
                fn ($s) => $s !== ''
            ));
            if (count($segments) >= 2
                && self::detectRole((string) end($segments)) !== null
            ) {
                array_pop($segments);
            }
            $proc = trim(implode(' ', $segments));
        }
        $tokens = array_values(array_filter(
            BridgeServiceSearch::contentWords($proc),
            fn ($t) => ! in_array($t, self::siblingGeneric(), true)
        ));
        if ($tokens === []) {
            $tokens = BridgeServiceSearch::contentWords($proc);
        }
        if ($tokens === []) {
            return [];
        }

        return $this->suggest(
            implode(' ', $tokens).' - Kamar Operasi',
            null,
            $className,
            $effectiveTariff,
            $limit,
        );
    }

    /**
     * Pola specialty [pattern, kanonis] — nilai default; efektif dibaca
     * dari config('bridge.search.specialties'). Urutan penting (frasa
     * dulu). "umum" hanya via frasa "bedah umum" agar "dokter umum"
     * (role) tidak terbaca sebagai spesialisasi.
     */
    private const SPECIALTY_PATTERNS = [
        ['/bedah\s+anak/iu', 'anak'],
        ['/\banak\b/iu', 'anak'],
        ['/bedah\s+umum/iu', 'umum'],
        ['/urologi/iu', 'urologi'],
        ['/jantung|cardio|kardiovaskular/iu', 'jantung'],
        ['/saraf|neuro/iu', 'saraf'],
        ['/mata|ophthalm/iu', 'mata'],
        ['/obgyn|kandungan|obstetri|ginekologi/iu', 'obgyn'],
        ['/ortopedi|orthopedi|ortohopedi/iu', 'ortopedi'],
        ['/\btulang\b/iu', 'ortopedi'],
        ['/digestif/iu', 'digestif'],
        ['/plastik/iu', 'plastik'],
        ['/paru|thorax|toraks/iu', 'paru'],
        ['/ginjal|nefro/iu', 'ginjal'],
        ['/\btht\b|telinga|hidung|tenggorokan/iu', 'tht'],
        ['/gigi|dental|\bmulut\b/iu', 'gigi'],
        ['/kulit|kelamin|dermato|venereologi/iu', 'kulit'],
    ];

    /**
     * Deteksi spesialisasi dari teks bebas (description Excel mentah atau
     * description master). Pola dari config, null bila tak dikenali.
     */
    public static function detectSpecialty(string $text): ?string
    {
        $norm = BridgeServiceSearch::normalize($text);
        if ($norm === '') {
            return null;
        }
        $patterns = config('bridge.search.specialties');
        if (! is_array($patterns) || $patterns === []) {
            $patterns = self::SPECIALTY_PATTERNS;
        }
        foreach ($patterns as $entry) {
            [$pattern, $canonical] = is_array($entry) ? array_values($entry) + [null, null] : [null, null];
            if (is_string($pattern) && is_string($canonical) && $pattern !== '' && @preg_match($pattern, $norm)) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Deteksi peran/komponen dari config('bridge.search.roles'):
     * berurutan kamar > operator > anestesi. null bila tak disebut.
     */
    public static function detectRole(string $text): ?string
    {
        $norm = ' '.BridgeServiceSearch::normalize($text).' ';
        if ($norm === '  ') {
            return null;
        }
        $roles = config('bridge.search.roles');
        if (! is_array($roles) || $roles === []) {
            $roles = [
                'kamar' => '/\bkamar\s+operasi\b|\bruang\s+operasi\b|\bruang\s+bedah\b|\bsarana\b/u',
                'operator' => '/\boperator\b/u',
                'anestesi' => '/\banestesi\b|\banasthesy\b|\banasthesi\b|\banesthesia\b|\banesthesy\b|\banastesi\b|\bnarkose\b|\bsedasi\b/u',
            ];
        }
        foreach ($roles as $canonical => $pattern) {
            if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, $norm)) {
                return (string) $canonical;
            }
        }

        return null;
    }

    /**
     * Skor procedure + specialty satu kandidat (dipakai SEBELUM sinyal
     * kelas/tarif): specialty sama +2, specialty beda -2 (penalti),
     * tanpa info specialty 0; kualitas procedure per token: kata master
     * memuat utuh token +1,5 (bentuk penuh "varicocelectomy" mengalahkan
     * "varicocele"), kata persis +1, token memuat kata +0,75, prefix
     * biasa +0,5.
     *
     * @param  array<int, string>  $tokens  token isi query
     */
    protected function specScore(array $tokens, ?string $querySpec, string $hayDesc): float
    {
        $score = 0.0;
        $candSpec = self::detectSpecialty($hayDesc);
        if ($querySpec !== null && $candSpec !== null) {
            $score += ($querySpec === $candSpec) ? 2.0 : -2.0;
        }

        $normHay = BridgeServiceSearch::normalize($hayDesc);
        $hayWords = $normHay === ''
            ? []
            : array_values(array_filter(
                explode(' ', $normHay),
                fn ($w) => mb_strlen($w) >= 2 || ctype_digit($w)
            ));
        foreach ($tokens as $token) {
            $best = 0.0;
            foreach ($hayWords as $word) {
                if ($word === $token) {
                    $best = max($best, 1.0);
                } elseif (str_starts_with($word, $token)
                    && mb_strlen($word) - mb_strlen($token) >= 3
                ) {
                    // Bentuk penuh klinis (konsisten dengan extendsToken).
                    $best = max($best, 1.5);
                } elseif (str_starts_with($token, $word)) {
                    $best = max($best, 0.75);
                } elseif (str_starts_with($word, mb_substr($token, 0, 4))
                    && str_starts_with($token, mb_substr($word, 0, 4))
                ) {
                    // Sama-sama awalan 4 huruf (variasi ejaan) — di bawah
                    // prefix searah agar tak mengalahkan bentuk penuh.
                    $best = max($best, 0.5);
                }
            }
            $score += $best;
        }

        return $score;
    }

    /**
     * Buang segmen kurung (...) / [...] HANYA bila berisi penanda dokter
     * (dr/dokter/Sp./spesialis/konsulen/FIPM/FIPP/Ph.D/dll). Kurung berisi
     * singkatan klinis ("(EKG)"), ukuran ("(18 mm)"), atau kode
     * ("(OST-22104-0139)") dipertahankan — menghapusnya membuang token
     * pembeda (Row 7: "ekg" hilang sehingga TPJ004 tak terbedakan).
     */
    public static function stripDoctorMentions(string $text): string
    {
        return (string) preg_replace(
            '/[\(\[][^)\]]*\b(dr\.?|dokter|sp\.?|spesialis|subspesialis|konsulen|fipm|fipp|ph\.?d\.?|m\.?h\.?|m\.?kes\.?)\b[^)\]]*[\)\]]/iu',
            ' ',
            $text
        );
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
                fn ($w) => mb_strlen($w) >= 2 || ctype_digit($w)
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

    /** @return array<int, string> */
    private static function personnelNoise(): array
    {
        $configured = config('bridge.search.personnel_noise');

        return is_array($configured) && $configured !== [] ? array_values($configured) : self::PERSONNEL_NOISE;
    }

    /**
     * Daftar penanda personel — nilai default; efektif dibaca dari
     * config('bridge.search.personnel_noise').
     */
    private const PERSONNEL_NOISE = [
        'narkose', 'sedasi', 'bius', 'dokter', 'operator',
        'bidan', 'spesialis', 'dpjp', 'konsulen',
    ];

    /**
     * Ekstraksi inti tindakan: buang kurung (...) / [...] HANYA bila
     * berisi penanda dokter (nama + gelar: dr/dokter/Sp./FIPM/...) —
     * singkatan klinis seperti "(EKG)" dipertahankan sebagai token
     * (Row 7). Lalu potong sejak kata-noise, lalu tangani delimiter
     * TEPAT " - " (spasi-hyphen-spasi, bukan "-" umum) sebagai SINYAL
     * STRUKTUR tambahan:
     * - segmen PERTAMA = PREFIX SPESIALISASI bila memuat kata "bedah"
     *   ("BEDAH UMUM", "BEDAH TULANG / ORTOHOPEDI") lalu dibuang;
     * - segmen terakhir = ROLE/KONTEKS hanya bila dikenali (daftar
     *   eksplisit: Dokter Operator/Anestesi/..., Kamar/Ruang Operasi,
     *   ... — dalam bentuk ternormalisasi), lalu dibuang;
     * - sisa segmen DIGABUNG tanpa asumsi posisi (bukan "selalu segmen
     *   ke-2/ke-3/terakhir") — similarity existing yang memverifikasi
     *   mana yang cocok, exact/phrase/token tetap penentu utama.
     * Sisa 1 token utama (mis. "Varicocelectomy" setelah prefix + nama
     * dokter dibuang, Row 18) tetap dipakai — JANGAN fallback ke
     * description mentah karena justru mengembalikan nama dokter +
     * prefix generik ke query. Fallback mentah hanya bila tidak ada
     * token utama sama sekali; kekosongan recall sudah ditangani
     * percobaan ulang di suggest().
     */
    protected function coreAction(string $description): string
    {
        $core = self::stripDoctorMentions($description);
        // Personel (dokter/operator/..., daftar di config) dipotong HANYA
        // sebagai kualifikasi akhir — setelah koma atau " - ". Di tengah
        // frasa ("Konsultasi Dokter Umum", Row 15) dipertahankan karena
        // bagian nama tindakan. Keluarga anestesi tak pernah dipotong
        // (sinyal komponen, Row 21) karena tak ada di daftar personel.
        $personnelAlt = implode('|', array_map(
            fn ($w) => preg_quote($w, '/'),
            self::personnelNoise()
        ));
        $core = trim((string) preg_replace(
            '/(,|\s-\s)[^,]*?\b('.$personnelAlt.')\b.*/ius',
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
                    return $this->fallbackCore($core, $description);
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
                    if ($joinedMains !== []) {
                        return $joined;
                    }

                    return $this->fallbackCore($core, $description);
                }
            }
        }
        $coreMains = array_values(array_filter(
            BridgeServiceSearch::words($core),
            fn ($t) => mb_strlen($t) > 2
        ));

        return $coreMains !== [] ? $core : $description;
    }

    /**
     * Fallback inti: core yang sudah bersih (tanpa nama dokter) lebih
     * baik daripada description mentah walau hanya 1 token; mentah
     * hanya bila core pun tak punya token utama sama sekali.
     */
    protected function fallbackCore(string $core, string $description): string
    {
        $coreMains = array_values(array_filter(
            BridgeServiceSearch::words($core),
            fn ($t) => mb_strlen($t) > 2
        ));

        return $coreMains !== [] ? $core : $description;
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
