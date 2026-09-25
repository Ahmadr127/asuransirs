<?php

namespace App\Services\Bridge\Ambiguous;

/**
 * Application layer AMBIGUOUS: orkestrasi rank -> putuskan.
 *
 * Dipakai TarifBridgeResolver saat kandidat > 1:
 *  - rank() selalu dijalankan agar daftar manual terurut paling relevan
 *    (skor kelas + tarif) — bukan lagi urutan kode mentah.
 *  - shouldAutoMatch() konservatif: hanya menang bila tarif Excel cocok
 *    nyaris persis dengan SATU kandidat dan gap jelas. Selebihnya tetap
 *    AMBIGUOUS untuk dipilih manual (tidak ada tebak-tebakan berisiko).
 */
final class AmbiguousResolver
{
    public function __construct(
        protected AmbiguousRepository $repository,
        protected AmbiguousRanker $ranker,
    ) {}

    public function repository(): AmbiguousRepository
    {
        return $this->repository;
    }

    /**
     * @param  array<string, mixed>  $normalized  hasil RowNormalizer
     * @param  array<int, array<string, mixed>>|null  $candidates  null = ambil dari repository
     */
    public function resolve(array $normalized, ?array $candidates = null): AmbiguousResult
    {
        $candidates ??= $this->repository->findCandidates($normalized['mapping_key'] ?? '');

        if (count($candidates) <= 1) {
            // Bukan kasus ambiguous — kembalikan apa adanya agar caller
            // menangani jalur MATCHED/NOT_FOUND seperti biasa.
            return AmbiguousResult::stillAmbiguous(array_values($candidates));
        }

        $ranked = $this->ranker->rank(array_values($candidates), $normalized);
        $winner = $this->ranker->shouldAutoMatch($ranked, $normalized);

        if ($winner !== null) {
            return AmbiguousResult::autoMatched($winner, $ranked);
        }

        return AmbiguousResult::stillAmbiguous($ranked);
    }
}
