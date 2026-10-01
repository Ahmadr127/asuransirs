<?php

namespace App\Services\Bridge\NotFound;

use App\Services\Bridge\BridgeServiceSearch;

/**
 * Satu file kondisi peran default untuk saran NOT_FOUND.
 *
 * - Bila keyword pencarian memuat keluarga anestesi ("anasthesy" sudah
 *   ternormalisasi menjadi "anestesi" via alias klinis; varian lain
 *   dibaca dari config bridge.search.recall_excluded_role) → prefer
 *   kandidat yang berujung "Dokter Anestesi".
 * - Bila tidak → prefer kandidat yang berujung "Dokter Operator".
 *
 * Alasan: baris tanpa sinyal anestesi (mis. dokter Sp.OT, tanpa kata
 * anasthesy/narkose/sedasi) adalah tindakan operator; tanpa default
 * ini pasangan anestesi bisa menang hanya karena tarifnya kebetulan
 * lebih dekat (kasus Row 40: Laparotomy Explorasi Gastrostomy).
 *
 * Dipakai NotFoundResolver sebagai peran efektif bila description
 * tidak menyebut peran eksplisit (roleHint null). Tidak menyentuh
 * logika kamar (jangkar sibling) dan tidak menambah daftar kata baru
 * selain yang sudah ada di config.
 */
final class AnesthesiaRoleRule
{
    public const ROLE_ANESTHESIA = 'anestesi';

    public const ROLE_OPERATOR = 'operator';

    /**
     * Peran yang dipreferensikan untuk satu keyword pencarian efektif
     * ($searchDesc, yaitu hasil rewrite/coreAction — bukan mentah).
     */
    public static function preferredRole(string $searchDesc): string
    {
        foreach (BridgeServiceSearch::contentWords($searchDesc) as $token) {
            if (in_array($token, self::anesthesiaFamily(), true)) {
                return self::ROLE_ANESTHESIA;
            }
        }

        return self::ROLE_OPERATOR;
    }

    /** @return array<int, string> */
    protected static function anesthesiaFamily(): array
    {
        $configured = config('bridge.search.recall_excluded_role');
        $family = is_array($configured) && $configured !== []
            ? array_values($configured)
            : BridgeServiceSearch::RECALL_EXCLUDED_ROLE;
        if (! in_array(self::ROLE_ANESTHESIA, $family, true)) {
            $family[] = self::ROLE_ANESTHESIA;
        }

        return $family;
    }
}
