<?php

namespace App\Services\Bridge;

use Illuminate\Support\Facades\DB;

/**
 * Sumber master untuk Bridge: tabel tarifs (relasi service + class).
 * Preload SEKALI lalu lookup via associative map — tanpa query per row.
 * Tidak pernah membuat/mengubah master.
 */
class TarifBridgeRepository
{
    /** @var array<string, array<int, array{code: string}>> mapping_key => pasangan unik */
    protected array $map = [];

    protected bool $loaded = false;

    /** @var array<string, string>|null nama kelas ternormalisasi => kode */
    protected ?array $classMap = null;

    /** @var array<string, string>|null kode kelas (upper+trim) => kode */
    protected ?array $classCodeMap = null;

    public function preload(): self
    {
        if ($this->loaded) {
            return $this;
        }

        // Master bisa 200rb+ row: jangan hidrasi Eloquent sekaligus (OOM).
        // Streaming via query builder per chunk, hanya kolom yang dipakai.
        DB::table('tarifs')
            ->join('services as s', 's.id', '=', 'tarifs.service_id')
            ->join('classes as c', 'c.id', '=', 'tarifs.class_id')
            ->select([
                's.code as service_code',
                's.name as service_name',
                's.description as service_description',
                'c.code as class_code',
                'c.name as class_name',
                'tarifs.tariff as tariff',
                'tarifs.valid_date_from as valid_from',
            ])
            ->orderBy('tarifs.id')
            ->chunk(5000, function ($rows) {
                foreach ($rows as $row) {
                    $serviceKeys = array_unique(array_filter([
                        BridgeTarifRowNormalizer::normalizeKey($row->service_name),
                        BridgeTarifRowNormalizer::normalizeKey($row->service_description),
                    ]));
                    $classKey = BridgeTarifRowNormalizer::normalizeKey($row->class_name);
                    if ($serviceKeys === [] || $classKey === '') {
                        continue;
                    }

                    $pairKey = mb_strtoupper(trim((string) $row->service_code)).'|'.mb_strtoupper(trim((string) $row->class_code));
                    $tariff = is_numeric($row->tariff) ? (float) $row->tariff : null;
                    if ($tariff !== null && $tariff <= 0) {
                        $tariff = null;
                    }

                    foreach ($serviceKeys as $serviceKey) {
                        $mapKey = $serviceKey.'|'.$classKey;
                        if (! isset($this->map[$mapKey][$pairKey])) {
                            $this->map[$mapKey][$pairKey] = [
                                'service_code' => mb_strtoupper(trim((string) $row->service_code)),
                                'service_name' => $row->service_name,
                                'class_code' => mb_strtoupper(trim((string) $row->class_code)),
                                'class_name' => $row->class_name,
                                // Satu pair bisa punya banyak tarif (beda
                                // provider/jenis/periode): kumpulkan semua,
                                // representative = nilai tengah (median)
                                // agar stabil terhadap outlier.
                                'tariffs' => [],
                                'tariff' => null,
                                // Periode master terbaru pair ini (untuk
                                // tie-break AMBIGUOUS saat tarif seri).
                                'valid_from' => null,
                            ];
                        }
                        if ($tariff !== null && ! in_array($tariff, $this->map[$mapKey][$pairKey]['tariffs'], true)) {
                            $this->map[$mapKey][$pairKey]['tariffs'][] = $tariff;
                        }
                        $validFrom = trim((string) ($row->valid_from ?? ''));
                        if ($validFrom !== '' && (($this->map[$mapKey][$pairKey]['valid_from'] ?? null) === null
                            || $validFrom > $this->map[$mapKey][$pairKey]['valid_from'])
                        ) {
                            $this->map[$mapKey][$pairKey]['valid_from'] = $validFrom;
                        }
                    }
                }
            });

        // Hitung representative (median) per pair setelah chunk selesai.
        foreach ($this->map as $mapKey => $pairs) {
            foreach ($pairs as $pairKey => $pair) {
                $tariffs = $pair['tariffs'];
                sort($tariffs);
                $n = count($tariffs);
                $this->map[$mapKey][$pairKey]['tariff'] = $n === 0
                    ? null
                    : $tariffs[(int) floor(($n - 1) / 2)];
            }
        }

        $this->loaded = true;

        return $this;
    }

    /**
     * Kandidat (pasangan service+class unik) untuk satu mapping key,
     * terurut deterministik. [] bila tidak ada.
     *
     * @return array<int, array{service_code: string, service_name: string, class_code: string, class_name: string, tariff: ?float, tariffs: array<int, float>}>
     */
    public function candidatesFor(string $mappingKey): array
    {
        $this->preload();

        $pairs = array_values($this->map[$mappingKey] ?? []);
        usort($pairs, fn ($a, $b) => [$a['service_code'], $a['class_code']] <=> [$b['service_code'], $b['class_code']]);

        return $pairs;
    }

    /**
     * Cek pasangan service+class benar-benar ada di master Tarif.
     * Dipakai untuk validasi pilihan manual pada grup NOT_FOUND.
     */
    public function pairExists(string $serviceCode, string $classCode): bool
    {
        $serviceCode = mb_strtoupper(trim($serviceCode));
        $classCode = mb_strtoupper(trim($classCode));
        if ($serviceCode === '' || $classCode === '') {
            return false;
        }

        return DB::table('tarifs')
            ->join('services as s', 's.id', '=', 'tarifs.service_id')
            ->join('classes as c', 'c.id', '=', 'tarifs.class_id')
            ->whereRaw('UPPER(TRIM(s.code)) = ?', [$serviceCode])
            ->whereRaw('UPPER(TRIM(c.code)) = ?', [$classCode])
            ->exists();
    }

    /**
     * Cek kode service ada di master. Dipakai untuk validasi pilihan
     * manual NOT_FOUND.
     */
    public function serviceExists(string $serviceCode): bool
    {
        $serviceCode = mb_strtoupper(trim($serviceCode));
        if ($serviceCode === '') {
            return false;
        }

        return DB::table('services')
            ->whereRaw('UPPER(TRIM(code)) = ?', [$serviceCode])
            ->exists();
    }

    /**
     * Kode kelas master untuk satu baris Excel. Urutan: cocokkan NAMA
     * kelas dulu (persis setelah normalisasi), lalu KODE kelas. Selama
     * salah satunya ada di master, classcode bisa diproses.
     * null bila keduanya tidak ada di master.
     */
    public function classCodeFor(?string $className, ?string $classCode = null): ?string
    {
        if ($this->classMap === null) {
            $this->classMap = [];
            $this->classCodeMap = [];
            foreach (DB::table('classes')->select(['code', 'name'])->orderBy('id')->get() as $row) {
                $code = mb_strtoupper(trim((string) $row->code));
                if ($code !== '' && ! isset($this->classCodeMap[$code])) {
                    $this->classCodeMap[$code] = $code;
                }
                $key = BridgeTarifRowNormalizer::normalizeKey((string) $row->name);
                if ($key !== '' && ! isset($this->classMap[$key])) {
                    $this->classMap[$key] = $code;
                }
            }
        }

        $nameKey = BridgeTarifRowNormalizer::normalizeKey((string) $className);
        if ($nameKey !== '' && isset($this->classMap[$nameKey])) {
            return $this->classMap[$nameKey];
        }

        $codeKey = mb_strtoupper(trim((string) $classCode));
        if ($codeKey !== '' && isset($this->classCodeMap[$codeKey])) {
            return $this->classCodeMap[$codeKey];
        }

        return null;
    }
}
