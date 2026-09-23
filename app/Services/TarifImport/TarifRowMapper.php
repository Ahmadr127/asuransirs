<?php

namespace App\Services\TarifImport;

use App\Models\Tarif;

/**
 * Mengubah satu baris Excel (sudah dipetakan ke field domain) menjadi
 * struktur domain + error/warning per field. Murni, tanpa query DB —
 * resolve master dilakukan oleh TarifMasterResolver agar bisa di-preload.
 */
class TarifRowMapper
{
    public const STATUS_VALID = 'VALID';

    public const STATUS_WARNING = 'WARNING';

    public const STATUS_ERROR = 'ERROR';

    public const STATUS_DUPLICATE = 'DUPLICATE';

    /**
     * @param  array<string, mixed>  $mapped  field_domain => nilai mentah
     * @return array{data: array<string, mixed>, errors: array<string, string>, warnings: array<string, string>}
     */
    public static function normalize(array $mapped): array
    {
        $get = fn (string $field): mixed => $mapped[$field] ?? null;
        $text = fn (mixed $v): string => trim((string) ($v ?? ''));

        $errors = [];
        $warnings = [];

        $providerCode = mb_strtoupper($text($get(TarifImportColumnMapper::FIELD_PROVIDER_CODE)));
        $providerName = $text($get(TarifImportColumnMapper::FIELD_PROVIDER_NAME));
        $serviceCode = mb_strtoupper($text($get(TarifImportColumnMapper::FIELD_SERVICE_CODE)));
        $serviceDescription = $text($get(TarifImportColumnMapper::FIELD_SERVICE_DESCRIPTION));
        $classCode = mb_strtoupper($text($get(TarifImportColumnMapper::FIELD_SERVICE_CLASS_CODE)));
        $className = $text($get(TarifImportColumnMapper::FIELD_CLASS_NAME));
        $helperRaw = $text($get(TarifImportColumnMapper::FIELD_HELPER));
        // "-" / kosong dianggap NULL (kolom opsional).
        $helper = ($helperRaw === '' || $helperRaw === '-') ? '' : $helperRaw;
        // Normalisasi "-" menjadi string kosong agar data => null.
        if ($providerCode === '-') {
            $providerCode = '';
        }
        if ($serviceCode === '-') {
            $serviceCode = '';
        }
        if ($classCode === '-') {
            $classCode = '';
        }
        if ($className === '-') {
            $className = '';
        }
        if ($providerName === '-') {
            $providerName = '';
        }
        if ($serviceDescription === '-') {
            $serviceDescription = '';
        }

        if ($providerCode === '') {
            $errors[TarifImportColumnMapper::FIELD_PROVIDER_CODE] = 'PROVID kosong.';
        }

        if ($serviceCode === '') {
            $errors[TarifImportColumnMapper::FIELD_SERVICE_CODE] = 'SERVICECODE kosong.';
        }

        if (mb_strlen($providerName) > 255) {
            $errors[TarifImportColumnMapper::FIELD_PROVIDER_NAME] = 'PROVIDER_NAME melebihi 255 karakter.';
        }

        // SERVICECODE_KELAS boleh kosong bila KELAS terisi? Tidak — kode kelas
        // adalah business key ke master classes. Wajib ada salah satunya dan
        // diutamakan kode.
        if ($classCode === '') {
            if ($className === '') {
                $errors[TarifImportColumnMapper::FIELD_SERVICE_CLASS_CODE] = 'SERVICECODE_KELAS / KELAS kosong.';
            } else {
                // Hanya nama tanpa kode: lookup by name dilakukan resolver;
                // tandai warning karena berisiko ambigu.
                $warnings[TarifImportColumnMapper::FIELD_SERVICE_CLASS_CODE] = 'SERVICECODE_KELAS kosong, lookup memakai nama KELAS.';
            }
        }

        // RUANG BEDAH / HELPER nullable: kosong / "-" => null tanpa error.
        // Hanya error bila terisi tapi tidak dikenali.
        $surgeryRaw = trim((string) ($get(TarifImportColumnMapper::FIELD_SURGERY_TYPE) ?? ''));
        if ($surgeryRaw === '' || $surgeryRaw === '-') {
            $surgery = null;
        } else {
            $surgery = TarifDateParser::normalizeSurgery($surgeryRaw);
            if ($surgery === null) {
                $errors[TarifImportColumnMapper::FIELD_SURGERY_TYPE] = 'Kolom bedah tidak dikenali (gunakan SURGERY / NON SURGERY).';
            } elseif (! in_array($surgery, Tarif::SURGERY_TYPES, true)) {
                $errors[TarifImportColumnMapper::FIELD_SURGERY_TYPE] = 'Surgery type tidak valid.';
            }
        }

        if ($helper !== '' && mb_strlen($helper) > 255) {
            $errors[TarifImportColumnMapper::FIELD_HELPER] = 'HELPER melebihi 255 karakter.';
        }

        $tariff = TarifDateParser::parseTariff($get(TarifImportColumnMapper::FIELD_TARIFF));
        if ($tariff === null) {
            $errors[TarifImportColumnMapper::FIELD_TARIFF] = 'TARIFF bukan numeric yang valid.';
        }

        $validFrom = TarifDateParser::parseDate($get(TarifImportColumnMapper::FIELD_VALID_FROM));
        if ($validFrom === null) {
            $errors[TarifImportColumnMapper::FIELD_VALID_FROM] = 'VALID DATE FROM tidak valid.';
        }

        $validTo = TarifDateParser::parseDate($get(TarifImportColumnMapper::FIELD_VALID_TO));
        if ($validTo === null) {
            $errors[TarifImportColumnMapper::FIELD_VALID_TO] = 'END DATE TO tidak valid.';
        }

        if ($validFrom !== null && $validTo !== null && $validTo < $validFrom) {
            $errors[TarifImportColumnMapper::FIELD_VALID_TO] = 'END DATE TO harus sama/lebih dari VALID DATE FROM.';
        }

        return [
            'data' => [
                TarifImportColumnMapper::FIELD_PROVIDER_CODE => $providerCode !== '' ? $providerCode : null,
                TarifImportColumnMapper::FIELD_PROVIDER_NAME => $providerName !== '' ? $providerName : null,
                TarifImportColumnMapper::FIELD_SERVICE_CODE => $serviceCode !== '' ? $serviceCode : null,
                TarifImportColumnMapper::FIELD_SERVICE_DESCRIPTION => $serviceDescription !== '' ? $serviceDescription : null,
                TarifImportColumnMapper::FIELD_SERVICE_CLASS_CODE => $classCode !== '' ? $classCode : null,
                TarifImportColumnMapper::FIELD_CLASS_NAME => $className !== '' ? $className : null,
                TarifImportColumnMapper::FIELD_SURGERY_TYPE => $surgery,
                TarifImportColumnMapper::FIELD_HELPER => $helper !== '' ? $helper : null,
                TarifImportColumnMapper::FIELD_TARIFF => $tariff,
                TarifImportColumnMapper::FIELD_VALID_FROM => $validFrom,
                TarifImportColumnMapper::FIELD_VALID_TO => $validTo,
            ],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
