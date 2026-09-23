<?php

namespace App\Services\Bridge;

/**
 * Membaca file Excel Bridge: header + mapping kolom + baris mentah.
 * Murni IO — tanpa query database, tanpa business rule mapping.
 */
class BridgeTarifExcelReader
{
    public const FIELD_SERVICE_CODE = 'service_code';

    public const FIELD_SERVICE_DESCRIPTION = 'service_description';

    public const FIELD_SERVICE_CLASS_CODE = 'service_class_code';

    public const FIELD_CLASS_NAME = 'class_name';

    /**
     * Normalisasi nama header: trim, lowercase, buang non-alfanumerik.
     * "SERVICECODE DESCRIPTION" / "ServiceCode_Description" -> "servicecodedescription".
     */
    public static function normalizeHeader(?string $header): string
    {
        $header = mb_strtolower(trim((string) $header));

        return (string) preg_replace('/[^a-z0-9]+/', '', $header);
    }

    /**
     * Petakan baris header (array numerik sel) ke [field => index_kolom].
     * Urutan cek penting: KELAS-berisi-servicecode dulu, lalu description,
     * lalu servicecode polos, lalu kelas polos.
     *
     * @param  array<int, mixed>  $headerRow
     * @return array<string, int>
     */
    public static function mapHeaderRow(array $headerRow): array
    {
        $map = [];
        foreach (array_values($headerRow) as $index => $cell) {
            $norm = self::normalizeHeader((string) $cell);
            if ($norm === '') {
                continue;
            }

            $hasServiceCode = str_contains($norm, 'servicecode');
            $hasDesc = str_contains($norm, 'desc');
            $hasKelas = str_contains($norm, 'kelas') || str_contains($norm, 'class') || str_contains($norm, 'kode');

            if ($hasServiceCode && $hasKelas && ! isset($map[self::FIELD_SERVICE_CLASS_CODE])) {
                $map[self::FIELD_SERVICE_CLASS_CODE] = $index;
            } elseif ($hasServiceCode && $hasDesc && ! isset($map[self::FIELD_SERVICE_DESCRIPTION])) {
                $map[self::FIELD_SERVICE_DESCRIPTION] = $index;
            } elseif ($hasServiceCode && ! $hasDesc && ! $hasKelas && ! isset($map[self::FIELD_SERVICE_CODE])) {
                $map[self::FIELD_SERVICE_CODE] = $index;
            } elseif (! $hasServiceCode && $hasKelas && ! isset($map[self::FIELD_CLASS_NAME])) {
                $map[self::FIELD_CLASS_NAME] = $index;
            }
        }

        return $map;
    }

    /**
     * Kolom mapping wajib: SERVICECODE, DESCRIPTION, KELAS-code, KELAS.
     *
     * @return array<int, string> daftar field yang hilang
     */
    public static function missingFields(array $map): array
    {
        return array_values(array_diff(
            [self::FIELD_SERVICE_CODE, self::FIELD_SERVICE_DESCRIPTION, self::FIELD_SERVICE_CLASS_CODE, self::FIELD_CLASS_NAME],
            array_keys($map)
        ));
    }

    /**
     * Baca file menjadi header + baris mentah (array numerik per baris).
     * Baris 1 = header. Baris sepenuhnya kosong dibuang.
     *
     * @return array{headers: array<int, mixed>, map: array<string, int>, rows: array<int, array<int, mixed>>}
     */
    public static function read(string $path): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        try {
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $rows = array_values(array_map(fn ($r) => array_values((array) $r), $rows));
        if ($rows === []) {
            return ['headers' => [], 'map' => [], 'rows' => []];
        }

        $headers = array_shift($rows);
        $map = self::mapHeaderRow($headers);
        $rows = array_values(array_filter(
            $rows,
            fn ($r) => collect($r)->filter(fn ($c) => trim((string) $c) !== '')->isNotEmpty()
        ));

        return ['headers' => array_values($headers), 'map' => $map, 'rows' => $rows];
    }
}
