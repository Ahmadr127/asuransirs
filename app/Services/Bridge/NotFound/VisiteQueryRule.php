<?php

namespace App\Services\Bridge\NotFound;

/**
 * Aturan query khusus pola VISITE + nama dokter.
 *
 * Description Excel seperti:
 *   "Visite Puja Laksana Maqbul, dr., Sp.An., FIPM"
 * tidak bisa dicari apa adanya (nama orang tidak ada di master).
 * Ditulis ulang menjadi frasa kanonis master:
 *   - ada gelar spesialis di belakang dr/DR (Sp*, spesialis, konsulen,
 *     FIPM/FIPP, ...) -> "VISITE DOKTER SPESIALIS"
 *   - sedikit/tanpa gelar (mis. "Visite Ahmad Yani, dr.") ->
 *     "VISITE DOKTER UMUM"
 *
 * Murni string (tanpa DB, tanpa ranking) agar murah dan mudah ditest.
 * Dipakai NotFoundResolver::suggest() sebelum ekstraksi inti tindakan;
 * bila cocok, hasil rewrite dipakai langsung sebagai query search
 * (melewati pemotongan noise/kategori karena frasa sudah kanonis —
 * catatan: "spesialis" sendiri ada di daftar noise, jadi rewrite
 * tidak boleh dilewatkan ekstraksi itu lagi).
 */
final class VisiteQueryRule
{
    public const SEARCH_UMUM = 'VISITE DOKTER UMUM';

    public const SEARCH_SPESIALIS = 'VISITE DOKTER SPESIALIS';

    /**
     * Kembalikan frasa pencarian kanonis, atau null bila description
     * bukan pola visite + dokter (caller lanjut ke logika normal).
     */
    public static function rewrite(string $description): ?string
    {
        if (! preg_match('/\bvisite\b/iu', $description)) {
            return null;
        }
        // Pola dokter: ada penanda dr/DR di mana pun setelah kata visite.
        if (! preg_match('/\bdr\.?/iu', $description)) {
            return null;
        }

        return self::hasSpecialistTitle($description)
            ? self::SEARCH_SPESIALIS
            : self::SEARCH_UMUM;
    }

    /**
     * Gelar spesialis di SELURUH description (praktisnya gelar selalu
     * menempel di belakang "dr", mis. "dr., Sp.An., FIPM"). "sp" wajib
     * token persis agar "sphincterotomi" tidak ikut cocok; sisanya
     * boleh awalan kata.
     */
    protected static function hasSpecialistTitle(string $description): bool
    {
        $normalized = mb_strtolower($description);
        $normalized = (string) preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = ' '.trim((string) preg_replace('/\s+/', ' ', $normalized)).' ';

        return (bool) preg_match(
            '/\ssp\s|\sspesialis|\skonsulen|\sfipm|\sfipp|\ssubspesialis/',
            $normalized
        );
    }
}
