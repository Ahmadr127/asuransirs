<?php

namespace App\Services\Bridge;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Menulis Excel hasil Bridge: struktur + data original dipertahankan,
 * hanya SERVICECODE dan SERVICECODE KELAS yang diganti sesuai mapping.
 * SERVICECODE diganti untuk row MATCHED + row AMBIGUOUS yang sarannya
 * langsung dimasukkan (suggested_applied). Kolom PROVID / PROVIDER_NAME
 * yang kosong diisi provider default (config bridge); sel yang sudah
 * terisi tidak diubah.
 */
class BridgeTarifExcelWriter
{
    /**
     * @param  array<int, array{status: string, new_service_code: ?string, new_class_code: ?string, suggested_applied?: bool}>  $decisions  excel_row => keputusan
     * @param  array<string, int>  $map  field => index kolom
     */
    public static function write(string $inputPath, string $outputPath, array $decisions, array $map): void
    {
        $spreadsheet = IOFactory::load($inputPath);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $codeCol = $map[BridgeTarifExcelReader::FIELD_SERVICE_CODE];
            $classCol = $map[BridgeTarifExcelReader::FIELD_SERVICE_CLASS_CODE];
            $providerCodeCol = $map[BridgeTarifExcelReader::FIELD_PROVIDER_CODE] ?? null;
            $providerNameCol = $map[BridgeTarifExcelReader::FIELD_PROVIDER_NAME] ?? null;
            $defaultProviderCode = trim((string) config('bridge.provider_code'));
            $defaultProviderName = trim((string) config('bridge.provider_name'));

            foreach ($decisions as $excelRow => $decision) {
                // SERVICECODE diganti untuk row MATCHED + row AMBIGUOUS
                // yang sarannya langsung dimasukkan; SERVICECODE KELAS
                // diganti kapan pun kode master-nya ketemu (termasuk row
                // yang service-nya masih AMBIGUOUS/NOT_FOUND).
                if (($decision['status'] === TarifBridgeResolver::STATUS_MATCHED
                        || ! empty($decision['suggested_applied']))
                    && $decision['new_service_code'] !== null
                ) {
                    $sheet->setCellValue([$codeCol + 1, $excelRow], $decision['new_service_code']);
                }
                if ($decision['new_class_code'] !== null) {
                    $sheet->setCellValue([$classCol + 1, $excelRow], $decision['new_class_code']);
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

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($outputPath);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
