<?php

namespace App\Services\Bridge\Ambiguous;

/**
 * DTO hasil disambiguasi satu baris Excel.
 *
 * Layer: Application (output Resolver).
 *
 * Kebijakan: scan TIDAK pernah auto-MATCHED dari jalur ambiguous —
 * status selalu tetap AMBIGUOUS agar terlihat di UI. Bila ada pemenang
 * konservatif, ia disampaikan sebagai `suggested` (rekomendasi) +
 * `reason` (penjelasan) untuk modal detail analisa per baris.
 * - matched=true  -> legacy (dipertahankan untuk BC, sudah tidak dipakai
 *   resolver; gunakan suggested sebagai rekomendasi non-memaksa).
 * - matched=false -> ranked terisi (terurut skor), caller tetap AMBIGUOUS
 *   dan menyodorkan ranked + suggested ke UI manual.
 */
final class AmbiguousResult
{
    /**
     * @param  array<int, array<string, mixed>>  $ranked  kandidat terurut skor
     * @param  array<string, mixed>|null  $winner  legacy (BC)
     * @param  array<string, mixed>|null  $suggested  rekomendasi non-memaksa
     */
    public function __construct(
        public readonly bool $matched,
        public readonly ?array $winner,
        public readonly array $ranked,
        public readonly ?array $suggested = null,
        public readonly ?string $reason = null,
    ) {}

    public static function autoMatched(array $winner, array $ranked): self
    {
        return new self(true, $winner, $ranked, $winner, null);
    }

    /** @param  array<int, array<string, mixed>>  $ranked */
    public static function stillAmbiguous(array $ranked, ?array $suggested = null, ?string $reason = null): self
    {
        return new self(false, null, $ranked, $suggested, $reason);
    }
}
