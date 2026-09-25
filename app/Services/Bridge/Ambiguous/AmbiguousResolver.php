<?php

namespace App\Services\Bridge\Ambiguous;

/**
 * Application layer AMBIGUOUS: orkestrasi rank -> rekomendasi.
 *
 * Dipakai TarifBridgeResolver saat kandidat > 1:
 *  - rank() selalu dijalankan agar daftar manual terurut paling relevan
 *    (skor kelas + tarif) — bukan lagi urutan kode mentah.
 *  - shouldAutoMatch() dipakai sebagai REKOMENDASI (suggested), bukan
 *    keputusan: status scan selalu tetap AMBIGUOUS. Pemenang hanya
 *    ditampilkan di modal detail analisa + urutan teratas.
 *  - explain() menghasilkan kalimat analisa untuk modal per baris.
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
        // Rekomendasi saja — tidak pernah mengubah status menjadi MATCHED.
        $suggested = $this->ranker->shouldAutoMatch($ranked, $normalized);
        $reason = $this->ranker->explain($ranked, $normalized, $suggested);

        return AmbiguousResult::stillAmbiguous($ranked, $suggested, $reason);
    }
}
