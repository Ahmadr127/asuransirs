<?php

namespace App\Services\Bridge\NotFound;

/**
 * Aturan query khusus pola ROOM CHARGE + level kelas.
 *
 * Description Excel seperti:
 *   "Room Charge KELAS 2"
 * tidak bisa dicari apa adanya: master memakai bahasa Indonesia
 * ("Kamar Perawatan Kelas 2", kode KMK2) sehingga token "room" tidak
 * cocok apa pun, token "charge" justru nyangkut ke "Drill Charge",
 * dan angka "2" terbuang saat tokenisasi (min. 2 karakter) sehingga
 * KMK1/KMK2/KMK3 tak terbedakan. Ditulis ulang menjadi frasa kanonis
 * master: "KAMAR PERAWATAN KELAS 2" (exact tier 5 bila level tepat).
 *
 * Level dibaca dari description dulu ("KELAS 2", "VIP", "SUITE", ...),
 * fallback ke nama kelas kolom Excel bila description tak menyebut
 * level eksplisit. Tanpa level yang dikenali: "KAMAR PERAWATAN" saja
 * (semua kamar tie di teks, pemenang ditentukan pasangan kelas+tarif).
 *
 * Murni string (tanpa DB, tanpa ranking) agar murah dan mudah ditest.
 * Dipakai NotFoundResolver::suggest() sebelum rewrite visite dan
 * ekstraksi inti tindakan.
 */
final class RoomChargeQueryRule
{
    public const SEARCH_BASE = 'KAMAR PERAWATAN';

    /**
     * Kembalikan frasa pencarian kanonis, atau null bila description
     * bukan pola room charge (caller lanjut ke logika normal).
     */
    public static function rewrite(string $description, ?string $className = null): ?string
    {
        if (! preg_match('/\broom\b/iu', $description)) {
            return null;
        }

        $level = self::levelFrom($description) ?? self::levelFrom((string) $className);

        return $level !== null ? self::SEARCH_BASE.' '.$level : self::SEARCH_BASE;
    }

    /**
     * Petakan sebutan level ke suffix kanonis master.
     * Urutan penting: VVIP sebelum VIP, KELAS berangka sebelum umum.
     */
    protected static function levelFrom(string $text): ?string
    {
        $normalized = ' '.trim((string) preg_replace('/[^a-z0-9]+/iu', ' ', $text)).' ';

        if (preg_match('/\bkelas\s*([123])\b/iu', $normalized, $m)) {
            return 'KELAS '.$m[1];
        }
        if (preg_match('/\bvvip\b/iu', $normalized)) {
            return 'VVIP';
        }
        if (preg_match('/\bvip\b/iu', $normalized)) {
            return 'VIP';
        }
        if (preg_match('/\butama\b/iu', $normalized)) {
            return 'UTAMA';
        }
        if (preg_match('/\bsuite\b/iu', $normalized)) {
            return 'SUITE ROOM';
        }
        if (preg_match('/\bumum\b/iu', $normalized)) {
            return 'UMUM';
        }

        return null;
    }
}
