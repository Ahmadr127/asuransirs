<?php

namespace App\Services\Bridge;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

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
     * Pilihan manual user untuk satu mapping key ambigu; berlaku ke
     * seluruh row dengan key sama.
     */
    public function resolve(string $token, string $mappingKey, string $serviceCode, string $classCode): array
    {
        $session = $this->session($token);
        $result = $this->scanFile(Storage::path($session['path']), $session['resolutions']);

        if (! isset($result['groups'][$mappingKey])) {
            throw new \RuntimeException('Grup mapping tidak ditemukan atau sudah terselesaikan.');
        }

        $choice = ['service_code' => $serviceCode, 'class_code' => $classCode];
        $valid = false;
        foreach ($result['groups'][$mappingKey]['candidates'] as $candidate) {
            if (mb_strtoupper(trim($candidate['service_code'])) === mb_strtoupper(trim($serviceCode))
                && mb_strtoupper(trim($candidate['class_code'])) === mb_strtoupper(trim($classCode))
            ) {
                $valid = true;
                break;
            }
        }
        if (! $valid) {
            throw new \RuntimeException('Kandidat yang dipilih tidak valid untuk mapping ini.');
        }

        $session['resolutions'][$mappingKey] = $choice;
        Cache::put($this->cacheKey($token), $session, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return $this->scanFile(Storage::path($session['path']), $session['resolutions']);
    }

    /**
     * Generate Excel hasil: hitung ulang mapping + terapkan resolusi,
     * tulis file baru. Row unresolved tetap memakai kode original.
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
            }
        }

        $outputFilename = pathinfo($session['filename'], PATHINFO_FILENAME).'-bridge.xlsx';
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

        return pathinfo((string) ($session['filename'] ?? 'bridge'), PATHINFO_FILENAME).'-bridge.xlsx';
    }
}
