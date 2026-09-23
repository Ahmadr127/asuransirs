<?php

namespace App\Http\Services;

use App\Imports\TarifChunkImport;
use App\Models\JenisTarif;
use App\Models\Tarif;
use App\Services\TarifImport\TarifImportColumnMapper;
use App\Services\TarifImport\TarifMasterResolver;
use App\Services\TarifImport\TarifRowMapper;
use App\Services\TarifImport\TarifSliceReadFilter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Orchestrator generik import tarif. Satu service untuk SEMUA jenis tarif —
 * jenis tarif hanya menjadi context (jenis_tarif_id) yang dipilih user
 * saat upload, tidak pernah dibaca dari Excel.
 *
 * Tanggung jawab:
 * - baca Excel (sheet pertama, read-only)
 * - validasi header terpusat via TarifImportColumnMapper
 * - normalisasi row via TarifRowMapper
 * - resolve master via TarifMasterResolver (preload, tanpa N+1)
 * - deteksi duplicate dalam file + terhadap database
 * - scan (dry-run) dan commit (batch 1000 dalam transaction per batch)
 */
class TarifImportService
{
    public const CHUNK_SIZE = 2000;

    public const PREVIEW_LIMIT = 200;

    public const ERROR_LIMIT = 500;

    /**
     * Jumlah baris data per slice background scan (queue).
     * Benchmark 126K (PhpSpreadsheet reload per slice, I/O bound):
     *  1000 → 17.9s/chunk → 37.8 min total (melebihi timeout 1800)
     *  2000 → ~19s/chunk → ~21 min total (melebihi worker default 60s)
     *  5000 → ~25s/chunk → ~10.5 min total
     *  10000 → ~35s/chunk → ~7.5 min total (paling stabil untuk 120K-500K)
     * Dipilih 10000: meminimalkan reload file (13× vs 63×) sambil
     * menjaga peak memory <250MB per slice. COMMIT chunk 2000 menjaga
     * transaksi DB tetap bounded.
     */
    public const SCAN_SLICE = 10000;

    public function __construct(protected TarifMasterResolver $resolver) {}

    /**
     * Business key tarif (tidak ada unique constraint di DB, jadi didefinisikan
     * di level aplikasi dari FK + periode + tipe):
     *   jenis_tarif_id | provider_id | service_id | class_id
     *   | surgery_type | valid_date_from | end_date_to
     *
     * Catatan: nominal tariff TIDAK masuk key — baris dengan key sama tapi
     * nominal beda tetap dianggap DUPLICATE agar commit default SKIP dan
     * tidak menimpa/menggandakan data existing (perilaku paling aman).
     */
    public static function businessKey(array $persist): string
    {
        return implode('|', [
            $persist['jenis_tarif_id'] ?? '',
            $persist['provider_id'] ?? '',
            $persist['service_id'] ?? '',
            $persist['class_id'] ?? '',
            $persist['surgery_type'] ?? '',
            self::dateKey($persist['valid_date_from'] ?? ''),
            self::dateKey($persist['end_date_to'] ?? ''),
        ]);
    }

    /**
     * Samakan representasi tanggal dari DB (Carbon) dan Excel (string)
     * agar business key selalu sebanding.
     */
    protected static function dateKey(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d');
        }

        $text = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text, $m) === 1) {
            return $m[0];
        }

        try {
            return \Illuminate\Support\Carbon::parse($text)->format('Y-m-d');
        } catch (\Throwable) {
            return $text;
        }
    }

    /**
     * Baca sheet pertama file Excel menjadi array baris numerik.
     *
     * @return array<int, array<int, mixed>>
     */
    public function readSheetRows(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        try {
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        // Buang baris yang sepenuhnya kosong.
        return array_values(array_filter(
            array_map(fn ($r) => array_values((array) $r), $rows),
            fn ($r) => collect($r)->filter(fn ($c) => trim((string) $c) !== '')->isNotEmpty()
        ));
    }

    /**
     * SCAN (dry-run) satu request: validasi seluruh file tanpa menulis DB.
     * Cocok untuk file kecil (<= SCAN_SLICE). Untuk file besar gunakan
     * initScan() + readSlice() + processSlice() yang bertahap.
     */
    public function scan(UploadedFile|string $file, int $jenisTarifId, int $previewLimit = self::PREVIEW_LIMIT): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $jenis = JenisTarif::findOrFail($jenisTarifId);

        $rows = $this->readSheetRows($path);
        $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path);

        if ($rows === []) {
            return $this->emptyResult($filename, $jenis, 'File Excel kosong.');
        }

        $header = TarifImportColumnMapper::validateHeaders($rows[0]);
        if (! $header['valid']) {
            return array_merge(
                $this->emptyResult($filename, $jenis, 'Header tidak valid. Kolom wajib hilang: '.implode(', ', $header['missing'])),
                ['header' => $this->headerSummary($rows[0], $header)]
            );
        }

        $this->resolver->preload();
        $this->resolver->resetPending();
        $existingKeys = $this->loadExistingKeys($jenisTarifId);

        $summary = $this->freshSummary($filename, $jenis, $rows[0], $header);
        $seenKeys = [];
        $preview = [];
        $errors = [];
        $rowNumber = 1; // baris 1 = header

        // Proses per chunk agar pola memory sama dengan commit.
        foreach (array_chunk(array_slice($rows, 1), self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $rawRow) {
                $rowNumber++;
                $this->accumulate(
                    $this->processRow($rawRow, $header['map'], $rowNumber, $seenKeys, $existingKeys, $summary),
                    $preview,
                    $errors,
                    $previewLimit
                );
            }
        }

        $summary['total_rows'] = $rowNumber - 1;
        $summary['preview'] = $preview;
        $summary['errors'] = $errors;
        $summary['truncated_preview'] = $summary['total_rows'] > $previewLimit;
        $summary['truncated_errors'] = count($errors) >= self::ERROR_LIMIT;
        $this->attachCandidates($summary);

        return $summary;
    }

    /**
     * Tahap 1 scan bertahap: baca & validasi HANYA baris header (row 1)
     * plus hitung estimasi jumlah baris — murah (<2 detik) sehingga aman
     * untuk satu request HTTP.
     *
     * @return array{filename: string, jenis_tarif_id: int, jenis_tarif_name: string, header_map: array<int, string>, header: array, total_rows: int}|array{fatal: string, ...}
     */
    public function initScan(string $path, int $jenisTarifId, string $filename): array
    {
        $jenis = JenisTarif::findOrFail($jenisTarifId);

        $headerRow = $this->readSlice($path, 1, 1)[0] ?? null;
        if ($headerRow === null) {
            return $this->emptyResult($filename, $jenis, 'File Excel kosong.');
        }

        $header = TarifImportColumnMapper::validateHeaders($headerRow);
        if (! $header['valid']) {
            return array_merge(
                $this->emptyResult($filename, $jenis, 'Header tidak valid. Kolom wajib hilang: '.implode(', ', $header['missing'])),
                ['header' => $this->headerSummary($headerRow, $header)]
            );
        }

        $totalRows = max(0, $this->countSheetRows($path) - 1); // minus header

        return [
            'filename' => $filename,
            'jenis_tarif_id' => $jenis->id,
            'jenis_tarif_name' => $jenis->code.' — '.$jenis->name,
            'header_map' => $header['map'],
            'header' => $this->headerSummary($headerRow, $header),
            'total_rows' => $totalRows,
        ];
    }

    /**
     * Tahap 2 scan bertahap: proses satu slice baris data dan gabungkan ke
     * $state (akumulator antar request). $state['summary'] memakai format
     * yang sama dengan freshSummary() ditambah 'processed' (baris data yang
     * sudah diproses) dan $state['seen'] untuk deteksi duplikat antar slice.
     *
     * @param  array<int, mixed>  $rawRows  baris mentah (sudah termasuk baris kosong)
     * @param  array<int, string>  $headerMap
     * @param  array{summary: array, seen: array<string, int>, preview: array, errors: array}  $state
     */
    public function processSlice(array $rawRows, array $headerMap, int $firstRowNumber, array $existingKeys, array &$state, int $previewLimit = self::PREVIEW_LIMIT): int
    {
        // Suntik kembali kandidat unik dari slice sebelumnya agar dedup
        // lintas-slice tetap 1 kandidat untuk value yang sama.
        $this->resolver->preload();
        $this->resolver->importCandidates($state['candidates'] ?? []);
        $rowNumber = $firstRowNumber - 1;
        foreach ($rawRows as $rawRow) {
            if (! $this->isNonEmptyRow($rawRow)) {
                continue;
            }
            $rowNumber++;
            $this->accumulate(
                $this->processRow($rawRow, $headerMap, $rowNumber, $state['seen'], $existingKeys, $state['summary']),
                $state['preview'],
                $state['errors'],
                $previewLimit
            );
        }

        $state['candidates'] = $this->resolver->exportCandidates();
        $state['summary']['processed'] = $rowNumber - 1;
        $state['summary']['total_rows'] = $rowNumber - 1;
        $this->attachCandidates($state['summary'], $state['candidates']);

        return $rowNumber - 1;
    }

    /**
     * Tempelkan kandidat master UNIK ke summary untuk preview.
     * Hitungan berdasarkan UNIQUE VALUE (business key), bukan jumlah row.
     */
    protected function attachCandidates(array &$summary, ?array $candidates = null): void
    {
        $candidates ??= $this->resolver->exportCandidates();
        $lists = $this->resolver->candidateLists(100);
        $counts = $this->resolver->candidateCounts();
        // Bila kandidat dititipkan dari state (scan bertahap), hitung dari
        // state agar unik lintas-slice, bukan hanya slice terakhir.
        if ($candidates !== [] && isset($candidates['providers'])) {
            $counts = [
                'providers' => count((array) ($candidates['providers'] ?? [])),
                'services' => count((array) ($candidates['services'] ?? [])),
                'classes' => count((array) ($candidates['classes'] ?? [])),
            ];
            $take = function (array $pending): array {
                $list = array_values($pending);
                usort($list, fn ($a, $b) => strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? '')));

                return array_slice($list, 0, 100);
            };
            $lists = [
                'providers' => $take((array) ($candidates['providers'] ?? [])),
                'services' => $take((array) ($candidates['services'] ?? [])),
                'classes' => $take((array) ($candidates['classes'] ?? [])),
            ];
        }
        $summary['new_provider_total'] = $counts['providers'];
        $summary['new_service_total'] = $counts['services'];
        $summary['new_class_total'] = $counts['classes'];
        $summary['new_providers'] = $lists['providers'];
        $summary['new_services'] = $lists['services'];
        $summary['new_classes'] = $lists['classes'];
    }

    /**
     * Baca slice baris Excel 1-indexed [$startRow, $endRow] memakai
     * ReadFilter sehingga hanya slice tersebut yang dimuat ke memory.
     * Mengembalikan baris MENTAH (termasuk yang kosong) agar penomoran
     * baris konsisten dengan scan() satu request.
     *
     * @return array<int, array<int, mixed>>
     */
    public function readSlice(string $path, int $startRow, int $endRow): array
    {
        if ($endRow < $startRow) {
            return [];
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new TarifSliceReadFilter($startRow, $endRow));
        $spreadsheet = $reader->load($path);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $highest = $sheet->getHighestColumn($startRow);
            $rows = $sheet->rangeToArray("A{$startRow}:{$highest}{$endRow}", null, true, true, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return array_values(array_map(fn ($r) => array_values((array) $r), $rows));
    }

    /**
     * Hitung jumlah baris sheet tanpa memuat isi sel (murah).
     */
    public function countSheetRows(string $path): int
    {
        $info = IOFactory::createReaderForFile($path)->listWorksheetInfo($path);

        return (int) ($info[0]['totalRows'] ?? 0);
    }

    public static function isNonEmptyRow(mixed $row): bool
    {
        return collect((array) $row)->filter(fn ($c) => trim((string) $c) !== '')->isNotEmpty();
    }

    /**
     * Gabungkan satu hasil processRow ke preview/errors dengan batas.
     */
    protected function accumulate(array $processed, array &$preview, array &$errors, int $previewLimit): void
    {
        if (count($preview) < $previewLimit) {
            $preview[] = $processed['preview'];
        }
        if ($processed['preview']['status'] === TarifRowMapper::STATUS_ERROR && count($errors) < self::ERROR_LIMIT) {
            foreach ($processed['rowErrors'] as $field => $message) {
                $errors[] = [
                    'row' => $processed['preview']['row'],
                    'field' => $field,
                    'value' => $this->cellForError($processed['preview'], $field),
                    'message' => $message,
                ];
            }
        }
    }

    /**
     * Proses satu chunk Maatwebsite (dipakai TarifChunkImport).
     * Transaksional per chunk: BEGIN -> bulk create master yang hilang
     * (satu kali per business key) -> resolve ID -> batch insert tarif
     * -> COMMIT. Gagal di tengah => ROLLBACK chunk tersebut.
     *
     * @param  array<int, string>|null  $headerMap
     * @param  array<string, bool>  $seenKeys
     * @param  array<string, bool>  $existingKeys
     */
    public function importChunk(Collection $rows, int $jenisTarifId, ?array &$headerMap, array &$seenKeys, array &$existingKeys, object $stats): void
    {
        $raw = $rows->map(fn ($r) => array_values($r instanceof Collection ? $r->toArray() : (array) $r))->all();
        // Buang baris kosong.
        $raw = array_values(array_filter($raw, fn ($r) => collect($r)->filter(fn ($c) => trim((string) $c) !== '')->isNotEmpty()));
        if ($raw === []) {
            return;
        }

        if ($headerMap === null) {
            $header = TarifImportColumnMapper::validateHeaders($raw[0]);
            if (! $header['valid']) {
                throw new \RuntimeException('Header tidak valid. Kolom wajib hilang: '.implode(', ', $header['missing']));
            }
            $headerMap = $header['map'];
            array_shift($raw);
            $this->resolver->preload();
            $existingKeys = $this->loadExistingKeys($jenisTarifId);
        }

        if ($raw === []) {
            return;
        }

        $stats->processedRows += count($raw);

        // Fase 1 (tanpa DB write): normalisasi + kumpulkan kandidat master
        // UNIK per business key. 13.000 row PROVID sama => 1 kandidat.
        $valid = [];
        $errorCount = 0;
        $providerCandidates = [];
        $serviceCandidates = [];
        $classCandidates = [];
        foreach ($raw as $rawRow) {
            $mapped = TarifImportColumnMapper::applyMap($rawRow, $headerMap);
            $normalized = TarifRowMapper::normalize($mapped);
            if ($normalized['errors'] !== []) {
                $errorCount++;

                continue;
            }
            $data = $normalized['data'];
            $pKey = mb_strtoupper(trim((string) ($data[TarifImportColumnMapper::FIELD_PROVIDER_CODE] ?? '')));
            $sKey = mb_strtoupper(trim((string) ($data[TarifImportColumnMapper::FIELD_SERVICE_CODE] ?? '')));
            $cKey = mb_strtoupper(trim((string) ($data[TarifImportColumnMapper::FIELD_SERVICE_CLASS_CODE] ?? '')));
            if ($pKey !== '' && $pKey !== '-' && ! isset($providerCandidates[$pKey])) {
                $providerCandidates[$pKey] = ['code' => $pKey, 'name' => trim((string) ($data[TarifImportColumnMapper::FIELD_PROVIDER_NAME] ?? '')) ?: $pKey];
            }
            if ($sKey !== '' && $sKey !== '-' && ! isset($serviceCandidates[$sKey])) {
                $desc = trim((string) ($data[TarifImportColumnMapper::FIELD_SERVICE_DESCRIPTION] ?? ''));
                $serviceCandidates[$sKey] = ['code' => $sKey, 'name' => $desc !== '' && $desc !== '-' ? mb_substr($desc, 0, 255) : $sKey, 'description' => $desc !== '' && $desc !== '-' ? $desc : null];
            }
            if ($cKey !== '' && $cKey !== '-' && ! isset($classCandidates[$cKey])) {
                $cName = trim((string) ($data[TarifImportColumnMapper::FIELD_CLASS_NAME] ?? ''));
                $classCandidates[$cKey] = ['code' => $cKey, 'name' => $cName !== '' && $cName !== '-' ? mb_substr($cName, 0, 255) : $cKey];
            }
            $valid[] = $data;
        }
        $stats->skippedError += $errorCount;

        if ($valid === []) {
            return;
        }

        // Fase 2 (transaksional): persist kandidat master satu kali (batch)
        // lalu insert tarif. Jenis tarif TIDAK dibuat otomatis — dipakai
        // dari $jenisTarifId pilihan user untuk semua tarif.
        DB::transaction(function () use ($providerCandidates, $serviceCandidates, $classCandidates, $valid, $jenisTarifId, &$seenKeys, $existingKeys, $stats) {
            $this->resolver->ensureManyProviders($providerCandidates);
            $this->resolver->ensureManyServices($serviceCandidates);
            $this->resolver->ensureManyClasses($classCandidates);

            $batch = [];
            $dupFile = 0;
            $dupDb = 0;
            $errMaster = 0;
            foreach ($valid as $data) {
                $provider = $this->resolver->provider($data[TarifImportColumnMapper::FIELD_PROVIDER_CODE]);
                $service = $this->resolver->service($data[TarifImportColumnMapper::FIELD_SERVICE_CODE]);
                $class = $this->resolver->serviceClass(
                    $data[TarifImportColumnMapper::FIELD_SERVICE_CLASS_CODE],
                    $data[TarifImportColumnMapper::FIELD_CLASS_NAME]
                );
                if (! $provider || ! $service || ! $class) {
                    $errMaster++;

                    continue;
                }
                $persist = [
                    'jenis_tarif_id' => $jenisTarifId,
                    'provider_id' => $provider->id,
                    'service_id' => $service->id,
                    'class_id' => $class->id,
                    'surgery_type' => $data[TarifImportColumnMapper::FIELD_SURGERY_TYPE],
                    'helper' => $data[TarifImportColumnMapper::FIELD_HELPER],
                    'tariff' => $data[TarifImportColumnMapper::FIELD_TARIFF],
                    'valid_date_from' => $data[TarifImportColumnMapper::FIELD_VALID_FROM],
                    'end_date_to' => $data[TarifImportColumnMapper::FIELD_VALID_TO],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $key = self::businessKey($persist);
                if (isset($seenKeys[$key]) || isset($existingKeys[$key])) {
                    if (isset($seenKeys[$key])) {
                        $dupFile++;
                    } else {
                        $dupDb++;
                    }

                    continue;
                }
                $seenKeys[$key] = true;
                $batch[] = $persist;
            }
            $stats->skippedError += $errMaster;
            $stats->skippedDuplicate += $dupFile + $dupDb;

            if ($batch === []) {
                return;
            }
            $freshKeys = $this->loadExistingKeysFor($jenisTarifId, $batch);
            $insertable = [];
            foreach ($batch as $row) {
                if (isset($freshKeys[self::businessKey($row)])) {
                    $stats->skippedDuplicate++;

                    continue;
                }
                $insertable[] = $row;
            }
            if ($insertable !== []) {
                Tarif::insert($insertable);
                $stats->inserted += count($insertable);
            }
        });
    }

    /**
     * COMMIT: tulis baris VALID yang tidak duplicate ke database via
     * chunk reading (1000 row/chunk, satu transaction per chunk).
     * Baris error/duplicate di-SKIP dan dilaporkan — tidak menggagalkan
     * seluruh import (atomic per chunk, bukan per file).
     *
     * @return array{inserted: int, skipped_error: int, skipped_duplicate: int, total_rows: int}
     */
    public function commit(string $path, int $jenisTarifId): array
    {
        JenisTarif::findOrFail($jenisTarifId);

        $import = new TarifChunkImport($this, $jenisTarifId);
        Excel::import($import, $path);

        return [
            'inserted' => $import->inserted,
            'skipped_error' => $import->skippedError,
            'skipped_duplicate' => $import->skippedDuplicate,
            'total_rows' => $import->processedRows,
        ];
    }

    /**
     * Proses satu baris untuk SCAN. Mengembalikan preview row + rowErrors.
     *
     * @param  array<int, string>  $map
     * @param  array<string, bool>  $seenKeys
     * @param  array<string, bool>  $existingKeys
     */
    protected function processRow(array $rawRow, array $map, int $rowNumber, array &$seenKeys, array $existingKeys, array &$summary): array
    {
        $mapped = TarifImportColumnMapper::applyMap($rawRow, $map);
        $normalized = TarifRowMapper::normalize($mapped);
        $data = $normalized['data'];
        $rowErrors = $normalized['errors'];
        $warnings = $normalized['warnings'];

        // Master awal boleh kosong: bukan ERROR. Resolve pola spec —
        // DB -> cache kandidat -> kandidat baru (in-memory, tanpa insert).
        // WARNING = data valid tapi butuh pembuatan master baru.
        // VALID = semua master sudah ditemukan. Tidak ada query/insert
        // DB di sini selain preload awal.
        [$provider, $providerWillCreate] = $this->resolver->resolveProvider(
            $data[TarifImportColumnMapper::FIELD_PROVIDER_CODE],
            $data[TarifImportColumnMapper::FIELD_PROVIDER_NAME]
        );
        [$service, $serviceWillCreate] = $this->resolver->resolveService(
            $data[TarifImportColumnMapper::FIELD_SERVICE_CODE],
            $data[TarifImportColumnMapper::FIELD_SERVICE_DESCRIPTION]
        );
        [$class, $classWillCreate] = $this->resolver->resolveClass(
            $data[TarifImportColumnMapper::FIELD_SERVICE_CLASS_CODE],
            $data[TarifImportColumnMapper::FIELD_CLASS_NAME]
        );

        if ($data[TarifImportColumnMapper::FIELD_PROVIDER_CODE] !== null && $provider === null) {
            $rowErrors['provider_code'] = 'PROVID "'.$data[TarifImportColumnMapper::FIELD_PROVIDER_CODE].'" kosong / tidak valid.';
        } elseif ($providerWillCreate && $provider) {
            $warnings['provider_code'] = 'Provider baru akan dibuat: '.$provider->code.($provider->name && $provider->name !== $provider->code ? ' — '.$provider->name : '');
        } elseif ($provider && TarifMasterResolver::nameMismatch($data[TarifImportColumnMapper::FIELD_PROVIDER_NAME], $provider->name)) {
            $warnings['provider_name'] = 'PROVIDER_NAME berbeda dengan master ("'.$provider->name.'"), dipakai nama master.';
        }

        if ($data[TarifImportColumnMapper::FIELD_SERVICE_CODE] !== null && $service === null) {
            $rowErrors['service_code'] = 'SERVICECODE "'.$data[TarifImportColumnMapper::FIELD_SERVICE_CODE].'" kosong / tidak valid.';
        } elseif ($serviceWillCreate && $service) {
            $warnings['service_code'] = 'Service baru akan dibuat: '.$service->code.($service->name && $service->name !== $service->code ? ' — '.$service->name : '');
        }

        if ($class === null) {
            $codeShown = $data[TarifImportColumnMapper::FIELD_SERVICE_CLASS_CODE]
                ?? $data[TarifImportColumnMapper::FIELD_CLASS_NAME]
                ?? '-';
            $rowErrors['service_class_code'] = 'Kelas "'.$codeShown.'" tidak ditemukan dan tidak bisa dibuat otomatis tanpa kode (isi SERVICECODE_KELAS).';
        } elseif ($classWillCreate) {
            $warnings['service_class_code'] = 'Kelas baru akan dibuat: '.$class->code.($class->name && $class->name !== $class->code ? ' — '.$class->name : '');
        }

        $status = $rowErrors !== []
            ? TarifRowMapper::STATUS_ERROR
            : ($warnings !== [] ? TarifRowMapper::STATUS_WARNING : TarifRowMapper::STATUS_VALID);

        $summary['provider_found'] += $provider !== null ? 1 : 0;
        $summary['provider_missing'] += $provider === null ? 1 : 0;
        $summary['service_found'] += $service !== null ? 1 : 0;
        $summary['service_missing'] += $service === null ? 1 : 0;
        $summary['class_found'] += $class !== null ? 1 : 0;
        $summary['class_missing'] += $class === null ? 1 : 0;
        $summary['provider_will_create'] = ($summary['provider_will_create'] ?? 0) + ($providerWillCreate ? 1 : 0);
        $summary['service_will_create'] = ($summary['service_will_create'] ?? 0) + ($serviceWillCreate ? 1 : 0);
        $summary['class_will_create'] = ($summary['class_will_create'] ?? 0) + ($classWillCreate ? 1 : 0);

        $key = null;
        if ($status !== TarifRowMapper::STATUS_ERROR && $provider && $service && $class) {
            // Untuk master yang akan dibuat (belum punya id), pakai kode
            // sebagai identitas sementara agar duplikat dalam file tetap
            // terdeteksi. Duplikat DB hanya dicek bila semua master existing.
            $providerKey = $provider->id ?? ('new:'.mb_strtoupper($provider->code));
            $serviceKey = $service->id ?? ('new:'.mb_strtoupper($service->code));
            $classKey = $class->id ?? ('new:'.mb_strtoupper($class->code));
            $allExisting = ! $providerWillCreate && ! $serviceWillCreate && ! $classWillCreate;
            $key = implode('|', [
                $summary['jenis_tarif_id'], $providerKey, $serviceKey, $classKey,
                $data[TarifImportColumnMapper::FIELD_SURGERY_TYPE] ?? '',
                $data[TarifImportColumnMapper::FIELD_VALID_FROM],
                $data[TarifImportColumnMapper::FIELD_VALID_TO],
            ]);

            if (isset($seenKeys[$key])) {
                $status = TarifRowMapper::STATUS_DUPLICATE;
                $summary['duplicate_in_file']++;
                $rowErrors['_duplicate'] = 'Duplikat baris '.$seenKeys[$key].' dalam file yang sama.';
            } elseif ($allExisting && isset($existingKeys[$key])) {
                $status = TarifRowMapper::STATUS_DUPLICATE;
                $summary['duplicate_in_db']++;
                $rowErrors['_duplicate'] = 'Data sudah ada di database (key sama).';
            } else {
                $seenKeys[$key] = $rowNumber;
            }
        }

        match ($status) {
            TarifRowMapper::STATUS_VALID => $summary['valid_rows']++,
            TarifRowMapper::STATUS_WARNING => $summary['warning_rows']++,
            TarifRowMapper::STATUS_DUPLICATE => $summary['duplicate_rows']++,
            default => $summary['error_rows']++,
        };

        return [
            'rowErrors' => $rowErrors,
            'preview' => [
                'row' => $rowNumber,
                'status' => $status,
                'provider_code' => $data[TarifImportColumnMapper::FIELD_PROVIDER_CODE],
                'provider_name' => $provider?->name ?? $data[TarifImportColumnMapper::FIELD_PROVIDER_NAME],
                'service_code' => $data[TarifImportColumnMapper::FIELD_SERVICE_CODE],
                'service_description' => $service?->description ?? $service?->name ?? $data[TarifImportColumnMapper::FIELD_SERVICE_DESCRIPTION],
                'service_class_code' => $class?->code ?? $data[TarifImportColumnMapper::FIELD_SERVICE_CLASS_CODE],
                'class_name' => $class?->name ?? $data[TarifImportColumnMapper::FIELD_CLASS_NAME],
                'surgery_type' => $data[TarifImportColumnMapper::FIELD_SURGERY_TYPE],
                'helper' => $data[TarifImportColumnMapper::FIELD_HELPER],
                'tariff' => $data[TarifImportColumnMapper::FIELD_TARIFF],
                'valid_date_from' => $data[TarifImportColumnMapper::FIELD_VALID_FROM],
                'end_date_to' => $data[TarifImportColumnMapper::FIELD_VALID_TO],
                'messages' => array_values(array_merge($rowErrors, $warnings)),
            ],
        ];
    }

    /**
     * Ubah satu baris mentah menjadi array siap insert (atau null bila
     * error/duplicate). Dipakai oleh commit().
     *
     * Catatan: TIDAK menulis master ke DB (cache-only). Pembuatan master
     * dilakukan bulk di importChunk() dalam transaksi yang sama dengan
     * insert tarif. Method ini hanya resolve dari cache hasil bulk.
     *
     * @param  array<int, string>  $map
     */
    protected function toPersistRow(array $rawRow, array $map, int $jenisTarifId, array &$seenKeys, array $existingKeys, array &$summary): ?array
    {
        $mapped = TarifImportColumnMapper::applyMap($rawRow, $map);
        $normalized = TarifRowMapper::normalize($mapped);
        if ($normalized['errors'] !== []) {
            $summary['error_rows']++;

            return null;
        }
        $data = $normalized['data'];

        // Cache-only: tanpa insert (lihat importChunk untuk bulk persist).
        $provider = $this->resolver->provider($data[TarifImportColumnMapper::FIELD_PROVIDER_CODE]);
        $service = $this->resolver->service($data[TarifImportColumnMapper::FIELD_SERVICE_CODE]);
        $class = $this->resolver->serviceClass(
            $data[TarifImportColumnMapper::FIELD_SERVICE_CLASS_CODE],
            $data[TarifImportColumnMapper::FIELD_CLASS_NAME]
        );

        if (! $provider || ! $service || ! $class) {
            $summary['error_rows']++;

            return null;
        }

        $persist = [
            'jenis_tarif_id' => $jenisTarifId,
            'provider_id' => $provider->id,
            'service_id' => $service->id,
            'class_id' => $class->id,
            'surgery_type' => $data[TarifImportColumnMapper::FIELD_SURGERY_TYPE],
            'helper' => $data[TarifImportColumnMapper::FIELD_HELPER],
            'tariff' => $data[TarifImportColumnMapper::FIELD_TARIFF],
            'valid_date_from' => $data[TarifImportColumnMapper::FIELD_VALID_FROM],
            'end_date_to' => $data[TarifImportColumnMapper::FIELD_VALID_TO],
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $key = self::businessKey($persist);
        if (isset($seenKeys[$key]) || isset($existingKeys[$key])) {
            if (isset($seenKeys[$key])) {
                $summary['duplicate_in_file']++;
            } else {
                $summary['duplicate_in_db']++;
            }

            return null;
        }
        $seenKeys[$key] = true;

        return $persist;
    }

    /**
     * Preload master + seluruh business key existing untuk satu jenis tarif.
     * Public agar bisa dipakai scan bertahap (satu query per slice request).
     *
     * @return array<string, bool>
     */
    public function existingKeysFor(int $jenisTarifId): array
    {
        $this->resolver->preload();

        return $this->loadExistingKeys($jenisTarifId);
    }

    /**
     * Preload seluruh business key existing untuk satu jenis tarif.
     * Dibaca per chunk 2000 agar hemat memory.
     *
     * @return array<string, bool>
     */
    protected function loadExistingKeys(int $jenisTarifId): array
    {
        $keys = [];
        Tarif::where('jenis_tarif_id', $jenisTarifId)
            ->select(['jenis_tarif_id', 'provider_id', 'service_id', 'class_id', 'surgery_type', 'valid_date_from', 'end_date_to'])
            ->chunk(2000, function (Collection $tarifs) use (&$keys) {
                foreach ($tarifs as $tarif) {
                    $keys[self::businessKey($tarif->only([
                        'jenis_tarif_id', 'provider_id', 'service_id', 'class_id',
                        'surgery_type', 'valid_date_from', 'end_date_to',
                    ]))] = true;
                }
            });

        return $keys;
    }

    /**
     * Guard race saat commit: cek ulang key milik batch ini saja.
     *
     * @param  array<int, array<string, mixed>>  $batch
     * @return array<string, bool>
     */
    protected function loadExistingKeysFor(int $jenisTarifId, array $batch): array
    {
        $pairs = collect($batch)->map(fn ($r) => [
            $r['provider_id'], $r['service_id'], $r['class_id'], $r['surgery_type'] ?? null,
            $r['valid_date_from'], $r['end_date_to'],
        ])->unique(fn ($p) => implode('|', array_map(fn ($v) => (string) ($v ?? ''), $p)))->values();

        if ($pairs->isEmpty()) {
            return [];
        }

        $keys = [];
        // Query per 500 kombinasi agar where tidak terlalu panjang.
        foreach ($pairs->chunk(500) as $slice) {
            $query = Tarif::where('jenis_tarif_id', $jenisTarifId)
                ->select(['jenis_tarif_id', 'provider_id', 'service_id', 'class_id', 'surgery_type', 'valid_date_from', 'end_date_to']);
            $query->where(function ($q) use ($slice) {
                foreach ($slice as $p) {
                    $q->orWhere(function ($qq) use ($p) {
                        $qq->where('provider_id', $p[0])
                            ->where('service_id', $p[1])
                            ->where('class_id', $p[2]);
                        if ($p[3] === null || $p[3] === '') {
                            $qq->whereNull('surgery_type');
                        } else {
                            $qq->where('surgery_type', $p[3]);
                        }
                        $qq->where('valid_date_from', $p[4])
                            ->where('end_date_to', $p[5]);
                    });
                }
            });
            foreach ($query->get() as $tarif) {
                $keys[self::businessKey($tarif->only([
                    'jenis_tarif_id', 'provider_id', 'service_id', 'class_id',
                    'surgery_type', 'valid_date_from', 'end_date_to',
                ]))] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<int, mixed>  $headerRow
     */
    protected function headerSummary(array $headerRow, array $header): array
    {
        return [
            'columns' => array_values(array_map(fn ($c) => trim((string) $c), $headerRow)),
            'column_count' => count($headerRow),
            'mapped_count' => count($header['map']),
            'missing' => $header['missing'],
            'unknown' => $header['unknown'],
            'valid' => $header['valid'],
        ];
    }

    /**
     * @param  array<int, mixed>  $headerRow
     */
    protected function freshSummary(string $filename, ?JenisTarif $jenis, array $headerRow, array $header): array
    {
        return [
            'filename' => $filename,
            'jenis_tarif_id' => $jenis?->id,
            'jenis_tarif_name' => $jenis ? $jenis->code.' — '.$jenis->name : null,
            'header' => $headerRow === [] ? null : $this->headerSummary($headerRow, $header),
            'total_rows' => 0,
            'valid_rows' => 0,
            'warning_rows' => 0,
            'error_rows' => 0,
            'duplicate_rows' => 0,
            'duplicate_in_file' => 0,
            'duplicate_in_db' => 0,
            'provider_found' => 0,
            'provider_missing' => 0,
            'service_found' => 0,
            'service_missing' => 0,
            'class_found' => 0,
            'class_missing' => 0,
            'provider_will_create' => 0,
            'service_will_create' => 0,
            'class_will_create' => 0,
            // Hitungan UNIK (dedup per business key), bukan per row.
            // 13.000 row PROVID sama => 1, bukan 13.000.
            'new_provider_total' => 0,
            'new_service_total' => 0,
            'new_class_total' => 0,
            'new_providers' => [],
            'new_services' => [],
            'new_classes' => [],
            'processed' => 0,
            'preview' => [],
            'errors' => [],
        ];
    }

    /**
     * Finalisasi state scan bertahap menjadi $result dengan bentuk yang
     * SAMA PERSIS seperti scan() agar blade tidak perlu dua format.
     */
    public function finalizeScanState(array $state, string $token, int $previewLimit = self::PREVIEW_LIMIT): array
    {
        $summary = $state['summary'];
        $summary['preview'] = $state['preview'];
        $summary['errors'] = $state['errors'];
        $summary['truncated_preview'] = $summary['total_rows'] > $previewLimit;
        $summary['truncated_errors'] = count($state['errors']) >= self::ERROR_LIMIT;
        $summary['token'] = $token;
        $this->attachCandidates($summary, $state['candidates'] ?? null);

        return $summary;
    }

    /**
     * State awal akumulator scan bertahap (disimpan di cache antar request).
     */
    public function freshScanState(string $filename, ?JenisTarif $jenis, array $headerRow, array $header): array
    {
        return [
            'summary' => $this->freshSummary($filename, $jenis, $headerRow, $header),
            'seen' => [],
            'preview' => [],
            'errors' => [],
            'candidates' => ['providers' => [], 'services' => [], 'classes' => []],
            'empty_streak' => 0,
        ];
    }

    protected function emptyResult(string $filename, ?JenisTarif $jenis, string $message): array
    {
        $result = $this->freshSummary($filename, $jenis, [], ['map' => [], 'missing' => [], 'unknown' => [], 'valid' => false]);
        $result['fatal'] = $message;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    protected function cellForError(array $preview, string $field): mixed
    {
        $map = [
            'provider_code' => $preview['provider_code'] ?? null,
            'service_code' => $preview['service_code'] ?? null,
            'service_class_code' => $preview['service_class_code'] ?? null,
            'surgery_type' => $preview['surgery_type'] ?? null,
            'tariff' => $preview['tariff'] ?? null,
            'valid_date_from' => $preview['valid_date_from'] ?? null,
            'end_date_to' => $preview['end_date_to'] ?? null,
        ];

        return $map[$field] ?? null;
    }
}
