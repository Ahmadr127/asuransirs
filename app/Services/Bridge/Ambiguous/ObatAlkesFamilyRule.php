<?php

namespace App\Services\Bridge\Ambiguous;

/**
 * Satu file kondisi famili obat/alkes untuk jalur AMBIGUOUS.
 *
 * Kode lama Excel untuk obat/alat kesehatan sering generik ("OBAT")
 * atau berseri ("OBT...", "ALK...", "ALKES..."), sementara master
 * memuat seri ganda untuk barang yang sama (mis. OBT02429 vs
 * ALK00415 "Isorane" dengan tarif beda jauh karena satuan berbeda).
 * Bila kode lama memetakan ke famili OBT/ALK, kandidat se-famili
 * diutamakan — tarif saja tidak boleh menyeberangkan famili.
 *
 * Tanpa pola famili pada kode lama: tidak berpengaruh sama sekali
 * (semua kandidat bernilai 0) sehingga perilaku lama persis.
 */
final class ObatAlkesFamilyRule
{
    public const FAMILY_OBT = 'OBT';

    public const FAMILY_ALK = 'ALK';

    /**
     * Famili kode lama (OBT/ALK), null bila tidak berpola famili.
     * "OBAT" → OBT (mengandung kata OBAT); "OBT..." → OBT;
     * "ALK...", "ALKES..." → ALK.
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
}
