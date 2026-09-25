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
     * format "Rp 1.250.000", "1,250,000.00", maupun "1250000,50".
     * null bila kosong / tidak bisa diparsing / <= 0.
     */
    public static function parseTariff(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $num = (float) $value;

            return $num > 0 ? $num : null;
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
            // Tanpa koma: koma ribuan AS ("1,250,000" sudah tertangani di
            // atas); titik ganda berarti ribuan ("1.250.000").
            if (substr_count($clean, '.') > 1) {
                $clean = str_replace('.', '', $clean);
            }
        }
        if (! is_numeric($clean)) {
            return null;
        }
        $num = (float) $clean;

        return $num > 0 ? $num : null;
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

        $descriptionKey = self::normalizeKey($description);
        $classKey = self::normalizeKey($className);

        return [
            'excel_row' => $excelRow,
            'service_code' => $serviceCode,
            'service_description' => $description,
            'service_class_code' => $classCode,
            'class_name' => $className,
            'tariff_raw' => trim($tariffRaw),
            'tariff' => self::parseTariff($tariffRaw),
            'description_key' => $descriptionKey,
            'class_key' => $classKey,
            'mapping_key' => $descriptionKey.'|'.$classKey,
        ];
    }
}
