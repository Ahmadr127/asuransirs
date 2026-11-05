<?php

namespace App\Services\Bridge;

use App\Services\Bridge\Ambiguous\ObatAlkesFamilyRule;

/**
 * Loop baris Excel: normalisasi -> resolve -> kumpulkan ringkasan,
 * grup ambigu (per mapping key), dan sampel preview.
 */
class BridgeTarifProcessor
{
    public const PREVIEW_LIMIT = 200;

    protected RoomChargeClassService $roomChargeClass;

    public function __construct(
        protected TarifBridgeResolver $resolver,
        ?RoomChargeClassService $roomChargeClass = null,
    ) {
        $this->roomChargeClass = $roomChargeClass
            ?? new RoomChargeClassService($resolver->repository());
    }

    public function repository(): TarifBridgeRepository
    {
        return $this->resolver->repository();
    }


    public function process(array $rows, array $map, array $resolutions = []): array
    {
        $summary = ['total' => 0, 'matched' => 0, 'ambiguous' => 0, 'not_found' => 0, 'invalid' => 0, 'suggested' => 0];
        /** @var array<string, array{description: string, kelas: string, candidates: array, rows: array<int>, resolved: ?array}> $groups */
        $groups = [];
        $preview = [];
        $decisions = [];

        
        $normalizedList = [];
        $excelRow = 1; 
        foreach ($rows as $rawRow) {
            $excelRow++;
            $normalizedList[] = BridgeTarifRowNormalizer::normalize($rawRow, $map, $excelRow);
        }

        
        
        
        $this->applyGroupTariffFallback($normalizedList);

        
        /** @var array<string, array<int, array{t: float, s: ?string}>> $groupTariffs */
        $groupTariffs = [];

        
        
        foreach ($normalizedList as $normalized) {
            $excelRow = $normalized['excel_row'];
            $resolved = $this->resolver->resolve($normalized);

            
            
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
                $resolved['new_service_description'] = $this->descriptionForChoice(
                    $resolved['candidates'],
                    $choice,
                    $isManual
                );

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
                'new_class_name' => null,
                'new_service_description' => $resolved['new_service_description'] ?? null,
                'suggested_applied' => $resolved['suggested_applied'] ?? false,
            ];

            if (count($preview) < self::PREVIEW_LIMIT) {
                $preview[] = array_merge($normalized, [
                    'status' => $resolved['status'],
                    'new_service_code' => $resolved['new_service_code'],
                    'new_class_code' => $resolved['new_class_code'],
                    'new_service_description' => $resolved['new_service_description'] ?? null,
                    
                    
                    'candidates' => $resolved['candidates'] ?? [],
                    'suggested' => $resolved['suggested'] ?? null,
                    'suggested_applied' => $resolved['suggested_applied'] ?? false,
                    'analysis' => $resolved['analysis'] ?? null,
                    
                    'search_query' => $resolved['search_query'] ?? null,
                    'search_rule' => $resolved['search_rule'] ?? null,
                ]);
            }
        }


        foreach ($groups as $key => $group) {
            $groups[$key]['tariff_ref'] = $this->summarizeGroupTariffs($groupTariffs[$key] ?? []);
        }


        foreach ($groups as $key => $group) {
            if (! ($group['manual'] ?? false)) {
                continue;
            }
            $tariff = $groups[$key]['tariff_ref']['tariff'] ?? null;
            $kelas = trim((string) ($group['kelas'] ?? ''));
            $meta = $this->resolver->notFound()->suggestWithMeta(
                (string) $group['description'],
                null,
                $kelas !== '' ? $kelas : null,
                is_numeric($tariff) ? (float) $tariff : null,
                10,
            );
            $groups[$key]['suggestions'] = $meta['services'];
            $groups[$key]['search_query'] = $meta['search_query'];
            $groups[$key]['search_rule'] = $meta['search_rule'];
        }


        $this->applyKamarAnchors($groups, $preview);

   
        foreach ($groups as $key => $group) {
            if (! ($group['manual'] ?? false) || empty($group['suggestions'])) {
                continue;
            }
            $top = $group['suggestions'][0];
            $code = trim((string) ($top['service_code'] ?? ''));
            if ($code === '') {
                continue;
            }
        
            $tariffDiff = $top['_tariff_diff'] ?? null;
            $strongSingle = ((float) ($top['spec_score'] ?? 0.0)) >= 3.0
                && ((int) ($top['_class_match'] ?? 0)) === 1
                && is_numeric($tariffDiff) && (float) $tariffDiff <= 0.5;
            if ((int) ($top['desc_matched'] ?? 0) < 2 && ! $strongSingle) {
                continue;
            }
            $groups[$key]['top_service'] = ObatAlkesFamilyRule::categoryForJenis(
                $this->resolver->repository()->pairJenis(
                    (string) ($top['service_code'] ?? ''),
                    (string) ($top['class_code'] ?? '')
                )
            ) ?? $top['service_code'];
            $groups[$key]['top_service_description'] = TarifBridgeResolver::candidateDescription($top)
                ?? $this->resolver->repository()->serviceDescription((string) $top['service_code']);
            $codes = array_values(array_filter(array_unique(array_map(
                fn ($s) => mb_strtoupper(trim((string) ($s['service_code'] ?? ''))),
                $group['suggestions']
            ))));
            $consensus = count($codes) === 1;
            if ($consensus) {
                $groups[$key]['consensus'] = true;
            }
            $topClass = trim((string) ($top['class_code'] ?? ''));
            $topDesc = $groups[$key]['top_service_description'] ?? null;
            foreach ($group['rows'] as $excelRow) {
                if (isset($decisions[$excelRow])) {
                    $decisions[$excelRow]['new_service_code'] = $groups[$key]['top_service'];
                    $decisions[$excelRow]['new_service_description'] = $topDesc;
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

        
        foreach ($preview as $i => $row) {
            if (($row['status'] ?? '') === TarifBridgeResolver::STATUS_NOT_FOUND) {
                $key = $row['mapping_key'];
                $preview[$i]['suggestions'] = $groups[$key]['suggestions'] ?? [];
                
                
                if (isset($groups[$key]['search_query'])) {
                    $preview[$i]['search_query'] = $groups[$key]['search_query'];
                    $preview[$i]['search_rule'] = $groups[$key]['search_rule'] ?? null;
                }
                if (! empty($groups[$key]['anchor_note'])) {
                    $preview[$i]['analysis'] = trim(
                        (string) ($preview[$i]['analysis'] ?? '').' '.$groups[$key]['anchor_note']
                    );
                }
                if (isset($groups[$key]['top_service'])) {
                    $preview[$i]['new_service_code'] = $groups[$key]['top_service'];
                    $preview[$i]['new_service_description'] = $groups[$key]['top_service_description'] ?? null;
                    $preview[$i]['suggested_applied'] = true;
                    $preview[$i]['suggested'] = [
                        'service_code' => $groups[$key]['top_service'],
                        'class_code' => $groups[$key]['suggestions'][0]['class_code'] ?? null,
                        'service_description' => $groups[$key]['top_service_description'] ?? null,
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

 
        $byRow = [];
        foreach ($normalizedList as $normalized) {
            $byRow[(int) ($normalized['excel_row'] ?? 0)] = $normalized;
        }
        $this->roomChargeClass->apply($decisions, $preview, $normalizedList);


        foreach ($decisions as $excelRow => $decision) {
            $oldDesc = (string) ($byRow[(int) $excelRow]['service_description'] ?? '');
            $replaced = (($decision['status'] ?? '') === TarifBridgeResolver::STATUS_MATCHED
                    || ! empty($decision['suggested_applied']))
                && trim((string) ($decision['new_service_code'] ?? '')) !== '';
            $flagDesc = ($replaced && trim((string) ($decision['new_service_description'] ?? '')) !== '')
                ? (string) $decision['new_service_description']
                : $oldDesc;
            $decisions[$excelRow]['surgery_flag'] = BridgeTarifSurgeryFlag::label(null, $flagDesc);
        }
        foreach ($preview as $i => $row) {
            $key = (int) ($row['excel_row'] ?? 0);
            if (isset($decisions[$key]['surgery_flag'])) {
                $preview[$i]['surgery_flag'] = $decisions[$key]['surgery_flag'];
            }
        }

        
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
            $siblingMeta = $this->resolver->notFound()->suggestKamarSiblingWithMeta(
                $anchorDesc,
                $kelas !== '' ? $kelas : null,
                is_numeric($tariff) ? (float) $tariff : null,
                10,
            );
            $sibling = $siblingMeta['services'];
            if ($sibling === []) {
                continue;
            }
            $groups[$key]['suggestions'] = $sibling;
            $groups[$key]['search_query'] = $siblingMeta['search_query'];
            $groups[$key]['search_rule'] = $siblingMeta['search_rule'];
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
    protected function descriptionForChoice(array $candidates, mixed $choice, bool $manual): ?string
    {
        $serviceCode = mb_strtoupper(trim((string) ($choice['service_code'] ?? '')));
        if ($serviceCode === '') {
            return null;
        }
        if (! $manual) {
            $classCode = mb_strtoupper(trim((string) ($choice['class_code'] ?? '')));
            foreach ($candidates as $candidate) {
                if (mb_strtoupper(trim((string) ($candidate['service_code'] ?? ''))) === $serviceCode
                    && mb_strtoupper(trim((string) ($candidate['class_code'] ?? ''))) === $classCode
                ) {
                    $desc = TarifBridgeResolver::candidateDescription($candidate);
                    if ($desc !== null) {
                        return $desc;
                    }
                    break;
                }
            }
        }

        return $this->resolver->repository()->serviceDescription($serviceCode);
    }

    /** @param  array<int, array>  $candidates */
    protected function isValidChoice(array $candidates, mixed $choice, bool $manual): bool
    {
        if (! is_array($choice) || ! isset($choice['service_code'], $choice['class_code'])) {
            return false;
        }
        if ($manual) {
            
            
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
