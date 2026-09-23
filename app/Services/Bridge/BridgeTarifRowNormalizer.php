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

        $descriptionKey = self::normalizeKey($description);
        $classKey = self::normalizeKey($className);

        return [
            'excel_row' => $excelRow,
            'service_code' => $serviceCode,
            'service_description' => $description,
            'service_class_code' => $classCode,
            'class_name' => $className,
            'description_key' => $descriptionKey,
            'class_key' => $classKey,
            'mapping_key' => $descriptionKey.'|'.$classKey,
        ];
    }
}
