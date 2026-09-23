<?php

namespace App\Services\TarifImport;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Filter baca per slice agar satu request HTTP hanya memuat sebagian baris
 * ke memory (pola resmi PhpSpreadsheet/ChunkReader, dipakai juga oleh
 * Maatwebsite WithChunkReading). $startRow/$endRow adalah nomor baris
 * Excel 1-indexed (baris 1 = header).
 */
class TarifSliceReadFilter implements IReadFilter
{
    public function __construct(protected int $startRow, protected int $endRow) {}

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return $row >= $this->startRow && $row <= $this->endRow;
    }
}
