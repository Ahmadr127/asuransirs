<?php

namespace App\Services\Bridge;

/**
 * Menentukan status mapping satu baris + kode pengganti.
 * Tidak pernah membuat master baru — hanya membaca repository.
 */
class TarifBridgeResolver
{
    public const STATUS_MATCHED = 'MATCHED';

    public const STATUS_AMBIGUOUS = 'AMBIGUOUS';

    public const STATUS_NOT_FOUND = 'NOT_FOUND';

    public const STATUS_INVALID = 'INVALID';

    public function __construct(protected TarifBridgeRepository $repository) {}

    public function repository(): TarifBridgeRepository
    {
        return $this->repository;
    }

    /**
     * @param  array<string, mixed>  $normalized  hasil RowNormalizer
     * @return array{status: string, candidates: array, new_service_code: ?string, new_class_code: ?string}
     */
    public function resolve(array $normalized): array
    {
        if (($normalized['description_key'] ?? '') === '' || ($normalized['class_key'] ?? '') === '') {
            return [
                'status' => self::STATUS_INVALID,
                'candidates' => [],
                'new_service_code' => null,
                'new_class_code' => null,
            ];
        }

        $candidates = $this->repository->candidatesFor($normalized['mapping_key']);

        if ($candidates === []) {
            return [
                'status' => self::STATUS_NOT_FOUND,
                'candidates' => [],
                'new_service_code' => null,
                'new_class_code' => null,
            ];
        }

        if (count($candidates) > 1) {
            return [
                'status' => self::STATUS_AMBIGUOUS,
                'candidates' => $candidates,
                'new_service_code' => null,
                'new_class_code' => null,
            ];
        }

        return [
            'status' => self::STATUS_MATCHED,
            'candidates' => $candidates,
            'new_service_code' => $candidates[0]['service_code'],
            'new_class_code' => $candidates[0]['class_code'],
        ];
    }
}
