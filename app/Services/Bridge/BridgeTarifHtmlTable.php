<?php

namespace App\Services\Bridge;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Dukungan file legacy: tabel HTML yang disimpan berekstensi .xls
 * (umum dari hasil export sistem lama, mis. teraMedik).
 *
 * PhpSpreadsheet menolak file seperti ini (proteksi XXE/XEE), jadi
 * tabel diparsing manual via DOM dan dikonversi ke .xlsx asli agar
 * alur scan -> resolve -> generate tetap bekerja tanpa perubahan.
 */
class BridgeTarifHtmlTable
{
    /**
     * Deteksi konten HTML via sniffing awal file (bukan via ekstensi,
     * karena file legacy justru berekstensi .xls).
     */
    public static function isHtml(string $absPath): bool
    {
        $head = @file_get_contents($absPath, false, null, 0, 4096);
        if (! is_string($head) || $head === '') {
            return false;
        }
        $head = mb_strtolower(ltrim($head));

        return str_starts_with($head, '<!doctype html')
            || str_starts_with($head, '<html')
            || str_contains($head, '<table');
    }

    /**
     * Ambil header + baris data dari tabel terbaik di dokumen HTML.
     * Bentuk return sama dengan BridgeTarifExcelReader::read().
     *
     * @return array{headers: array<int, mixed>, rows: array<int, array<int, mixed>>}
     *
     * @throws \RuntimeException bila tidak ada tabel data yang dikenali
     */
    public static function extractRows(string $absPath): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // Tanpa LIBXML_NOENT/DTDLOAD: entity tidak di-expand (aman dari XXE).
        $ok = $dom->loadHTMLFile($absPath);
        libxml_clear_errors();
        if (! $ok) {
            throw new \RuntimeException('File HTML tidak dapat dibaca.');
        }

        /** @var array{int, array<int, mixed>, array<int, array<int, mixed>>}|null $best [skor, headers, rows] */
        $best = null;
        foreach ($dom->getElementsByTagName('table') as $table) {
            if (! $table instanceof \DOMElement) {
                continue;
            }
            $grid = self::tableGrid($table);
            if (count($grid) < 2) {
                continue;
            }
            // Header tidak selalu baris 1 (ada file berjudul di atas tabel):
            // cari baris dengan kecocokan header terbanyak di 5 baris pertama.
            foreach (array_slice($grid, 0, 5) as $i => $row) {
                $score = count(BridgeTarifExcelReader::mapHeaderRow($row));
                if ($best === null || $score > $best[0]) {
                    $best = [$score, array_values($row), array_slice($grid, $i + 1)];
                }
                if ($score >= 4) {
                    break;
                }
            }
            if ($best !== null && $best[0] >= 4) {
                break;
            }
        }

        if ($best === null || $best[0] === 0) {
            throw new \RuntimeException('Tabel data tidak ditemukan di file HTML.');
        }

        $rows = array_values(array_filter(
            $best[2],
            fn ($r) => collect($r)->filter(fn ($c) => trim((string) $c) !== '')->isNotEmpty()
        ));

        return ['headers' => $best[1], 'rows' => $rows];
    }

    /**
     * Konversi file HTML legacy menjadi .xlsx asli.
     * Header selalu ditulis di baris 1 agar penomoran baris Excel
     * konsisten antara scan dan generate.
     */
    public static function convertToXlsx(string $absSource, string $absDest): void
    {
        $extracted = self::extractRows($absSource);

        $spreadsheet = new Spreadsheet();
        try {
            $spreadsheet->getActiveSheet()->fromArray(
                array_merge([$extracted['headers']], $extracted['rows']),
                null,
                'A1',
                true
            );
            (new Xlsx($spreadsheet))->save($absDest);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected static function tableGrid(\DOMElement $table): array
    {
        $grid = [];
        /** @var \DOMElement $tr */
        foreach ($table->getElementsByTagName('tr') as $tr) {
            // Abaikan baris milik nested table di dalam sel.
            $owner = $tr->parentNode;
            while ($owner instanceof \DOMElement && strtolower($owner->tagName) !== 'table') {
                $owner = $owner->parentNode;
            }
            if ($owner !== $table) {
                continue;
            }

            $cells = [];
            foreach ($tr->childNodes as $c) {
                if ($c instanceof \DOMElement && in_array(strtolower($c->tagName), ['td', 'th'], true)) {
                    $cells[] = trim((string) preg_replace('/\s+/', ' ', $c->textContent));
                }
            }
            $grid[] = $cells;
        }

        return $grid;
    }
}
