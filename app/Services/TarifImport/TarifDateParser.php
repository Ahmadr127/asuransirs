<?php

namespace App\Services\TarifImport;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Satu-satunya tempat parsing tanggal & angka tarif untuk import.
 * Jangan parsing manual tersebar di class lain.
 */
class TarifDateParser
{
    /**
     * Parse nilai tanggal Excel (serial number / string "month, day, year" /
     * d/m/Y / Y-m-d / DateTime) menjadi 'Y-m-d'. Return null bila tak valid.
     */
    public static function parseDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d');
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            // Serial Excel yang wajar: 1900-01-01 (1) s.d. 2100-12-31 (~73050).
            // Nilai kecil seperti "5" hampir pasti bukan tanggal.
            if ($serial < 1000 || $serial > 80000) {
                return null;
            }
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject($serial))->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $text = trim((string) $value);
        if ($text === '' || $text === '-' || $text === '0') {
            return null;
        }

        // Bersihkan jam bila ada ("2024-01-15 00:00:00" / "15/01/2024 12:00 AM").
        $formats = [
            'Y-m-d', 'Y/m/d', 'Y.m.d',
            'd-m-Y', 'd/m/Y', 'd.m.Y',
            'm-d-Y', 'm/d/Y', 'm.d.Y',
            'd M Y', 'd M y', 'j M Y',
            'M d Y', 'M d, Y', 'F d, Y', 'F j, Y',
            'd F Y', 'j F Y', 'd M, Y',
            'Y-m-d H:i:s', 'd/m/Y H:i:s', 'm/d/Y H:i:s',
            'd-m-Y H:i', 'd/m/Y H:i',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $text);
                if ($parsed !== false && $parsed->format($format) === $text) {
                    return $parsed->format('Y-m-d');
                }
            } catch (\Throwable) {
                continue;
            }
        }

        // Fallback longgar untuk varian tak terduga ("Jan 5 2024", dsb).
        try {
            $parsed = Carbon::parse($text);
            // Tolak hasil absurd (strtotime('abc') bisa lolos di versi lama).
            if ($parsed->year < 1900 || $parsed->year > 2100) {
                return null;
            }

            return $parsed->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse nilai tarif ("Rp 1.500.000,00" / "1500000.00" / 1500000)
     * menjadi float. Return null bila bukan numeric / negatif.
     */
    public static function parseTariff(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $num = (float) $value;

            return $num >= 0 ? $num : null;
        }

        $text = trim((string) $value);
        if ($text === '' || $text === '-') {
            return null;
        }

        // Buang "Rp", spasi, dan karakter non angka/koma/titik/minus.
        $text = (string) preg_replace('/[^0-9,.\-]/', '', $text);
        if ($text === '' || $text === '-') {
            return null;
        }

        $hasComma = str_contains($text, ',');
        $hasDot = str_contains($text, '.');

        if ($hasComma && $hasDot) {
            // Format Indonesia: 1.500.000,00 -> titik ribuan, koma desimal.
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        } elseif ($hasComma) {
            // "1500000,00" atau "1,500,000" -> koma jadi desimal bila
            // hanya 1 koma dengan <=2 digit di belakang; selain itu ribuan.
            $parts = explode(',', $text);
            if (count($parts) === 2 && strlen(end($parts)) <= 2) {
                $text = $parts[0].'.'.end($parts);
            } else {
                $text = str_replace(',', '', $text);
            }
        }

        if (! is_numeric($text)) {
            return null;
        }

        $num = (float) $text;

        return $num >= 0 ? $num : null;
    }

    /**
     * Normalisasi nilai surgery Excel menjadi 'SURGERY' / 'NON SURGERY'.
     * Return null bila tak dikenali.
     */
    public static function normalizeSurgery(mixed $value): ?string
    {
        $text = mb_strtolower(trim((string) $value));
        if ($text === '' || $text === '-') {
            return null;
        }

        if (str_contains($text, 'non')) {
            return 'NON SURGERY';
        }

        if (str_contains($text, 'surg') || str_contains($text, 'bedah') || $text === 'ya' || $text === 'y') {
            return 'SURGERY';
        }

        return null;
    }
}
