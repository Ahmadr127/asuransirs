<?php

namespace App\Services\TarifImport;

/**
 * Pemetaan header Excel -> field domain, terpusat di satu tempat.
 *
 * Excel (11 kolom):
 *   PROVID | PROVIDER_NAME | SERVICECODE | SERVICECODE DESCRIPTION
 *   | SERVICECODE_KELAS | KELAS | RUANG BEDAH (SURGERY)/NON RUANG BEDAH (NON SURGERY)
 *   | HELPER | TARIFF | VALID DATE FROM | END DATE TO
 *
 * Domain:
 *   provider_code | provider_name | service_code | service_description
 *   | service_class_code | class_name | surgery_type | helper
 *   | tariff | valid_date_from | end_date_to
 */
class TarifImportColumnMapper
{
    public const FIELD_PROVIDER_CODE = 'provider_code';

    public const FIELD_PROVIDER_NAME = 'provider_name';

    public const FIELD_SERVICE_CODE = 'service_code';

    public const FIELD_SERVICE_DESCRIPTION = 'service_description';

    public const FIELD_SERVICE_CLASS_CODE = 'service_class_code';

    public const FIELD_CLASS_NAME = 'class_name';

    public const FIELD_SURGERY_TYPE = 'surgery_type';

    public const FIELD_HELPER = 'helper';

    public const FIELD_TARIFF = 'tariff';

    public const FIELD_VALID_FROM = 'valid_date_from';

    public const FIELD_VALID_TO = 'end_date_to';

    /**
     * Field yang wajib ada di header agar file bisa diproses.
     */
    public const REQUIRED_FIELDS = [
        self::FIELD_PROVIDER_CODE,
        self::FIELD_SERVICE_CODE,
        self::FIELD_TARIFF,
        self::FIELD_VALID_FROM,
        self::FIELD_VALID_TO,
    ];

    /**
     * Urutan kolom canonical untuk template export.
     */
    public const CANONICAL_HEADERS = [
        'PROVID',
        'PROVIDER_NAME',
        'SERVICECODE',
        'SERVICECODE DESCRIPTION',
        'SERVICECODE_KELAS',
        'KELAS',
        'RUANG BEDAH (SURGERY)/NON RUANG BEDAH (NON SURGERY)',
        'HELPER',
        'TARIFF',
        'VALID DATE FROM',
        'END DATE TO',
    ];

    /**
     * Normalisasi satu nama header: trim, lowercase, underscore->spasi,
     * buang karakter non-alfanumerik, rapikan spasi ganda.
     * Sehingga " SERVICECODE ", "ServiceCode", "SERVICECODE" -> "servicecode".
     */
    public static function normalizeHeader(?string $header): string
    {
        $header = (string) $header;
        $header = trim($header);
        $header = mb_strtolower($header);
        $header = str_replace('_', ' ', $header);
        // Buang "(...)" beserta isinya? TIDAK — isi kurung mengandung kata
        // kunci (surgery/non surgery). Ganti tanda baca jadi spasi.
        $header = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $header);
        $header = (string) preg_replace('/\s+/', ' ', $header);

        return trim($header);
    }

    /**
     * Petakan satu header yang sudah dinormalisasi ke field domain.
     * Return null bila header tidak dikenal.
     */
    public static function fieldFor(string $normalized): ?string
    {
        // Exact match cepat.
        $exact = [
            'provid' => self::FIELD_PROVIDER_CODE,
            'provider code' => self::FIELD_PROVIDER_CODE,
            'providername' => self::FIELD_PROVIDER_NAME,
            'provider name' => self::FIELD_PROVIDER_NAME,
            'servicecode' => self::FIELD_SERVICE_CODE,
            'service code' => self::FIELD_SERVICE_CODE,
            'servicecode description' => self::FIELD_SERVICE_DESCRIPTION,
            'service description' => self::FIELD_SERVICE_DESCRIPTION,
            'servicedescription' => self::FIELD_SERVICE_DESCRIPTION,
            'servicecode kelas' => self::FIELD_SERVICE_CLASS_CODE,
            'servicecodekelas' => self::FIELD_SERVICE_CLASS_CODE,
            'kode kelas' => self::FIELD_SERVICE_CLASS_CODE,
            'kelas' => self::FIELD_CLASS_NAME,
            'class' => self::FIELD_CLASS_NAME,
            'classname' => self::FIELD_CLASS_NAME,
            'class name' => self::FIELD_CLASS_NAME,
            'helper' => self::FIELD_HELPER,
            'tariff' => self::FIELD_TARIFF,
            'tarif' => self::FIELD_TARIFF,
        ];

        if (isset($exact[$normalized])) {
            return $exact[$normalized];
        }

        // Fuzzy contains untuk header panjang yang bervariasi.
        $has = fn (string $needle): bool => str_contains($normalized, $needle);

        // Kolom surgery: "ruang bedah surgery non ruang bedah non surgery".
        if (($has('bedah') || $has('surgery') || $has('surg')) && ($has('ruang') || $has('surgery') || $has('non'))) {
            return self::FIELD_SURGERY_TYPE;
        }
        if ($normalized === 'surgery' || $normalized === 'non surgery' || $normalized === 'nonsurgery') {
            return self::FIELD_SURGERY_TYPE;
        }

        // Kolom tanggal: harus mengandung kata tanggal/date + penanda from/to.
        if ($has('valid') || $has('berlaku') || $has('date') || $has('tanggal')) {
            $isFrom = $has('from') || $has('dari') || $has('mulai') || $has('start');
            $isTo = $has('to') || $has('sampai') || $has('hingga') || $has('akhir') || $has('end') || $has('until');
            if ($isFrom && ! $isTo) {
                return self::FIELD_VALID_FROM;
            }
            if ($isTo && ! $isFrom) {
                return self::FIELD_VALID_TO;
            }

            // Ambigu (mis. "valid date"), biarkan null agar dilaporkan unknown.
            return null;
        }

        // Fallback tanggal format "month day year" tanpa label? Tidak bisa
        // dibedakan from/to — tolak sebagai unknown.
        return null;
    }

    /**
     * Petakan satu baris header mentah (array nilai sel) menjadi
     * [index_kolom => field_domain].
     *
     * @param  array<int, mixed>  $headerRow
     * @return array<int, string>
     */
    public static function mapHeaderRow(array $headerRow): array
    {
        $map = [];
        foreach (array_values($headerRow) as $index => $cell) {
            $field = self::fieldFor(self::normalizeHeader((string) $cell));
            if ($field !== null && ! in_array($field, $map, true)) {
                $map[$index] = $field;
            }
        }

        return $map;
    }

    /**
     * Validasi struktur header. Return ['map', 'missing', 'unknown', 'valid'].
     *
     * @param  array<int, mixed>  $headerRow
     */
    public static function validateHeaders(array $headerRow): array
    {
        $raw = array_values($headerRow);
        $map = self::mapHeaderRow($raw);

        $unknown = [];
        foreach ($raw as $index => $cell) {
            if ((string) $cell !== '' && ! isset($map[$index])) {
                $unknown[] = (string) $cell;
            }
        }

        $missing = array_values(array_diff(self::REQUIRED_FIELDS, array_values($map)));

        return [
            'map' => $map,
            'missing' => $missing,
            'unknown' => $unknown,
            'valid' => $missing === [],
        ];
    }

    /**
     * Ubah satu baris data mentah (array numerik sel) menjadi array
     * asosiatif field_domain => nilai mentah, memakai $map dari validateHeaders().
     *
     * @param  array<int, mixed>  $row
     * @param  array<int, string>  $map
     */
    public static function applyMap(array $row, array $map): array
    {
        $values = array_values($row);
        $out = [];
        foreach ($map as $index => $field) {
            $out[$field] = $values[$index] ?? null;
        }

        return $out;
    }
}
