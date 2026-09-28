<?php

namespace App\Services\Bridge;

/**
 * Loop baris Excel: normalisasi -> resolve -> kumpulkan ringkasan,
 * grup ambigu (per mapping key), dan sampel preview.
 */
class BridgeTarifProcessor
{
    public const PREVIEW_LIMIT = 200;

    public function __construct(protected TarifBridgeResolver $resolver) {}

    public function repository(): TarifBridgeRepository
    {
        return $this->resolver->repository();
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows  baris mentah (tanpa header)
     * @param  array<string, int>  $map  field => index kolom
     * @param  array<string, array{service_code: string, class_code: string}>  $resolutions  mapping_key => pilihan user
     */
    public function process(array $rows, array $map, array $resolutions = []): array
    {
        $summary = ['total' => 0, 'matched' => 0, 'ambiguous' => 0, 'not_found' => 0, 'invalid' => 0, 'suggested' => 0];
        /** @var array<string, array{description: string, kelas: string, candidates: array, rows: array<int>, resolved: ?array}> $groups */
        $groups = [];
        $preview = [];
        $decisions = [];

        // Pass 1: normalisasi semua row (murni in-memory, tanpa query).
        $normalizedList = [];
        $excelRow = 1; // baris 1 = header
        foreach ($rows as $rawRow) {
            $excelRow++;
            $normalizedList[] = BridgeTarifRowNormalizer::normalize($rawRow, $map, $excelRow);
        }

        // Pass 2: fallback tarif grup — row yang effective_tariff-nya masih
        // null memakai median tarif valid row lain dengan mapping_key sama
        // (prioritas 3, sumber 'group'). Nilai asli row tidak diubah.
        $this->applyGroupTariffFallback($normalizedList);

        // Kumpulan tarif efektif per grup untuk referensi Petakan Manual.
        /** @var array<string, array<int, array{t: float, s: ?string}>> $groupTariffs */
        $groupTariffs = [];

        // Pass 3: resolve per row (satu-satunya tempat query via repository
        // yang sudah preload — tanpa query per row).
        foreach ($normalizedList as $normalized) {
            $excelRow = $normalized['excel_row'];
            $resolved = $this->resolver->resolve($normalized);

            // Pilihan manual user untuk grup ambigu maupun NOT_FOUND
            // berlaku ke semua row se-key.
            $isManual = $resolved['status'] === TarifBridgeResolver::STATUS_NOT_FOUND;
            if (($resolved['status'] === TarifBridgeResolver::STATUS_AMBIGUOUS
                    || $isManual)
                && isset($resolutions[$normalized['mapping_key']])
                && $this->isValidChoice(
                    $resolved['candidates'],
                    $resolutions[$normalized['mapping_key']],
                    $isManual
                )
            ) {
                $choice = $resolutions[$normalized['mapping_key']];
                $resolved['status'] = TarifBridgeResolver::STATUS_MATCHED;
                $resolved['new_service_code'] = mb_strtoupper(trim($choice['service_code']));
                // Grup manual (NOT_FOUND): kelas tidak dipilih ulang —
                // otomatis dari master via nama kelas; bila nama kelas
                // tidak ada di master, pertahankan bawaan Excel.
                // Override class eksplisit (bila diisi) selalu menang.
                $overrideClass = mb_strtoupper(trim($choice['class_code'] ?? ''));
                if ($overrideClass !== '') {
                    $resolved['new_class_code'] = $overrideClass;
                } elseif ($isManual) {
                    $resolved['new_class_code'] = $this->resolver->repository()
                        ->classCodeFor($normalized['class_name'], $normalized['service_class_code'])
                        ?? $normalized['service_class_code'];
                } else {
                    $resolved['new_class_code'] = $choice['class_code'];
                }
            }

            $summary['total']++;
            match ($resolved['status']) {
                TarifBridgeResolver::STATUS_MATCHED => $summary['matched']++,
                TarifBridgeResolver::STATUS_AMBIGUOUS => $summary['ambiguous']++,
                TarifBridgeResolver::STATUS_NOT_FOUND => $summary['not_found']++,
                default => $summary['invalid']++,
            };
            if ($resolved['status'] === TarifBridgeResolver::STATUS_AMBIGUOUS
                && ! empty($resolved['suggested_applied'])
            ) {
                $summary['suggested']++;
            }

            if ($resolved['status'] === TarifBridgeResolver::STATUS_AMBIGUOUS
                || $resolved['status'] === TarifBridgeResolver::STATUS_NOT_FOUND
            ) {
                $key = $normalized['mapping_key'];
                $groups[$key] ??= [
                    'description' => $normalized['service_description'],
                    'kelas' => $normalized['class_name'],
                    'candidates' => $resolved['candidates'],
                    'manual' => $resolved['status'] === TarifBridgeResolver::STATUS_NOT_FOUND,
                    'rows' => [],
                    'resolved' => null,
                ];
                $groups[$key]['rows'][] = $excelRow;
                // Referensi tarif efektif grup untuk Petakan Manual: tiap
                // row menyumbang effective_tariff-nya (bila ada).
                if (isset($normalized['effective_tariff']) && is_numeric($normalized['effective_tariff'])) {
                    $groupTariffs[$key][] = [
                        't' => (float) $normalized['effective_tariff'],
                        's' => $normalized['tariff_source'] ?? null,
                    ];
                }
            }

            $decisions[$excelRow] = [
                'status' => $resolved['status'],
                'new_service_code' => $resolved['new_service_code'],
                'new_class_code' => $resolved['new_class_code'],
                'suggested_applied' => $resolved['suggested_applied'] ?? false,
            ];

            if (count($preview) < self::PREVIEW_LIMIT) {
                $preview[] = array_merge($normalized, [
                    'status' => $resolved['status'],
                    'new_service_code' => $resolved['new_service_code'],
                    'new_class_code' => $resolved['new_class_code'],
                    // Detail analisa per baris untuk modal UI (hanya
                    // AMBIGUOUS/NOT_FOUND/INVALID yang memakainya).
                    'candidates' => $resolved['candidates'] ?? [],
                    'suggested' => $resolved['suggested'] ?? null,
                    'suggested_applied' => $resolved['suggested_applied'] ?? false,
                    'analysis' => $resolved['analysis'] ?? null,
                ]);
            }
        }

        // Ringkas referensi tarif efektif per grup (satu nilai bila semua
        // row sama, rentang min–max bila bervariasi, null bila tak ada).
        foreach ($groups as $key => $group) {
            $groups[$key]['tariff_ref'] = $this->summarizeGroupTariffs($groupTariffs[$key] ?? []);
        }

        // Saran NOT_FOUND per grup (sekali per grup, bukan per row):
        // kandidat mirip description (BridgeServiceSearch) disusun ulang
        // memakai kelas + tarif efektif grup. 2 query ringan per grup
        // (recall similarity + pairs tarif); tanpa grup NOT_FOUND maka
        // tanpa query tambahan sama sekali.
        foreach ($groups as $key => $group) {
            if (! ($group['manual'] ?? false)) {
                continue;
            }
            $tariff = $groups[$key]['tariff_ref']['tariff'] ?? null;
            $kelas = trim((string) ($group['kelas'] ?? ''));
            $groups[$key]['suggestions'] = $this->resolver->notFound()->suggest(
                (string) $group['description'],
                null,
                $kelas !== '' ? $kelas : null,
                is_numeric($tariff) ? (float) $tariff : null,
                10,
            );
        }

        // Fase JANGKAR se-kasus (kasus 868-1): grup kamar TANPA prosedur
        // ("Kamar Operasi" saja) tidak bisa dicari dari deskripsinya
        // sendiri — prosedurnya diambil dari baris se-kasus di file yang
        // sama (868-2 Varicocelectomy), lalu saran diganti dengan
        // saudara kamar prosedur tersebut (suggestKamarSibling). Kunci
        // kasus = prefix Old Code sebelum "-" numerik ("868-1" -> "868";
        // kode master seperti "OKURO-O-030-002" tidak dipecah karena
        // prefixnya mengandung huruf). Fallback: baris prosedur terdekat.
        // Tanpa jangkar yakin (MATCHED atau spec >= 2): perilaku lama
        // (saran berbasis tarif) dipertahankan.
        $this->applyKamarAnchors($groups, $preview);

        // Saran NOT_FOUND teratas langsung masuk New Code — status tetap
        // NOT_FOUND dan bisa ditimpa manual — HANYA bila buktinya cukup:
        // (a) >= 2 token isi cocok, ATAU (b) token tunggal yang kuat:
        // spec_score >= 3 (spesialisasi sama + bentuk penuh) DAN kelas
        // cocok DAN selisih tarif <= 50% (kasus Row 18: "Varicocelectomy"
        // -> OKURO-O/KL2). Tanpa threshold, kandidat yang hanya cocok
        // 1 token generik + tarif dekat langsung mengisi New Code yang
        // salah. Saran tetap ditampilkan untuk dipilih manual walau di
        // bawah threshold.
        // KONSENSUS (seluruh saran berkode sama): status menjadi
        // MATCHED/valid dengan syarat yang sama, tetapi grup Petakan
        // Manual + modal analisa tetap dipertahankan agar bisa
        // diperiksa/diubah. Berlaku untuk preview + decisions (generate).
        foreach ($groups as $key => $group) {
            if (! ($group['manual'] ?? false) || empty($group['suggestions'])) {
                continue;
            }
            $top = $group['suggestions'][0];
            $code = trim((string) ($top['service_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            // Bukti kuat: >= 2 token isi cocok, atau token tunggal dengan
            // spec kuat + kelas cocok + tarif wajar. Saran tetap ada
            // untuk dipilih manual; hanya auto-isi yang ditahan.
            $tariffDiff = $top['_tariff_diff'] ?? null;
            $strongSingle = ((float) ($top['spec_score'] ?? 0.0)) >= 3.0
                && ((int) ($top['_class_match'] ?? 0)) === 1
                && is_numeric($tariffDiff) && (float) $tariffDiff <= 0.5;
            if ((int) ($top['desc_matched'] ?? 0) < 2 && ! $strongSingle) {
                continue;
            }
            $groups[$key]['top_service'] = $top['service_code'];
            $codes = array_values(array_filter(array_unique(array_map(
                fn ($s) => mb_strtoupper(trim((string) ($s['service_code'] ?? ''))),
                $group['suggestions']
            ))));
            $consensus = count($codes) === 1;
            if ($consensus) {
                $groups[$key]['consensus'] = true;
            }
            $topClass = trim((string) ($top['class_code'] ?? ''));
            foreach ($group['rows'] as $excelRow) {
                if (isset($decisions[$excelRow])) {
                    $decisions[$excelRow]['new_service_code'] = $top['service_code'];
                    $decisions[$excelRow]['suggested_applied'] = true;
                    if ($consensus) {
                        $decisions[$excelRow]['status'] = TarifBridgeResolver::STATUS_MATCHED;
                        if ($topClass !== '') {
                            $decisions[$excelRow]['new_class_code'] = mb_strtoupper($topClass);
                        }
                    }
                }
            }
            $summary['suggested'] += count($group['rows']);
            if ($consensus) {
                $summary['matched'] += count($group['rows']);
                $summary['not_found'] -= count($group['rows']);
            }
        }

        // Tempelkan saran grup ke preview row NOT_FOUND untuk modal analisa.
        foreach ($preview as $i => $row) {
            if (($row['status'] ?? '') === TarifBridgeResolver::STATUS_NOT_FOUND) {
                $key = $row['mapping_key'];
                $preview[$i]['suggestions'] = $groups[$key]['suggestions'] ?? [];
                if (! empty($groups[$key]['anchor_note'])) {
                    $preview[$i]['analysis'] = trim(
                        (string) ($preview[$i]['analysis'] ?? '').' '.$groups[$key]['anchor_note']
                    );
                }
                if (isset($groups[$key]['top_service'])) {
                    $preview[$i]['new_service_code'] = $groups[$key]['top_service'];
                    $preview[$i]['suggested_applied'] = true;
                    $preview[$i]['suggested'] = [
                        'service_code' => $groups[$key]['top_service'],
                        'class_code' => $groups[$key]['suggestions'][0]['class_code'] ?? null,
                    ];
                }
                if (! empty($groups[$key]['consensus'])) {
                    $preview[$i]['status'] = TarifBridgeResolver::STATUS_MATCHED;
                    $topClass = trim((string) ($groups[$key]['suggestions'][0]['class_code'] ?? ''));
                    if ($topClass !== '') {
                        $preview[$i]['new_class_code'] = mb_strtoupper($topClass);
                    }
                }
            }
        }

        // Tandai grup yang sudah dipilih user.
        foreach ($groups as $key => $group) {
            if (isset($resolutions[$key])
                && $this->isValidChoice($group['candidates'], $resolutions[$key], $group['manual'] ?? false)
            ) {
                $groups[$key]['resolved'] = $resolutions[$key];
            }
        }

        $summary['unresolved'] = $summary['ambiguous'] + $summary['not_found'] + $summary['invalid'];

        return [
            'summary' => $summary,
            'groups' => $groups,
            'preview' => $preview,
            'decisions' => $decisions,
        ];
    }

    /**
     * Fallback tarif grup (prioritas 3, in-memory): row yang
     * effective_tariff-nya masih null memakai median tarif valid row lain
     * dengan mapping_key sama. Row yang sudah punya effective sendiri
     * (excel / total_billed_quantity) TIDAK disentuh. Dilewati untuk
     * key tak lengkap (jalur INVALID) agar row tak terkait tidak tercampur.
     *
     * @param  array<int, array<string, mixed>>  $list  (by reference)
     */
    protected function applyGroupTariffFallback(array &$list): void
    {
        $groupValues = [];
        foreach ($list as $n) {
            if (($n['description_key'] ?? '') === '' || ($n['class_key'] ?? '') === '') {
                continue;
            }
            if (isset($n['effective_tariff']) && is_numeric($n['effective_tariff'])) {
                $groupValues[$n['mapping_key']][] = (float) $n['effective_tariff'];
            }
        }
        $medians = [];
        foreach ($groupValues as $key => $values) {
            $medians[$key] = BridgeTarifEffectiveTariff::median($values);
        }
        foreach ($list as &$n) {
            if (isset($n['effective_tariff']) && is_numeric($n['effective_tariff'])) {
                continue;
            }
            $fallback = $medians[$n['mapping_key']] ?? null;
            if ($fallback !== null) {
                $n['effective_tariff'] = $fallback;
                $n['tariff_source'] = BridgeTarifEffectiveTariff::SOURCE_GROUP;
            }
        }
        unset($n);
    }

    /**
     * Ringkas tarif efektif grup untuk referensi Petakan Manual.
     *
     * @param  array<int, array{t: float, s: ?string}>  $entries
     * @return array{label: ?string, tariff: ?float, source: ?string, varied: bool}
     */
    protected function summarizeGroupTariffs(array $entries): array
    {
        $none = ['label' => null, 'tariff' => null, 'source' => null, 'varied' => false];
        if ($entries === []) {
            return $none;
        }
        // Nilai distinct (2 desimal) terurut; sumber mengikuti kemunculan
        // pertama nilai tersebut.
        $byValue = [];
        foreach ($entries as $e) {
            $k = number_format($e['t'], 2, '.', '');
            $byValue[$k] ??= ['t' => $e['t'], 's' => $e['s']];
        }
        ksort($byValue);
        $distinct = array_values($byValue);
        if (count($distinct) === 1) {
            $t = $distinct[0]['t'];
            $s = $distinct[0]['s'];

            return [
                'label' => 'Rp '.number_format($t, 0, ',', '.').' ('.BridgeTarifEffectiveTariff::sourceLabel($s).')',
                'tariff' => $t,
                'source' => $s,
                'varied' => false,
            ];
        }
        $min = $distinct[0]['t'];
        $max = $distinct[count($distinct) - 1]['t'];

        return [
            'label' => 'Rp '.number_format($min, 0, ',', '.').' – Rp '.number_format($max, 0, ',', '.').' (bervariasi antar row)',
            'tariff' => null,
            'source' => null,
            'varied' => true,
        ];
    }

    /**
     * Fase jangkar: untuk tiap grup kamar tanpa prosedur, cari prosedur
     * jangkar dari baris lain di file yang sama lalu ganti saran grup
     * dengan saudara kamar prosedur tersebut.
     *
     * @param  array<string, array>  $groups  (by reference)
     * @param  array<int, array<string, mixed>>  $preview
     */
    protected function applyKamarAnchors(array &$groups, array $preview): void
    {
        $targets = [];
        foreach ($groups as $key => $group) {
            if (($group['manual'] ?? false)
                && ! empty($group['suggestions'])
                && \App\Services\Bridge\NotFound\NotFoundResolver::isBareKamar((string) ($group['description'] ?? ''))
            ) {
                $targets[$key] = $group;
            }
        }
        if ($targets === []) {
            return;
        }

        $byExcelRow = [];
        foreach ($preview as $row) {
            $byExcelRow[(int) ($row['excel_row'] ?? 0)] = $row;
        }

        $needDesc = [];
        $anchors = [];
        foreach ($targets as $key => $group) {
            $anchor = $this->findKamarAnchor($group, $groups, $byExcelRow);
            if ($anchor !== null && ($anchor['kind'] ?? '') === 'matched') {
                $needDesc[$anchor['service_code']] = true;
            }
            $anchors[$key] = $anchor;
        }
        $descriptions = $needDesc === []
            ? []
            : $this->resolver->notFound()->repository()->serviceDescriptions(array_keys($needDesc));

        foreach ($targets as $key => $group) {
            $anchor = $anchors[$key] ?? null;
            if ($anchor === null) {
                continue;
            }
            $anchorDesc = (string) ($anchor['description'] ?? '');
            if ($anchorDesc === '' && isset($descriptions[mb_strtoupper(trim($anchor['service_code']))])) {
                $anchorDesc = $descriptions[mb_strtoupper(trim($anchor['service_code']))];
            }
            if (trim($anchorDesc) === '') {
                continue;
            }
            $tariff = $groups[$key]['tariff_ref']['tariff'] ?? null;
            $kelas = trim((string) ($group['kelas'] ?? ''));
            $sibling = $this->resolver->notFound()->suggestKamarSibling(
                $anchorDesc,
                $kelas !== '' ? $kelas : null,
                is_numeric($tariff) ? (float) $tariff : null,
                10,
            );
            if ($sibling === []) {
                continue;
            }
            $groups[$key]['suggestions'] = $sibling;
            $groups[$key]['anchor_note'] = 'Prosedur '.$anchor['service_code']
                .' diambil dari baris '.$anchor['excel_row']
                .($anchor['old_code'] !== '' ? ' ('.$anchor['old_code'].')' : '')
                .' se-kasus ('.$anchor['kind'].'); saran di bawah adalah '
                .'saudara kamar prosedur tersebut.';
        }
    }

    /**
     * Cari prosedur jangkar bagi grup kamar tanpa prosedur: baris
     * se-kasus (kunci kasus sama) dulu — MATCHED menang atas saran,
     * lalu spec tertinggi — fallback baris prosedur terdekat.
     * null bila tak ada jangkar yakin (MATCHED atau spec >= 2).
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, array>  $groups
     * @param  array<int, array<string, mixed>>  $byExcelRow
     * @return array{service_code: string, description: string, excel_row: int, old_code: string, kind: string}|null
     */
    protected function findKamarAnchor(array $group, array $groups, array $byExcelRow): ?array
    {
        $ownRows = array_map('intval', (array) ($group['rows'] ?? []));
        $ownCases = [];
        foreach ($ownRows as $excelRow) {
            $oldCode = trim((string) ($byExcelRow[$excelRow]['service_code'] ?? ''));
            $ownCases[self::caseKey($oldCode)] = true;
        }

        $scanRows = [];
        foreach ($groups as $other) {
            if ($other === $group) {
                continue;
            }
            foreach ((array) ($other['rows'] ?? []) as $excelRow) {
                $scanRows[(int) $excelRow] = $other;
            }
        }
        foreach ($byExcelRow as $excelRow => $prow) {
            if (in_array((int) $excelRow, $ownRows, true)) {
                continue;
            }
            $scanRows[(int) $excelRow] ??= null;
        }

        $best = null;
        $bestSameCase = null;
        foreach ($scanRows as $excelRow => $other) {
            $prow = $byExcelRow[(int) $excelRow] ?? null;
            if ($prow === null) {
                continue;
            }
            $candidate = $this->anchorFromRow($prow, $other);
            if ($candidate === null) {
                continue;
            }
            $candidate['excel_row'] = (int) $excelRow;
            $oldCode = trim((string) ($prow['service_code'] ?? ''));
            $candidate['old_code'] = $oldCode;
            $sameCase = isset($ownCases[self::caseKey($oldCode)]);
            $score = $candidate['kind'] === 'matched' ? 1000.0 : (float) ($candidate['spec'] ?? 0.0);
            if ($sameCase && ($bestSameCase === null || $score > $bestSameCase['score'])) {
                $bestSameCase = ['candidate' => $candidate, 'score' => $score];
            }
            if ($best === null
                || ($sameCase && ! $best['sameCase'])
                || ($sameCase === $best['sameCase'] && $score > $best['score'])
                || ($sameCase === $best['sameCase'] && $score === $best['score']
                    && abs($excelRow - min($ownRows)) < abs($best['candidate']['excel_row'] - min($ownRows)))
            ) {
                $best = ['candidate' => $candidate, 'score' => $score, 'sameCase' => $sameCase];
            }
        }

        return $bestSameCase !== null ? $bestSameCase['candidate'] : ($best['candidate'] ?? null);
    }

    /**
     * Ekstrak info jangkar dari satu baris preview: baris MATCHED
     * (prosedur pasti, description menyusul dari master) atau saran
     * teratas yang spec-nya meyakinkan (>= 2). null bila tak layak.
     *
     * @param  array<string, mixed>  $prow
     * @param  array<string, mixed>|null  $group
     * @return array{service_code: string, description: string, spec: float, kind: string}|null
     */
    protected function anchorFromRow(array $prow, ?array $group): ?array
    {
        if (($prow['status'] ?? '') === TarifBridgeResolver::STATUS_MATCHED
            && trim((string) ($prow['new_service_code'] ?? '')) !== ''
        ) {
            return [
                'service_code' => mb_strtoupper(trim((string) $prow['new_service_code'])),
                'description' => '',
                'spec' => 1000.0,
                'kind' => 'matched',
            ];
        }
        $suggestions = [];
        if ($group !== null && ! empty($group['suggestions'])) {
            $suggestions = $group['suggestions'];
        } elseif (! empty($prow['suggestions'])) {
            $suggestions = $prow['suggestions'];
        }
        if ($suggestions === []) {
            return null;
        }
        $top = $suggestions[0];
        if (trim((string) ($top['service_code'] ?? '')) === ''
            || ((float) ($top['spec_score'] ?? 0.0)) < 2.0
        ) {
            return null;
        }

        return [
            'service_code' => mb_strtoupper(trim((string) $top['service_code'])),
            'description' => (string) ($top['service_description'] ?? $top['service_name'] ?? ''),
            'spec' => (float) $top['spec_score'],
            'kind' => 'suggested',
        ];
    }

    /**
     * Kunci kasus dari Old Code: prefix sebelum "-" numerik akhir
     * ("868-1" -> "868"). Prefix berhuruf ("OKURO-O-030-002") tidak
     * dipecah — dianggap kunci sendiri agar tak salah kelompok.
     */
    protected static function caseKey(string $oldCode): string
    {
        $oldCode = trim($oldCode);
        if (preg_match('/^(\d+)-\d+$/', $oldCode, $m)) {
            return $m[1];
        }

        return mb_strtoupper($oldCode);
    }

    /** @param  array<int, array>  $candidates */
    protected function isValidChoice(array $candidates, mixed $choice, bool $manual): bool
    {
        if (! is_array($choice) || ! isset($choice['service_code'], $choice['class_code'])) {
            return false;
        }
        if ($manual) {
            // Grup NOT_FOUND: service wajib ada di master; kelas opsional
            // (kosong = ikut bawaan Excel, isi = override bila pair valid).
            if (! $this->resolver->repository()->serviceExists($choice['service_code'])) {
                return false;
            }
            $override = trim((string) ($choice['class_code'] ?? ''));

            return $override === ''
                || $this->resolver->repository()->pairExists($choice['service_code'], $override);
        }
        foreach ($candidates as $candidate) {
            if (mb_strtoupper(trim($candidate['service_code'])) === mb_strtoupper(trim($choice['service_code']))
                && mb_strtoupper(trim($candidate['class_code'])) === mb_strtoupper(trim($choice['class_code']))
            ) {
                return true;
            }
        }

        return false;
    }
}
