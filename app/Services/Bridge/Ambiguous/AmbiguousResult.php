<?php

namespace App\Services\Bridge\Ambiguous;

/**
 * DTO hasil disambiguasi satu baris Excel.
 *
 * Layer: Application (output Resolver).
 * - matched=true  -> winner terisi, caller boleh anggap MATCHED.
 * - matched=false -> ranked terisi (terurut skor), caller tetap AMBIGUOUS
 *   dan menyodorkan ranked ke UI manual.
 */
final class AmbiguousResult
{
    /**
     * @param  array<int, array<string, mixed>>  $ranked  kandidat terurut skor
     * @param  array<string, mixed>|null  $winner
     */
    public function __construct(
        public readonly bool $matched,
        public readonly ?array $winner,
        public readonly array $ranked,
    ) {}

    public static function autoMatched(array $winner, array $ranked): self
    {
        return new self(true, $winner, $ranked);
    }

    /** @param  array<int, array<string, mixed>>  $ranked */
    public static function stillAmbiguous(array $ranked): self
    {
        return new self(false, null, $ranked);
    }
}
