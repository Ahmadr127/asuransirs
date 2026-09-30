<?php

namespace App\Services\Bridge;

/**
 * Aturan kolom RUANG BEDAH (SURGERY) / RUANG NON BEDAH (NON SURGERY)
 * pada file bridge: 'OK' bila deskripsi memuat kata "tindakan" atau
 * "bedah", selain itu 'NON OK'. Perbandingan case-insensitive.
 */
final class BridgeTarifSurgeryFlag
{
    public const OK = 'OK';

    public const NON_OK = 'NON OK';

    public static function label(?string $name = null, ?string $description = null): string
    {
        $hay = mb_strtolower(trim((string) $name).' '.trim((string) $description));
        if (str_contains($hay, 'tindakan') || str_contains($hay, 'bedah')) {
            return self::OK;
        }

        return self::NON_OK;
    }

    /**
     * True bila header kolom adalah kolom LoS (Length of Stay) yang
     * wajib dibuang dari file hasil generate.
     */
    public static function isLosHeader(?string $header): bool
    {
        $norm = BridgeTarifExcelReader::normalizeHeader((string) $header);

        return $norm === 'los' || str_contains($norm, 'lengthofstay');
    }
}
