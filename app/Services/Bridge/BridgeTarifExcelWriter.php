<?php

namespace App\Services\Bridge;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Menulis Excel hasil Bridge: struktur + data original dipertahankan,
 * hanya SERVICECODE dan SERVICECODE KELAS yang diganti sesuai mapping.
 */
class BridgeTarifExcelWriter
{
    /**
     * @param  array<int, array{status: string, new_service_code: ?string, new_class_code: ?string}>  $decisions  excel_row => keputusan
     * @param  array<string, int>  $map  field => index kolom
     */
    public static function write(string $inputPath, string $outputPath, array $decisions, array $map): void
    {
        $spreadsheet = IOFactory::load($inputPath);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $codeCol = $map[BridgeTarifExcelReader::FIELD_SERVICE_CODE];
            $classCol = $map[BridgeTarifExcelReader::FIELD_SERVICE_CLASS_CODE];

            foreach ($decisions as $excelRow => $decision) {
                // SERVICECODE hanya diganti untuk row MATCHED; SERVICECODE
                // KELAS diganti kapan pun kode master-nya ketemu (termasuk
                // row yang service-nya masih AMBIGUOUS/NOT_FOUND).
                if ($decision['status'] === TarifBridgeResolver::STATUS_MATCHED
                    && $decision['new_service_code'] !== null
                ) {
                    $sheet->setCellValue([$codeCol + 1, $excelRow], $decision['new_service_code']);
                }
                if ($decision['new_class_code'] !== null) {
                    $sheet->setCellValue([$classCol + 1, $excelRow], $decision['new_class_code']);
                }
            }

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($outputPath);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
