<?php

namespace App\Services\Bridge\NotFound;

/**
 * DTO hasil jalur NOT_FOUND (kerangka — strategi belum ditentukan user).
 *
 * Layer: Application (output Resolver). Saat ini selalu
 * resolved=false + suggestions=[] (perilaku lama: murni manual via
 * BridgeServiceSearch). Struktur ini disiapkan agar strategi nanti
 * (mis. fuzzy description / tarif terdekat) tinggal mengisi
 * suggestions tanpa mengubah caller.
 */
final class NotFoundResult
{
    /** @param  array<int, array<string, mixed>>  $suggestions */
    public function __construct(
        public readonly bool $resolved,
        public readonly ?array $choice,
        public readonly array $suggestions,
    ) {}

    /** @param  array<int, array<string, mixed>>  $suggestions */
    public static function manual(array $suggestions = []): self
    {
        return new self(false, null, $suggestions);
    }
}
