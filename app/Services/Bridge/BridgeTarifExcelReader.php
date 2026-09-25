<?php

namespace App\Services\Bridge;

/**
 * Membaca file Excel Bridge: header + mapping kolom + baris mentah.
 * Murni IO — tanpa query database, tanpa business rule mapping.
 */
class BridgeTarifExcelReader
{
    public const FIELD_SERVICE_CODE = 'SERVICECODE';

    public const FIELD_SERVICE_DESCRIPTION = 'SERVICEDESCRIPTION';

    public const FIELD_SERVICE_CLASS_CODE = 'SERVICECLASSCODE';

    public const FIELD_CLASS_NAME = 'CLASSNAME';

    public const FIELD_PROVIDER_CODE = 'PROVIDERCODE';

    public const FIELD_PROVIDER_NAME = 'PROVIDERNAME';

    public const FIELD_TARIFF = 'TARIFF';

    public const FIELD_TOTAL_BILLED = 'TOTAL_BILLED';

    public const FIELD_QUANTITY = 'QUANTITY';

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

            $hasProvider = str_contains($norm, 'provid');
            $hasProviderName = $hasProvider && (str_contains($norm, 'name') || str_contains($norm, 'nama'));

            $hasServiceCode = str_contains($norm, 'servicecode');
            $hasDesc = str_contains($norm, 'desc');
            $hasKelas = str_contains($norm, 'kelas') || str_contains($norm, 'class') || str_contains($norm, 'kode');
            // Kolom tarif opsional (dipakai penimbang AMBIGUOUS/NOT_FOUND):
            // TARIFF / TARIF / HARGA / PRICE. Kolom "jenis tarif" dan
            // "tariff description" tidak ikut (itu bukan nilai tarif).
            $hasTariff = (str_contains($norm, 'tarif') || str_contains($norm, 'tariff') || str_contains($norm, 'harga') || str_contains($norm, 'price'))
                && ! str_contains($norm, 'jenis')
                && ! $hasDesc;
            // Kolom pendukung effective tariff (keduanya opsional — file
            // lama tanpa kolom ini tetap bisa diproses seperti semula).
            $hasTotalBilled = str_contains($norm, 'total')
                && (str_contains($norm, 'bill') || str_contains($norm, 'tagih'));
            $hasQuantity = str_contains($norm, 'qty') || str_contains($norm, 'quantity');

            if ($hasTariff && ! isset($map[self::FIELD_TARIFF])) {
                $map[self::FIELD_TARIFF] = $index;
            } elseif ($hasTotalBilled && ! isset($map[self::FIELD_TOTAL_BILLED])) {
                $map[self::FIELD_TOTAL_BILLED] = $index;
            } elseif ($hasQuantity && ! isset($map[self::FIELD_QUANTITY])) {
                $map[self::FIELD_QUANTITY] = $index;
            } elseif ($hasProvider && $hasProviderName && ! isset($map[self::FIELD_PROVIDER_NAME])) {
                $map[self::FIELD_PROVIDER_NAME] = $index;
            } elseif ($hasProvider && ! isset($map[self::FIELD_PROVIDER_CODE])) {
                $map[self::FIELD_PROVIDER_CODE] = $index;
            } elseif ($hasServiceCode && $hasKelas && ! isset($map[self::FIELD_SERVICE_CLASS_CODE])) {
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
        // File legacy: tabel HTML berekstensi .xls (hasil export sistem lama).
        if (BridgeTarifHtmlTable::isHtml($path)) {
            $extracted = BridgeTarifHtmlTable::extractRows($path);

            return [
                'headers' => $extracted['headers'],
                'map' => self::mapHeaderRow($extracted['headers']),
                'rows' => $extracted['rows'],
            ];
        }

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
