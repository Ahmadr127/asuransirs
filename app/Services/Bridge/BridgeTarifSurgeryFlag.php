<?php

namespace App\Services\Bridge;

/**
 * Aturan kolom RUANG BEDAH (SURGERY) / RUANG NON BEDAH (NON SURGERY)
 * pada file bridge: 'OK' bila deskripsi memuat kata "tindakan" atau
 * "bedah", selain itu 'NON OK'. Pengecualian: bila deskripsi memuat
 * nama alat/bahan (config bridge.surgery.excluded_words, mis. "pisau"
 * pada "Pisau Bedah") maka tetap 'NON OK'. Perbandingan
 * case-insensitive; pengecualian memakai whole-word.
 */
final class BridgeTarifSurgeryFlag
{
    public const OK = 'OK';

    public const NON_OK = 'NON OK';

    /**
     * Nilai default; efektif dibaca dari
     * config('bridge.surgery.excluded_words').
     */
    public const EXCLUDED_KEYWORDS = [
        'pisau',
        'benang',
        'foto',
        'gunting',
        'pinset',
        'klem',
        'jarum',
        'hecting',
        'catgut',
        'masker',
        'baju',
        'topi',
        'doek',
        'sarung',
        'handscoen',
        'handschoen',
    ];

    /** @return array<int, string> */
    protected static function excludedWords(): array
    {
        $configured = config('bridge.surgery.excluded_words');

        return is_array($configured) && $configured !== [] ? array_values($configured) : self::EXCLUDED_KEYWORDS;
    }

    public static function label(?string $name = null, ?string $description = null): string
    {
        $hay = mb_strtolower(trim((string) $name).' '.trim((string) $description));
        $words = ' '.(string) preg_replace('/[^a-z0-9]+/', ' ', $hay).' ';
        $words = (string) preg_replace('/\s+/', ' ', $words);
        foreach (self::excludedWords() as $excluded) {
            $excluded = trim(mb_strtolower((string) $excluded));
            if ($excluded !== '' && str_contains($words, ' '.$excluded.' ')) {
                return self::NON_OK;
            }
        }
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
