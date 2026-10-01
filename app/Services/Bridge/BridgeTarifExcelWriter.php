<?php

namespace App\Services\Bridge;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Menulis Excel hasil Bridge: struktur + data original dipertahankan,
 * hanya SERVICECODE, SERVICECODE DESCRIPTION, SERVICECODE KELAS, dan
 * kolom RUANG BEDAH (SURGERY)/... yang diganti sesuai mapping.
 * SERVICECODE + DESCRIPTION diganti untuk row MATCHED + row
 * AMBIGUOUS/NOT_FOUND yang sarannya langsung dimasukkan
 * (suggested_applied). Kolom RUANG BEDAH diisi OK / NON OK untuk
 * semua row berdasarkan deskripsi. Kolom LoS dipertahankan; bila
 * value-nya memuat teks "days"/"day" maka teks tersebut dibuang
 * (mis. "3 days" -> "3"). Kolom PROVID / PROVIDER_NAME yang kosong
 * diisi provider default (config bridge); sel yang sudah terisi
 * tidak diubah.
 */
class BridgeTarifExcelWriter
{
    /**
     * @param  array<int, array{status: string, new_service_code: ?string, new_class_code: ?string, new_service_description?: ?string, surgery_flag?: ?string, suggested_applied?: bool}>  $decisions  excel_row => keputusan
     * @param  array<string, int>  $map  field => index kolom
     */
    public static function write(string $inputPath, string $outputPath, array $decisions, array $map): void
    {
        $spreadsheet = IOFactory::load($inputPath);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $codeCol = $map[BridgeTarifExcelReader::FIELD_SERVICE_CODE];
            $classCol = $map[BridgeTarifExcelReader::FIELD_SERVICE_CLASS_CODE];
            $descCol = $map[BridgeTarifExcelReader::FIELD_SERVICE_DESCRIPTION] ?? null;
            $surgeryCol = $map[BridgeTarifExcelReader::FIELD_SURGERY_FLAG] ?? null;
            $providerCodeCol = $map[BridgeTarifExcelReader::FIELD_PROVIDER_CODE] ?? null;
            $providerNameCol = $map[BridgeTarifExcelReader::FIELD_PROVIDER_NAME] ?? null;
            $defaultProviderCode = trim((string) config('bridge.provider_code'));
            $defaultProviderName = trim((string) config('bridge.provider_name'));

            foreach ($decisions as $excelRow => $decision) {
                // SERVICECODE + DESCRIPTION diganti untuk row MATCHED +
                // row AMBIGUOUS/NOT_FOUND yang sarannya langsung
                // dimasukkan; SERVICECODE KELAS diganti kapan pun kode
                // master-nya ketemu (termasuk row yang service-nya masih
                // AMBIGUOUS/NOT_FOUND).
                $replaceService = ($decision['status'] === TarifBridgeResolver::STATUS_MATCHED
                        || ! empty($decision['suggested_applied']))
                    && $decision['new_service_code'] !== null;
                if ($replaceService) {
                    $sheet->setCellValue([$codeCol + 1, $excelRow], $decision['new_service_code']);
                    $newDesc = trim((string) ($decision['new_service_description'] ?? ''));
                    if ($descCol !== null && $newDesc !== '') {
                        $sheet->setCellValue([$descCol + 1, $excelRow], $decision['new_service_description']);
                    }
                }
                if ($decision['new_class_code'] !== null) {
                    $sheet->setCellValue([$classCol + 1, $excelRow], $decision['new_class_code']);
                }

                if ($surgeryCol !== null && isset($decision['surgery_flag'])) {
                    $sheet->setCellValue([$surgeryCol + 1, $excelRow], $decision['surgery_flag']);
                }
                // PROVID / PROVIDER_NAME kosong -> isi default.
                if ($providerCodeCol !== null && $defaultProviderCode !== ''
                    && trim((string) $sheet->getCell([$providerCodeCol + 1, $excelRow])->getValue()) === ''
                ) {
                    $sheet->setCellValue([$providerCodeCol + 1, $excelRow], $defaultProviderCode);
                }
                if ($providerNameCol !== null && $defaultProviderName !== ''
                    && trim((string) $sheet->getCell([$providerNameCol + 1, $excelRow])->getValue()) === ''
                ) {
                    $sheet->setCellValue([$providerNameCol + 1, $excelRow], $defaultProviderName);
                }
            }

            self::cleanLosColumns($sheet);

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($outputPath);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

 
    protected static function cleanLosColumns($sheet): void
    {
        $highest = $sheet->getHighestColumn();
        $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highest);
        $highestRow = $sheet->getHighestRow();
        $losCols = [];
        for ($col = 1; $col <= $maxCol; $col++) {
            if (BridgeTarifSurgeryFlag::isLosHeader((string) $sheet->getCell([$col, 1])->getValue())) {
                $losCols[] = $col;
            }
        }
        foreach ($losCols as $col) {
            for ($row = 2; $row <= $highestRow; $row++) {
                $cell = $sheet->getCell([$col, $row]);
                $value = $cell->getValue();
                if ($value === null || $value === '') {
                    continue;
                }
                $cleaned = BridgeTarifSurgeryFlag::cleanLosValue($value);
                if ($cleaned !== trim((string) $value)) {
                    $sheet->setCellValue([$col, $row], $cleaned);
                }
            }
        }
    }
}
