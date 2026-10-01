<?php

namespace App\Services\Bridge\Ambiguous;

/**
 * Satu file kondisi famili obat/alkes/makanan untuk jalur AMBIGUOUS.
 *
 * - familyOf/matches: kode lama Excel ("OBAT", "OBT...", "ALK...",
 *   "MAKANAN", "MKN...") memetakan ke famili OBT/ALK/MKN; kandidat
 *   se-famili diutamakan di ranking. Tanpa pola famili: tidak
 *   berpengaruh sama sekali.
 * - categoryForJenis: kategori tarif ("obat" → "OBAT",
 *   "makanan" → "MAKANAN", "alkes"/"alat kesehatan" →
 *   "ALAT KESEHATAN") dipakai mengisi New Code. Batas kata dipakai
 *   agar "peralatan" tidak terbaca sebagai alat kesehatan.
 */
final class ObatAlkesFamilyRule
{
    public const FAMILY_OBT = 'OBT';

    public const FAMILY_ALK = 'ALK';

    public const FAMILY_MKN = 'MKN';

    public const FAMILY_BHP = 'BHP';

    /**
     * Famili kode lama (OBT/ALK/MKN), null bila tidak berpola famili.
     * "OBAT" → OBT (mengandung kata OBAT); "OBT..." → OBT;
     * "ALK...", "ALKES..." → ALK; "MAKANAN"/"MKN..." → MKN.
     */
    public static function familyOf(?string $code): ?string
    {
        $norm = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim((string) $code)));
        if ($norm === '') {
            return null;
        }
        if (str_starts_with($norm, self::FAMILY_OBT) || str_contains($norm, 'OBAT')) {
            return self::FAMILY_OBT;
        }
        if (str_starts_with($norm, self::FAMILY_ALK)) {
            return self::FAMILY_ALK;
        }
        if (str_starts_with($norm, self::FAMILY_MKN) || str_contains($norm, 'MAKAN')) {
            return self::FAMILY_MKN;
        }
        if (str_starts_with($norm, self::FAMILY_BHP)) {
            return self::FAMILY_BHP;
        }

        return null;
    }

    /**
     * True bila kode kandidat master se-famili dengan famili kode lama.
     */
    public static function matches(?string $family, ?string $candidateCode): bool
    {
        if ($family === null) {
            return false;
        }
        $norm = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim((string) $candidateCode)));

        return $norm !== '' && str_starts_with($norm, $family);
    }

    /**
     * Label kategori dari jenis tarif, null untuk kategori lain.
     */
    public static function categoryForJenis(?string $jenis): ?string
    {
        $norm = ' '.(string) preg_replace('/[^A-Z0-9]+/', ' ', mb_strtoupper(trim((string) $jenis))).' ';
        if (str_contains($norm, ' OBAT ')) {
            return 'OBAT';
        }
        if (str_contains($norm, ' MAKAN')) {
            return 'MAKANAN';
        }
        if (str_contains($norm, ' ALKES ') || str_contains($norm, ' ALAT ')) {
            return 'ALKES';
        }
        if (str_contains($norm, ' BHP ')) {
            return 'BHP';
        }

        return null;
    }
}
