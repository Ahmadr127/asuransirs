<?php

namespace Tests\Unit;

use App\Services\TarifImport\TarifDateParser;
use App\Services\TarifImport\TarifImportColumnMapper as Mapper;
use App\Services\TarifImport\TarifRowMapper;
use PHPUnit\Framework\TestCase;

class TarifImportMappingTest extends TestCase
{
    public function test_normalize_header_handles_variants(): void
    {
        $this->assertSame('servicecode', Mapper::normalizeHeader(' SERVICECODE '));
        $this->assertSame('servicecode', Mapper::normalizeHeader('ServiceCode'));
        $this->assertSame('servicecode', Mapper::normalizeHeader('SERVICECODE'));
        $this->assertSame('servicecode description', Mapper::normalizeHeader('SERVICECODE DESCRIPTION'));
        $this->assertSame('servicecode description', Mapper::normalizeHeader('SERVICECODE_DESCRIPTION'));
    }

    public function test_field_for_maps_all_eleven_columns(): void
    {
        $headers = [
            'PROVID',
            'PROVIDER_NAME',
            'SERVICECODE',
            'SERVICECODE DESCRIPTION',
            'SERVICECODE_KELAS',
            'KELAS',
            'RUANG BEDAH (SURGERY)/NON RUANG BEDAH (NON SURGERY)',
            'HELPER',
            'TARIFF',
            'VALID DATE FROM (month, day, year)',
            'END DATE TO (month, day, year)',
        ];

        $map = Mapper::mapHeaderRow($headers);

        $this->assertSame([
            0 => Mapper::FIELD_PROVIDER_CODE,
            1 => Mapper::FIELD_PROVIDER_NAME,
            2 => Mapper::FIELD_SERVICE_CODE,
            3 => Mapper::FIELD_SERVICE_DESCRIPTION,
            4 => Mapper::FIELD_SERVICE_CLASS_CODE,
            5 => Mapper::FIELD_CLASS_NAME,
            6 => Mapper::FIELD_SURGERY_TYPE,
            7 => Mapper::FIELD_HELPER,
            8 => Mapper::FIELD_TARIFF,
            9 => Mapper::FIELD_VALID_FROM,
            10 => Mapper::FIELD_VALID_TO,
        ], $map);
    }

    public function test_validate_headers_reports_missing_required(): void
    {
        $result = Mapper::validateHeaders(['PROVID', 'HELPER']);

        $this->assertFalse($result['valid']);
        $this->assertContains(Mapper::FIELD_SERVICE_CODE, $result['missing']);
        $this->assertContains(Mapper::FIELD_TARIFF, $result['missing']);
    }

    public function test_parse_date_handles_serial_and_strings(): void
    {
        // Excel serial untuk 2024-01-15.
        $this->assertSame('2024-01-15', TarifDateParser::parseDate(45306));
        $this->assertSame('2024-01-15', TarifDateParser::parseDate('2024-01-15'));
        $this->assertSame('2024-01-15', TarifDateParser::parseDate('15/01/2024'));
        $this->assertSame('2024-01-15', TarifDateParser::parseDate('January 15, 2024'));
        $this->assertNull(TarifDateParser::parseDate('bukan tanggal'));
        $this->assertNull(TarifDateParser::parseDate(''));
    }

    public function test_parse_tariff_handles_rupiah_format(): void
    {
        $this->assertSame(1500000.0, TarifDateParser::parseTariff('Rp 1.500.000,00'));
        $this->assertSame(1500000.0, TarifDateParser::parseTariff('1500000.00'));
        $this->assertSame(1500000.0, TarifDateParser::parseTariff(1500000));
        $this->assertNull(TarifDateParser::parseTariff('abc'));
        $this->assertNull(TarifDateParser::parseTariff(-5));
    }

    public function test_normalize_surgery(): void
    {
        $this->assertSame('SURGERY', TarifDateParser::normalizeSurgery('SURGERY'));
        $this->assertSame('SURGERY', TarifDateParser::normalizeSurgery('Ruang Bedah'));
        $this->assertSame('NON SURGERY', TarifDateParser::normalizeSurgery('NON SURGERY'));
        $this->assertSame('NON SURGERY', TarifDateParser::normalizeSurgery('Non Ruang Bedah'));
        $this->assertNull(TarifDateParser::normalizeSurgery(''));
    }

    public function test_row_normalize_flags_errors_per_field(): void
    {
        $mapped = [
            'provider_code' => '',
            'service_code' => 'SVC001',
            'surgery_type' => 'SURGERY',
            'tariff' => 'bukan angka',
            'valid_date_from' => '2024-01-01',
            'end_date_to' => '2023-12-31',
        ];

        $result = TarifRowMapper::normalize($mapped);

        $this->assertArrayHasKey('provider_code', $result['errors']);
        $this->assertArrayHasKey('tariff', $result['errors']);
        $this->assertArrayHasKey('end_date_to', $result['errors']);
        $this->assertSame('SURGERY', $result['data']['surgery_type']);
    }
}
