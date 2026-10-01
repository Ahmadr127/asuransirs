<?php

namespace App\Services\Bridge;

use App\Services\Bridge\Ambiguous\AmbiguousRanker;
use App\Services\Bridge\Ambiguous\AmbiguousRepository;
use App\Services\Bridge\Ambiguous\AmbiguousResolver;
use App\Services\Bridge\NotFound\NotFoundRepository;
use App\Services\Bridge\NotFound\NotFoundResolver;

/**
 * Menentukan status mapping satu baris + kode pengganti.
 * Tidak pernah membuat master baru — hanya membaca repository.
 *
 * Jalur AMBIGUOUS didelegasikan ke layered Ambiguous/
 * (Repository -> Ranker -> Resolver): kandidat diranking berbasis
 * KELAS + TARIF Excel. Status scan SELALU tetap AMBIGUOUS (tidak ada
 * auto-MATCHED); pemenang konservatif hanya menjadi `suggested` +
 * `analysis` untuk modal detail analisa per baris.
 * Jalur NOT_FOUND menyertakan `analysis` dari layered NotFound/.
 */
class TarifBridgeResolver
{
    public const STATUS_MATCHED = 'MATCHED';

    public const STATUS_AMBIGUOUS = 'AMBIGUOUS';

    public const STATUS_NOT_FOUND = 'NOT_FOUND';

    public const STATUS_INVALID = 'INVALID';

    protected AmbiguousResolver $ambiguous;

    protected NotFoundResolver $notFound;

    public function __construct(
        protected TarifBridgeRepository $repository,
        ?AmbiguousResolver $ambiguous = null,
        ?NotFoundResolver $notFound = null,
    ) {
        // Default: bungkus repository YANG SAMA agar preload sekali
        // (tanpa query tambahan) + tetap bisa di-inject dari container.
        $this->ambiguous = $ambiguous
            ?? new AmbiguousResolver(
                new AmbiguousRepository($repository),
                new AmbiguousRanker()
            );
        $this->notFound = $notFound
            ?? new NotFoundResolver(new NotFoundRepository($repository));
    }

    public function repository(): TarifBridgeRepository
    {
        return $this->repository;
    }

    public function ambiguous(): AmbiguousResolver
    {
        return $this->ambiguous;
    }

    public function notFound(): NotFoundResolver
    {
        return $this->notFound;
    }

    /**
     * @param  array<string, mixed>  $normalized  hasil RowNormalizer
     * @return array{status: string, candidates: array, new_service_code: ?string, new_class_code: ?string, new_service_description: ?string, suggested: ?array, suggested_applied: bool, analysis: ?string, search_query: ?string, search_rule: ?string}
     */
    public function resolve(array $normalized): array
    {
        // Kode kelas master diproses independen dari status mapping:
        // selama kelas ada di master (cocok nama/kode), new_class_code
        // terisi walau service-nya masih AMBIGUOUS/NOT_FOUND/INVALID.
        $masterClassCode = $this->repository->classCodeFor(
            $normalized['class_name'] ?? '',
            $normalized['service_class_code'] ?? ''
        );

        if (($normalized['description_key'] ?? '') === '' || ($normalized['class_key'] ?? '') === '') {
            return [
                'status' => self::STATUS_INVALID,
                'candidates' => [],
                'new_service_code' => null,
                'new_class_code' => $masterClassCode,
                'new_service_description' => null,
                'suggested' => null,
                'suggested_applied' => false,
                'analysis' => 'Baris tidak lengkap: description atau kelas kosong setelah normalisasi, sehingga tidak bisa dicari di master. Lengkapi kedua kolom tersebut di Excel lalu scan ulang.',
                'search_query' => null,
                'search_rule' => null,
            ];
        }

        $candidates = $this->repository->candidatesFor($normalized['mapping_key']);

        if ($candidates === []) {
            $searchMeta = $this->notFound()->searchQueryFor(
                (string) ($normalized['service_description'] ?? ''),
                trim((string) ($normalized['class_name'] ?? '')) !== '' ? (string) $normalized['class_name'] : null
            );

            return [
                'status' => self::STATUS_NOT_FOUND,
                'candidates' => [],
                'new_service_code' => null,
                'new_class_code' => $masterClassCode,
                'new_service_description' => null,
                'suggested' => null,
                'suggested_applied' => false,
                'analysis' => $this->notFound()->explain($normalized, $masterClassCode),
                'search_query' => $searchMeta['query'],
                'search_rule' => $searchMeta['rule'],
            ];
        }

        if (count($candidates) > 1) {
            // Layered Ambiguous: ranking kelas+tarif + rekomendasi, status
            // SELALU tetap AMBIGUOUS agar terlihat. Saran (bila ada)
            // LANGSUNG masuk ke new code agar tampil di preview dan ikut
            // ke file hasil generate; user tetap bisa menimpa via manual.
            $disambiguated = $this->ambiguous->resolve($normalized, $candidates);
            $suggested = $disambiguated->suggested;

            return [
                'status' => self::STATUS_AMBIGUOUS,
                'candidates' => $disambiguated->ranked,
                'new_service_code' => $suggested['service_code'] ?? null,
                'new_class_code' => $suggested['class_code'] ?? $masterClassCode,
                'new_service_description' => self::candidateDescription($suggested),
                'suggested' => $suggested,
                'suggested_applied' => $suggested !== null,
                'analysis' => $disambiguated->reason,
                'search_query' => null,
                'search_rule' => null,
            ];
        }

        return [
            'status' => self::STATUS_MATCHED,
            'candidates' => $candidates,
            'new_service_code' => $candidates[0]['service_code'],
            'new_class_code' => $candidates[0]['class_code'],
            'new_service_description' => self::candidateDescription($candidates[0]),
            'suggested' => null,
            'suggested_applied' => false,
            'analysis' => null,
            'search_query' => null,
            'search_rule' => null,
        ];
    }

    /**
     * Description master dari satu kandidat/saran: services.description
     * (fallback services.name). null bila keduanya kosong.
     *
     * @param  array<string, mixed>|null  $candidate
     */
    public static function candidateDescription(?array $candidate): ?string
    {
        if (! is_array($candidate)) {
            return null;
        }
        $desc = trim((string) ($candidate['service_description'] ?? ''));
        if ($desc === '') {
            $desc = trim((string) ($candidate['service_name'] ?? ''));
        }

        return $desc !== '' ? $desc : null;
    }
}
