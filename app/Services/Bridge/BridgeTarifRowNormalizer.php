<?php

namespace App\Services\Bridge;

/**
 * Normalisasi satu baris Excel Bridge. Nilai original dipertahankan
 * apa adanya; key ternormalisasi hanya untuk keperluan matching.
 */
class BridgeTarifRowNormalizer
{
    /**
     * trim + uppercase + rapikan whitespace.
     * " CT Scan   Head " -> "CT SCAN HEAD".
     */
    public static function normalizeKey(?string $value): string
    {
        $value = trim((string) $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return mb_strtoupper($value);
    }

    /**
     * Parse nilai tarif Excel ke float. Mendukung angka mentah,
     * format "Rp 1.250.000", "1,250,000.00", "1250000,50", maupun
     * ribuan Indonesia "31.949" (titik tunggal + tepat 3 digit).
     * null bila kosong / tidak bisa diparsing / <= 0.
     */
    public static function parseTariff(mixed $value): ?float
    {
        $num = self::parseNumber($value, true);

        return ($num !== null && $num > 0) ? $num : null;
    }

    /**
     * Parse quantity: konsisten dengan parser tarif (mendukung desimal
     * koma/titik seperti "2.5"), tetapi null/kosong/0/negatif = invalid
     * (tidak boleh dipakai sebagai pembagi). Tanpa heuristik ribuan
     * Indonesia agar desimal quantity ("1.5") tidak rusak.
     */
    public static function parseQuantity(mixed $value): ?float
    {
        $num = self::parseNumber($value, false);

        return ($num !== null && $num > 0 && is_finite($num)) ? $num : null;
    }

    /**
     * Inti parsing angka. $thousandsHeuristic=true: titik tunggal yang
     * diikuti tepat 3 digit ("31.949", "63.898") dibaca sebagai pemisah
     * ribuan Indonesia; false: titik tunggal selalu desimal ("1.5").
     */
    protected static function parseNumber(mixed $value, bool $thousandsHeuristic): ?float
    {
        if (is_int($value) || is_float($value)) {
            $num = (float) $value;

            return is_finite($num) ? $num : null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        // Buang simbol non-angka penting: Rp, spasi, dll.
        $clean = (string) preg_replace('/[^0-9,.\-]/', '', $text);
        if ($clean === '' || $clean === '-' || $clean === '.' || $clean === ',') {
            return null;
        }
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            // Kedua pemisah ada: yang paling kanan = desimal.
            // "1,250,000.00" -> desimal titik; "1.250.000,50" -> desimal koma.
            if (strrpos($clean, '.') > strrpos($clean, ',')) {
                $clean = str_replace(',', '', $clean);
            } else {
                $clean = str_replace('.', '', $clean);
                $clean = str_replace(',', '.', $clean);
            }
        } elseif (str_contains($clean, ',')) {
            // Hanya koma: format Indonesia (titik ribuan tak ada).
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            // Tanpa koma: titik ganda berarti ribuan ("1.250.000").
            if (substr_count($clean, '.') > 1) {
                $clean = str_replace('.', '', $clean);
            } elseif ($thousandsHeuristic && preg_match('/^\d{1,3}(\.\d{3})+$/', $clean)) {
                // Titik tunggal + tepat 3 digit = ribuan Indonesia.
                $clean = str_replace('.', '', $clean);
            }
        }
        if (! is_numeric($clean)) {
            return null;
        }
        $num = (float) $clean;

        return is_finite($num) ? $num : null;
    }

    /**
     * @param  array<int, mixed>  $row  baris mentah (array numerik)
     * @param  array<string, int>  $map  field => index kolom
     * @param  int  $excelRow  nomor baris di Excel (1-indexed)
     */
    public static function normalize(array $row, array $map, int $excelRow): array
    {
        $values = array_values($row);
        $cell = fn (string $field): string => trim((string) ($values[$map[$field]] ?? ''));

        $serviceCode = $cell(BridgeTarifExcelReader::FIELD_SERVICE_CODE);
        $description = $cell(BridgeTarifExcelReader::FIELD_SERVICE_DESCRIPTION);
        $classCode = $cell(BridgeTarifExcelReader::FIELD_SERVICE_CLASS_CODE);
        $className = $cell(BridgeTarifExcelReader::FIELD_CLASS_NAME);
        $tariffRaw = isset($map[BridgeTarifExcelReader::FIELD_TARIFF])
            ? (string) ($values[$map[BridgeTarifExcelReader::FIELD_TARIFF]] ?? '')
            : '';
        $totalBilledRaw = isset($map[BridgeTarifExcelReader::FIELD_TOTAL_BILLED])
            ? (string) ($values[$map[BridgeTarifExcelReader::FIELD_TOTAL_BILLED]] ?? '')
            : '';
        $quantityRaw = isset($map[BridgeTarifExcelReader::FIELD_QUANTITY])
            ? (string) ($values[$map[BridgeTarifExcelReader::FIELD_QUANTITY]] ?? '')
            : '';

        $tariff = self::parseTariff($tariffRaw);
        $totalBilled = self::parseTariff($totalBilledRaw);
        $quantity = self::parseQuantity($quantityRaw);
        // Effective tariff per row (prioritas 1-2). Prioritas 3 (fallback
        // grup se-mapping_key) diisi BridgeTarifProcessor pada pass kedua.
        // Nilai asli TARIFF/total/quantity tetap dipertahankan apa adanya.
        $effective = BridgeTarifEffectiveTariff::fromParts($tariff, $totalBilled, $quantity);

        $descriptionKey = self::normalizeKey($description);
        $classKey = self::normalizeKey($className);

        return [
            'excel_row' => $excelRow,
            'service_code' => $serviceCode,
            'service_description' => $description,
            'service_class_code' => $classCode,
            'class_name' => $className,
            'tariff_raw' => trim($tariffRaw),
            'tariff' => $tariff,
            'total_billed_raw' => trim($totalBilledRaw),
            'total_billed' => $totalBilled,
            'quantity_raw' => trim($quantityRaw),
            'quantity' => $quantity,
            'effective_tariff' => $effective['effective_tariff'],
            'tariff_source' => $effective['tariff_source'],
            'description_key' => $descriptionKey,
            'class_key' => $classKey,
            'mapping_key' => $descriptionKey.'|'.$classKey,
        ];
    }
}
