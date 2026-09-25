<?php

namespace App\Services\Bridge;

/**
 * Konsep effective tariff: nilai tarif yang dipakai SISTEM untuk analisis
 * dan pembandingan (ranking AMBIGUOUS, referensi NOT_FOUND).
 *
 * Nilai asli TARIFF Excel TIDAK PERNAH diubah — effective tariff hanya
 * cerminan in-memory per row. Prioritas sumber (konsisten untuk
 * AMBIGUOUS maupun NOT_FOUND):
 *  1. TARIFF row (excel)
 *  2. TOTAL BILLED / QUANTITY row yang sama (total_billed_quantity)
 *  3. tarif valid row lain dengan mapping_key sama (group)
 *  4. null (tidak tersedia)
 */
final class BridgeTarifEffectiveTariff
{
    public const SOURCE_EXCEL = 'excel';

    public const SOURCE_COMPUTED = 'total_billed_quantity';

    public const SOURCE_GROUP = 'group';

    /**
     * Tentukan effective tariff satu row dari nilai yang sudah diparsing.
     *
     * @return array{effective_tariff: ?float, tariff_source: ?string}
     */
    public static function fromParts(?float $tariff, ?float $totalBilled, ?float $quantity): array
    {
        if ($tariff !== null && $tariff > 0) {
            return ['effective_tariff' => $tariff, 'tariff_source' => self::SOURCE_EXCEL];
        }

        $computed = self::divide($totalBilled, $quantity);
        if ($computed !== null) {
            return ['effective_tariff' => $computed, 'tariff_source' => self::SOURCE_COMPUTED];
        }

        return ['effective_tariff' => null, 'tariff_source' => null];
    }

    /**
     * TOTAL BILLED / QUANTITY yang aman: null bila input tak valid,
     * quantity <= 0 (anti divide-by-zero), hasil non-finite/non-positif.
     * Tanpa pembulatan agresif — nilai mentah untuk ranking.
     */
    public static function divide(?float $totalBilled, ?float $quantity): ?float
    {
        if ($totalBilled === null || $totalBilled <= 0) {
            return null;
        }
        if ($quantity === null || $quantity <= 0 || ! is_finite($quantity)) {
            return null;
        }
        $result = $totalBilled / $quantity;
        if (! is_finite($result) || $result <= 0) {
            return null;
        }

        return $result;
    }

    /**
     * Median deterministik untuk fallback grup (stabil terhadap outlier).
     * null bila daftar kosong.
     *
     * @param  array<int, float>  $values
     */
    public static function median(array $values): ?float
    {
        $values = array_values(array_filter(
            $values,
            fn ($v) => is_numeric($v) && is_finite((float) $v) && (float) $v > 0
        ));
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);

        return (float) $values[(int) floor(($n - 1) / 2)];
    }

    /** Label Indonesia untuk sumber tarif (dipakai UI). */
    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            self::SOURCE_EXCEL => 'TARIFF Excel',
            self::SOURCE_COMPUTED => 'TOTAL BILLED ÷ QUANTITY',
            self::SOURCE_GROUP => 'Row lain (grup sama)',
            default => 'tidak tersedia',
        };
    }
}
