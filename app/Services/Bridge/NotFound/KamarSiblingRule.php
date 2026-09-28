<?php

namespace App\Services\Bridge\NotFound;

/**
 * Aturan sibling KAMAR untuk saran NOT_FOUND.
 *
 * Bila baris Excel adalah tarif kamar operasi (role "kamar"), jawabannya
 * adalah pasangan "Kamar Operasi & Sarana" milik prosedur (service) yang
 * paling cocok teksnya — bukan pair operator/anestesi walau tarifnya
 * lebih dekat. Contoh: Excel "BEDAH UROLOGI - Varicocelectomy - Kamar
 * Operasi" bertarif Rp 4.800.000 (= tarif komponen operator); dari
 * keluarga OKURO-O/A/K yang naik adalah OKURO-K.
 *
 * Murni fungsi statis (tanpa DB, tanpa ranking) agar murah dan mudah
 * ditest. Dipakai NotFoundResolver::suggest() setelah usort, sebelum
 * diverseSlice — cap diversitas tetap berlaku di atasnya.
 */
final class KamarSiblingRule
{
    /**
     * Pindahkan pasangan kamar milik service teratas ke depan dengan
     * urutan relatif dipertahankan. Tanpa pair kamar ber-kelas pada
     * service teratas: kembalikan apa adanya (no-op). Ketikan user (q)
     * selalu dihormati — tidak dipromosi bila user sedang menyaring
     * manual.
     *
     * @param  array<int, array<string, mixed>>  $ranked  sudah terurut
     * @param  array<int, string>  $queryTokens  token ketikan user (q)
     * @return array<int, array<string, mixed>>
     */
    public static function promote(array $ranked, ?string $roleHint, array $queryTokens): array
    {
        if ($roleHint !== 'kamar' || $queryTokens !== [] || count($ranked) < 2) {
            return $ranked;
        }
        $topCode = mb_strtoupper(trim((string) ($ranked[0]['service_code'] ?? '')));
        if ($topCode === '') {
            return $ranked;
        }
        $kamarFirst = [];
        $rest = [];
        foreach ($ranked as $row) {
            if (mb_strtoupper(trim((string) ($row['service_code'] ?? ''))) === $topCode
                && ($row['class_code'] ?? null) !== null
                && NotFoundResolver::detectRole((string) ($row['service_description'] ?? '')) === 'kamar'
            ) {
                $kamarFirst[] = $row;
            } else {
                $rest[] = $row;
            }
        }

        return $kamarFirst !== [] ? array_merge($kamarFirst, $rest) : $ranked;
    }
}
