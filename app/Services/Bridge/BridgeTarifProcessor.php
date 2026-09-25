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
