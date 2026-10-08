<?php

namespace App\Services\Bridge;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Services\TarifImport\TarifDateParser;
use App\Services\TarifImport\TarifImportColumnMapper;

/**
 * Orchestration Bridge Tarif: upload -> read -> normalize -> resolve
 * -> preview -> generate. Tanpa pilihan Jenis Tarif — mapping murni
 * terhadap master Tarif existing, tanpa membuat master baru.
 */
class BridgeTarifService
{
    public const CACHE_TTL_MINUTES = 120;

    protected TarifBridgeRepository $repository;

    public function __construct(
        protected BridgeTarifProcessor $processor,
    ) {
        // Satu instance repository untuk seluruh rantai (preload sekali).
        $this->repository = $processor->repository();
    }

    protected function cacheKey(string $token): string
    {
        return 'bridge_'.$token;
    }

    /**
     * Scan murni dari file tersimpan (tanpa token): dipakai scan awal
     * maupun dihitung ulang saat resolve/generate.
     *
     * @return array{headers: array, map: array<string, int>, summary: array, groups: array, preview: array, decisions: array}
     *
     * @throws \RuntimeException bila file kosong / header tak lengkap
     */
    public function scanFile(string $path, array $resolutions = []): array
    {
        $read = BridgeTarifExcelReader::read($path);
        if ($read['headers'] === []) {
            throw new \RuntimeException('File Excel kosong.');
        }
        $missing = BridgeTarifExcelReader::missingFields($read['map']);
        if ($missing !== []) {
            throw new \RuntimeException('Header tidak lengkap. Kolom tidak ditemukan: '.implode(', ', $missing));
        }

        $this->repository->preload();

        return array_merge(
            ['headers' => $read['headers'], 'map' => $read['map']],
            $this->processor->process($read['rows'], $read['map'], $resolutions)
        );
    }

    /**
     * Upload + scan + simpan sesi kecil (path + resolusi) di cache.
     *
     * @return array{token: string, filename: string, summary: array, groups: array, preview: array}
     */
    public function scanUpload(UploadedFile $file): array
    {
        $storedPath = $file->store('bridge-inputs');
        $storedPath = $this->normalizeStored($storedPath);
        $filename = $file->getClientOriginalName();

        try {
            $result = $this->scanFile(Storage::path($storedPath));
        } catch (\Throwable $e) {
            Storage::delete($storedPath);

            throw $e;
        }

        $token = bin2hex(random_bytes(16));
        Cache::put($this->cacheKey($token), [
            'path' => $storedPath,
            'filename' => $filename,
            'resolutions' => [],
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        unset($result['decisions'], $result['headers'], $result['map']);

        return array_merge($result, ['token' => $token, 'filename' => $filename]);
    }

    /**
     * File legacy berupa tabel HTML berekstensi .xls tidak bisa dibaca
     * ulang oleh writer saat generate — konversi sekali ke .xlsx asli
     * agar seluruh alur (scan/resolve/generate) bekerja di atasnya.
     */
    protected function normalizeStored(string $storedPath): string
    {
        $abs = Storage::path($storedPath);
        if (! BridgeTarifHtmlTable::isHtml($abs)) {
            return $storedPath;
        }

        $rel = (string) preg_replace('/\.[A-Za-z0-9]+$/', '', $storedPath).'.xlsx';
        BridgeTarifHtmlTable::convertToXlsx($abs, Storage::path($rel));
        Storage::delete($storedPath);

        return $rel;
    }

    /** @return array{path: string, filename: string, resolutions: array} */
    protected function session(string $token): array
    {
        $session = Cache::get($this->cacheKey($token));
        if (! is_array($session) || ! Storage::exists($session['path'] ?? '')) {
            throw new \RuntimeException('Sesi bridge kedaluwarsa atau file tidak ditemukan. Ulangi upload.');
        }

        return $session;
    }

    /**
     * Pilihan manual user untuk satu mapping key; berlaku ke
     * seluruh row dengan key sama.
     */
    public function resolve(string $token, string $mappingKey, string $serviceCode, string $classCode): array
    {
        $session = $this->session($token);
        $result = $this->scanFile(Storage::path($session['path']), $session['resolutions']);

        $session['resolutions'][$mappingKey] = $this->validateChoice(
            $result['groups'] ?? [], $mappingKey, $serviceCode, $classCode
        );
        Cache::put($this->cacheKey($token), $session, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return $this->scanFile(Storage::path($session['path']), $session['resolutions']);
    }

    /**
     * Terapkan banyak pilihan sekaligus (dari modal). Entri kosong
     * dilewati; entri tidak valid menggagalkan semuanya agar tidak
     * ada mapping setengah jalan. @return jumlah mapping diterapkan
     */
    public function resolveMany(string $token, array $candidates): int
    {
        $session = $this->session($token);
        $result = $this->scanFile(Storage::path($session['path']), $session['resolutions']);

        $choices = [];
        foreach ($candidates as $mappingKey => $candidate) {
            $parts = explode('|', (string) $candidate);
            if (count($parts) !== 2 || trim($parts[0]) === '') {
                continue;
            }
            $choices[(string) $mappingKey] = $this->validateChoice(
                $result['groups'] ?? [], (string) $mappingKey, trim($parts[0]), trim($parts[1] ?? '')
            );
        }
        if ($choices === []) {
            throw new \RuntimeException('Tidak ada mapping valid yang dipilih.');
        }

        foreach ($choices as $mappingKey => $choice) {
            $session['resolutions'][$mappingKey] = $choice;
        }
        Cache::put($this->cacheKey($token), $session, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return count($choices);
    }

    /**
     * Hasil terkini untuk satu sesi (dipakai halaman hasil GET agar
     * refresh aman — pola PRG).
     *
     * @return array{token: string, filename: string, summary: array, groups: array, preview: array}
     */
    public function resultFor(string $token): array
    {
        $session = $this->session($token);
        $result = $this->scanFile(Storage::path($session['path']), $session['resolutions']);
        unset($result['decisions'], $result['headers'], $result['map']);

        return array_merge($result, ['token' => $token, 'filename' => $session['filename']]);
    }

    /** @return array{service_code: string, class_code: string} */
    protected function validateChoice(array $groups, string $mappingKey, string $serviceCode, string $classCode): array
    {
        if (! isset($groups[$mappingKey])) {
            throw new \RuntimeException('Grup mapping tidak ditemukan atau sudah terselesaikan.');
        }

        if ($groups[$mappingKey]['manual'] ?? false) {
            // Grup NOT_FOUND: cukup pilih service; kelas ikut bawaan Excel
            // kecuali diisi override (harus pair yang valid di master).
            if (! $this->repository->serviceExists($serviceCode)) {
                throw new \RuntimeException('Service tidak ada di master.');
            }
            if (trim($classCode) !== '' && ! $this->repository->pairExists($serviceCode, $classCode)) {
                throw new \RuntimeException('Pasangan service + kelas tidak ada di master Tarif.');
            }

            return [
                'service_code' => mb_strtoupper(trim($serviceCode)),
                'class_code' => mb_strtoupper(trim($classCode)),
            ];
        }

        foreach ($groups[$mappingKey]['candidates'] as $candidate) {
            if (mb_strtoupper(trim($candidate['service_code'])) === mb_strtoupper(trim($serviceCode))
                && mb_strtoupper(trim($candidate['class_code'])) === mb_strtoupper(trim($classCode))
            ) {
                return ['service_code' => trim($serviceCode), 'class_code' => trim($classCode)];
            }
        }

        throw new \RuntimeException('Kandidat yang dipilih tidak valid untuk mapping ini.');
    }

    /**
     * Generate Excel hasil: hitung ulang mapping + terapkan resolusi,
     * tulis file baru. SERVICECODE + DESCRIPTION berubah untuk row
     * MATCHED + row AMBIGUOUS/NOT_FOUND yang sarannya langsung
     * dimasukkan (konsensus kode saran); SERVICECODE KELAS berubah
     * kapan pun kode master-nya ketemu.
     *
     * @return array{output_filename: string, download_token: string, total: int, changed: int, unresolved: int}
     */
    public function generate(string $token): array
    {
        $session = $this->session($token);
        $result = $this->scanFile(Storage::path($session['path']), $session['resolutions']);

        $changed = 0;
        foreach ($result['decisions'] as $decision) {
            if ($decision['status'] === TarifBridgeResolver::STATUS_MATCHED) {
                $changed++;
            } elseif ($decision['new_class_code'] !== null) {
                $changed++;
            }
        }

        $outputFilename = $this->buildOutputFilename(Storage::path($session['path']), $session['filename']);
        $session['output_filename'] = $outputFilename;
        Cache::put($this->cacheKey($token), $session, now()->addMinutes(self::CACHE_TTL_MINUTES));
        $outputPath = 'bridge-outputs/'.$token.'.xlsx';
        Storage::makeDirectory('bridge-outputs');
        Storage::delete($outputPath);
        BridgeTarifExcelWriter::write(
            Storage::path($session['path']),
            Storage::path($outputPath),
            $result['decisions'],
            $result['map']
        );

        return [
            'output_filename' => $outputFilename,
            'download_token' => $token,
            'total' => $result['summary']['total'],
            'changed' => $changed,
            'unresolved' => $result['summary']['unresolved'],
        ];
    }

    public function outputPath(string $token): ?string
    {
        $path = 'bridge-outputs/'.$token.'.xlsx';

        return Storage::exists($path) ? Storage::path($path) : null;
    }

    public function outputFilename(string $token): string
    {
        $session = Cache::get($this->cacheKey($token));

        if (is_array($session) && ! empty($session['output_filename'])) {
            return (string) $session['output_filename'];
        }

        return pathinfo((string) ($session['filename'] ?? 'bridge'), PATHINFO_FILENAME).'-bridge.xlsx';
    }

    /**
     * Nama file hasil bridge: PROVID_TGLLAHIR_TGLMASUKPERAWATAN_NAMAPASIEN
     * diambil dari baris data pertama (kolom PROVID, CLIENTS DOB,
     * SERVICE_DATE_FROM, CLIENT NAME). Tanggal dinormalisasi ke ddmmyyyy
     * (mendukung serial Excel maupun "dd mm yyyy"). Fallback ke pola
     * lama "{nama-asli}-bridge.xlsx" bila komponen tidak ditemukan.
     */
    protected function buildOutputFilename(string $path, string $originalFilename): string
    {
        $fallback = pathinfo($originalFilename, PATHINFO_FILENAME).'-bridge.xlsx';

        try {
            $read = BridgeTarifExcelReader::read($path);
        } catch (\Throwable) {
            return $fallback;
        }

        if ($read['headers'] === [] || $read['rows'] === []) {
            return $fallback;
        }

        $indexes = $this->locateIdentityColumns($read['headers']);
        \Illuminate\Support\Facades\Log::info('Bridge output filename lookup', [
            'headers' => array_values($read['headers']),
            'indexes' => $indexes,
        ]);
        if ($indexes === null) {
            return $fallback;
        }

        foreach ($read['rows'] as $row) {
            $values = array_values(is_array($row) ? $row : []);
            $provid = trim((string) ($values[$indexes['provid']] ?? ''));
            $dob = trim((string) ($values[$indexes['dob']] ?? ''));
            $admission = trim((string) ($values[$indexes['admission']] ?? ''));
            $name = trim((string) ($values[$indexes['name']] ?? ''));

            if ($provid === '' && $dob === '' && $admission === '' && $name === '') {
                continue;
            }

            $parts = array_filter([
                self::sanitizeFilenamePart($provid),
                $this->filenameDate($dob),
                $this->filenameDate($admission),
                self::sanitizeFilenamePart($name),
            ], fn ($part) => $part !== '');

            if ($parts !== []) {
                return implode('_', $parts).'.xlsx';
            }
        }

        return $fallback;
    }

    /**
     * Cari indeks kolom identitas pasien dari baris header mentah.
     * Pencocokan longgar berbasis kata kunci agar varian penulisan
     * header ("TGLLAHIR", "CLIENTS NAME", "TGL MASUK", ...) tetap
     * dikenali. Return null bila salah satu dari 4 kolom tidak ditemukan.
     *
     * @param  array<int, mixed>  $headers
     * @return array{provid: int, dob: int, admission: int, name: int}|null
     */
    protected function locateIdentityColumns(array $headers): ?array
    {
        $found = ['provid' => null, 'dob' => null, 'admission' => null, 'name' => null];
        $claimed = [];

        foreach (array_values($headers) as $index => $cell) {
            $normalized = TarifImportColumnMapper::normalizeHeader((string) $cell);
            if ($normalized === '' || in_array($index, $claimed, true)) {
                continue;
            }

            if ($found['provid'] === null && str_contains($normalized, 'provid')
                && ! str_contains($normalized, 'name') && ! str_contains($normalized, 'nama')) {
                $found['provid'] = $index;
                $claimed[] = $index;
            } elseif ($found['dob'] === null && (
                str_contains($normalized, 'dob')
                || str_contains($normalized, 'tgllahir')
                || str_contains($normalized, 'tgl lahir')
                || str_contains($normalized, 'tanggal lahir')
                || str_contains($normalized, 'birth')
                || str_contains($normalized, 'lahir')
            )) {
                $found['dob'] = $index;
                $claimed[] = $index;
            } elseif ($found['admission'] === null && (
                $normalized === 'service date from'
                || $normalized === 'service from'
                || str_contains($normalized, 'admission')
                || str_contains($normalized, 'tanggal masuk')
                || str_contains($normalized, 'masuk')
            )) {
                $found['admission'] = $index;
                $claimed[] = $index;
            } elseif ($found['name'] === null && (
                (str_contains($normalized, 'client') && str_contains($normalized, 'name'))
                || (str_contains($normalized, 'pasien') && str_contains($normalized, 'nama'))
                || $normalized === 'nama pasien'
                || $normalized === 'nama'
            )) {
                $found['name'] = $index;
                $claimed[] = $index;
            }
        }

        if (in_array(null, $found, true)) {
            return null;
        }

        return $found;
    }

    /**
     * Normalisasi tanggal sel ke format ddmmyyyy untuk nama file.
     * Return '' bila tak valid (komponen dilewati, bukan gagal).
     */
    protected function filenameDate(mixed $value): string
    {
        if (is_numeric($value)) {
            $parsed = TarifDateParser::parseDate($value);
        } else {
            $text = trim((string) $value);
            if ($text === '' || $text === '-') {
                return '';
            }
            $parsed = TarifDateParser::parseDate($text);
        }

        if ($parsed === null) {
            return self::sanitizeFilenamePart((string) $value);
        }

        $parts = explode('-', $parsed);

        return $parts[2].$parts[1].$parts[0];
    }

    protected static function sanitizeFilenamePart(string $value): string
    {
        $value = trim($value);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', '_', $value);
        $value = trim($value, '_');

        return mb_substr($value, 0, 60);
    }
}
