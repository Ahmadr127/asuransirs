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
        $summary = ['total' => 0, 'matched' => 0, 'ambiguous' => 0, 'not_found' => 0, 'invalid' => 0];
        /** @var array<string, array{description: string, kelas: string, candidates: array, rows: array<int>, resolved: ?array}> $groups */
        $groups = [];
        $preview = [];
        $decisions = [];

        $excelRow = 1; // baris 1 = header
        foreach ($rows as $rawRow) {
            $excelRow++;
            $normalized = BridgeTarifRowNormalizer::normalize($rawRow, $map, $excelRow);
            $resolved = $this->resolver->resolve($normalized);

            // Pilihan manual user untuk grup ambigu berlaku ke semua row se-key.
            if ($resolved['status'] === TarifBridgeResolver::STATUS_AMBIGUOUS
                && isset($resolutions[$normalized['mapping_key']])
                && $this->isValidChoice($resolved['candidates'], $resolutions[$normalized['mapping_key']])
            ) {
                $choice = $resolutions[$normalized['mapping_key']];
                $resolved['status'] = TarifBridgeResolver::STATUS_MATCHED;
                $resolved['new_service_code'] = $choice['service_code'];
                $resolved['new_class_code'] = $choice['class_code'];
            }

            $summary['total']++;
            match ($resolved['status']) {
                TarifBridgeResolver::STATUS_MATCHED => $summary['matched']++,
                TarifBridgeResolver::STATUS_AMBIGUOUS => $summary['ambiguous']++,
                TarifBridgeResolver::STATUS_NOT_FOUND => $summary['not_found']++,
                default => $summary['invalid']++,
            };

            if ($resolved['status'] === TarifBridgeResolver::STATUS_AMBIGUOUS) {
                $key = $normalized['mapping_key'];
                $groups[$key] ??= [
                    'description' => $normalized['service_description'],
                    'kelas' => $normalized['class_name'],
                    'candidates' => $resolved['candidates'],
                    'rows' => [],
                    'resolved' => null,
                ];
                $groups[$key]['rows'][] = $excelRow;
            }

            $decisions[$excelRow] = [
                'status' => $resolved['status'],
                'new_service_code' => $resolved['new_service_code'],
                'new_class_code' => $resolved['new_class_code'],
            ];

            if (count($preview) < self::PREVIEW_LIMIT) {
                $preview[] = array_merge($normalized, [
                    'status' => $resolved['status'],
                    'new_service_code' => $resolved['new_service_code'],
                    'new_class_code' => $resolved['new_class_code'],
                ]);
            }
        }

        // Tandai grup yang sudah dipilih user.
        foreach ($groups as $key => $group) {
            if (isset($resolutions[$key]) && $this->isValidChoice($group['candidates'], $resolutions[$key])) {
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

    /** @param  array<int, array>  $candidates */
    protected function isValidChoice(array $candidates, mixed $choice): bool
    {
        if (! is_array($choice) || ! isset($choice['service_code'], $choice['class_code'])) {
            return false;
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
