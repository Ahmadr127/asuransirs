<?php

namespace App\Services\TarifImport;

use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceClass;

/**
 * Resolver master generik dengan preload + cache in-memory.
 *
 * Business rule (master awal boleh kosong):
 * - Master Provider/Service/Kelas yang belum ada BUKAN error.
 * - SCAN = READ/PREVIEW saja: tidak ada insert DB. Kandidat baru hanya
 *   dikumpulkan in-memory (unique per business key `code` upper).
 * - IMPORT = BEGIN TRANSACTION: kandidat dipersist satu kali (batch),
 *   lalu tarif memakai ID hasil resolve.
 * - Deduplikasi via business key `code` (case-insensitive, UNIQUE di DB):
 *   DB -> cache kandidat -> buat kandidat baru. 13.000 row dengan PROVID
 *   sama menghasilkan 1 kandidat, bukan 13.000.
 * - Lookup memakai business key existing: kolom `code` yang UNIQUE di
 *   providers / services / classes, dengan fallback nama untuk Kelas.
 *
 * Pola: resolveProvider($code, $name) / resolveService($code, $description)
 * / resolveClass($code, $name):
 *   1. cari di database (preload cache), 2. jika ketemu pakai ID existing,
 *   3. jika tidak cek cache kandidat, 4. jika belum ada buat kandidat baru,
 *   5. saat commit persist kandidat satu kali.
 */
class TarifMasterResolver
{
    /** @var array<string, Provider> code_upper => model */
    protected array $providers = [];

    /** @var array<string, Service> code_upper => model */
    protected array $services = [];

    /** @var array<string, ServiceClass> code_upper => model */
    protected array $classes = [];

    /** @var array<string, ServiceClass> name_upper => model (fallback) */
    protected array $classesByName = [];

    protected bool $loaded = false;

    /**
     * Master yang direncanakan dibuat saat SCAN (dry-run, tanpa tulis DB).
     * code_upper => attribute array. Dipakai agar preview VALID + warning
     * "akan dibuat", dan agar duplikat dalam file tidak dihitung berkali-kali.
     *
     * @var array<string, array{code: string, name: string}>
     */
    protected array $pendingProviders = [];

    /** @var array<string, array{code: string, name: string, description: ?string}> */
    protected array $pendingServices = [];

    /** @var array<string, array{code: string, name: string}> */
    protected array $pendingClasses = [];

    public static function preloaded(): self
    {
        return (new self)->preload();
    }

    public function preload(): self
    {
        if ($this->loaded) {
            return $this;
        }

        foreach (Provider::query()->get(['id', 'code', 'name']) as $provider) {
            $this->providers[mb_strtoupper(trim($provider->code))] = $provider;
        }

        foreach (Service::query()->get(['id', 'code', 'name']) as $service) {
            $this->services[mb_strtoupper(trim($service->code))] = $service;
        }

        foreach (ServiceClass::query()->get(['id', 'code', 'name']) as $class) {
            $this->classes[mb_strtoupper(trim($class->code))] = $class;
            $this->classesByName[mb_strtoupper(trim($class->name))] = $class;
        }

        $this->loaded = true;

        return $this;
    }

    public function provider(?string $code): ?Provider
    {
        $this->preload();
        $code = mb_strtoupper(trim((string) $code));

        return $code === '' ? null : ($this->providers[$code] ?? null);
    }

    public function service(?string $code): ?Service
    {
        $this->preload();
        $code = mb_strtoupper(trim((string) $code));

        return $code === '' ? null : ($this->services[$code] ?? null);
    }

    public function serviceClass(?string $code, ?string $name = null): ?ServiceClass
    {
        $this->preload();
        $code = mb_strtoupper(trim((string) $code));

        if ($code !== '' && isset($this->classes[$code])) {
            return $this->classes[$code];
        }

        $name = mb_strtoupper(trim((string) $name));
        if ($name !== '' && isset($this->classesByName[$name])) {
            return $this->classesByName[$name];
        }

        return null;
    }

    // -----------------------------------------------------------------
    // SCAN (dry-run): rencanakan master baru tanpa tulis DB.
    // Pola spec: resolveProvider($code, $name) /
    // resolveService($code, $description) / resolveClass($code, $name).
    // Return [model|null, willCreate: bool]. Model untuk kandidat adalah
    // instance transient (tanpa id) agar preview tetap bisa tampilkan
    // code/name. Duplikat dalam file hanya dicatat sekali di $pending*.
    // TIDAK ada query/insert DB di sini selain preload awal.
    // -----------------------------------------------------------------

    /** @return array{0: ?Provider, 1: bool} */
    public function resolveProvider(?string $code, ?string $name = null): array
    {
        $this->preload();
        $key = mb_strtoupper(trim((string) $code));
        if ($key === '' || $key === '-') {
            return [null, false];
        }
        // 1-2. Database (preload cache).
        if (isset($this->providers[$key])) {
            return [$this->providers[$key], false];
        }
        // 3-4. Cache kandidat import.
        if (isset($this->pendingProviders[$key])) {
            $p = $this->pendingProviders[$key];

            return [new Provider(['code' => $p['code'], 'name' => $p['name'], 'status' => 'active']), true];
        }
        // 5. Kandidat master baru (in-memory saja).
        $cleanName = trim((string) $name);
        if ($cleanName === '' || $cleanName === '-') {
            $cleanName = $key;
        }
        $cleanName = mb_substr($cleanName, 0, 255);
        $this->pendingProviders[$key] = ['code' => $key, 'name' => $cleanName];

        return [new Provider(['code' => $key, 'name' => $cleanName, 'status' => 'active']), true];
    }

    /** @return array{0: ?Service, 1: bool} */
    public function resolveService(?string $code, ?string $description = null): array
    {
        $this->preload();
        $key = mb_strtoupper(trim((string) $code));
        if ($key === '' || $key === '-') {
            return [null, false];
        }
        if (isset($this->services[$key])) {
            return [$this->services[$key], false];
        }
        if (isset($this->pendingServices[$key])) {
            $p = $this->pendingServices[$key];

            return [new Service(['code' => $p['code'], 'name' => $p['name'], 'description' => $p['description'], 'status' => 'active']), true];
        }
        $cleanDesc = trim((string) $description);
        if ($cleanDesc === '' || $cleanDesc === '-') {
            $cleanDesc = '';
        }
        $name = $cleanDesc !== '' ? mb_substr($cleanDesc, 0, 255) : $key;

        $this->pendingServices[$key] = ['code' => $key, 'name' => $name, 'description' => $cleanDesc !== '' ? $cleanDesc : null];

        return [new Service(['code' => $key, 'name' => $name, 'description' => $cleanDesc !== '' ? $cleanDesc : null, 'status' => 'active']), true];
    }

    /** @return array{0: ?ServiceClass, 1: bool} */
    public function resolveClass(?string $code, ?string $name = null): array
    {
        return $this->planServiceClass($code, $name);
    }

    /**
     * @return array{0: ?Provider, 1: bool}
     */
    public function planProvider(?string $code, ?string $name = null): array
    {
        return $this->resolveProvider($code, $name);
    }

    /**
     * @return array{0: ?Service, 1: bool}
     */
    public function planService(?string $code, ?string $description = null): array
    {
        return $this->resolveService($code, $description);
    }

    /**
     * @return array{0: ?ServiceClass, 1: bool}
     */
    public function planServiceClass(?string $code, ?string $name = null): array
    {
        $this->preload();
        $key = mb_strtoupper(trim((string) $code));
        $cleanName = trim((string) $name);
        if ($cleanName === '-') {
            $cleanName = '';
        }

        if ($key !== '' && $key !== '-' && isset($this->classes[$key])) {
            return [$this->classes[$key], false];
        }
        // Fallback nama bila kode kosong: hanya reuse existing, tidak
        // bisa rencanakan baru tanpa kode (business key).
        if ($key === '' || $key === '-') {
            $upper = mb_strtoupper($cleanName);
            if ($upper !== '' && isset($this->classesByName[$upper])) {
                return [$this->classesByName[$upper], false];
            }

            return [null, false];
        }
        if (isset($this->pendingClasses[$key])) {
            $p = $this->pendingClasses[$key];

            $m = new ServiceClass(['code' => $p['code'], 'name' => $p['name'], 'status' => 'active']);
            $m->setTable('classes');

            return [$m, true];
        }
        $upperName = mb_strtoupper($cleanName);
        if ($upperName !== '' && isset($this->classesByName[$upperName])) {
            return [$this->classesByName[$upperName], false];
        }
        $finalName = $cleanName !== '' ? mb_substr($cleanName, 0, 255) : $key;
        $this->pendingClasses[$key] = ['code' => $key, 'name' => $finalName];

        $m = new ServiceClass(['code' => $key, 'name' => $finalName, 'status' => 'active']);
        $m->setTable('classes');

        return [$m, true];
    }

    // -----------------------------------------------------------------
    // COMMIT: buat master beneran dengan cek duplikat (aman dari race).
    // -----------------------------------------------------------------

    public function ensureProvider(?string $code, ?string $name = null): ?Provider
    {
        $this->preload();
        $key = mb_strtoupper(trim((string) $code));
        if ($key === '' || $key === '-') {
            return null;
        }
        if (isset($this->providers[$key])) {
            return $this->providers[$key];
        }
        // Cek DB case-insensitive agar tidak duplikat beda casing.
        $existing = Provider::whereRaw('UPPER(code) = ?', [$key])->first();
        if ($existing) {
            $this->providers[$key] = $existing;

            return $existing;
        }
        $cleanName = trim((string) $name);
        if ($cleanName === '' || $cleanName === '-') {
            $cleanName = $key;
        }
        $cleanName = mb_substr($cleanName, 0, 255);
        try {
            $model = Provider::create(['code' => $key, 'name' => $cleanName, 'status' => 'active']);
        } catch (\Illuminate\Database\QueryException) {
            $model = Provider::whereRaw('UPPER(code) = ?', [$key])->first();
            if (! $model) {
                throw new \RuntimeException('Gagal membuat master Provider "'.$key.'".');
            }
        }
        $this->providers[$key] = $model;
        unset($this->pendingProviders[$key]);

        return $model;
    }

    public function ensureService(?string $code, ?string $description = null): ?Service
    {
        $this->preload();
        $key = mb_strtoupper(trim((string) $code));
        if ($key === '' || $key === '-') {
            return null;
        }
        if (isset($this->services[$key])) {
            return $this->services[$key];
        }
        $existing = Service::whereRaw('UPPER(code) = ?', [$key])->first();
        if ($existing) {
            $this->services[$key] = $existing;

            return $existing;
        }
        $cleanDesc = trim((string) $description);
        if ($cleanDesc === '' || $cleanDesc === '-') {
            $cleanDesc = '';
        }
        $name = $cleanDesc !== '' ? mb_substr($cleanDesc, 0, 255) : $key;
        try {
            $model = Service::create([
                'code' => $key,
                'name' => $name,
                'description' => $cleanDesc !== '' ? $cleanDesc : null,
                'status' => 'active',
            ]);
        } catch (\Illuminate\Database\QueryException) {
            $model = Service::whereRaw('UPPER(code) = ?', [$key])->first();
            if (! $model) {
                throw new \RuntimeException('Gagal membuat master Service "'.$key.'".');
            }
        }
        $this->services[$key] = $model;
        unset($this->pendingServices[$key]);

        return $model;
    }

    public function ensureServiceClass(?string $code, ?string $name = null): ?ServiceClass
    {
        $this->preload();
        $key = mb_strtoupper(trim((string) $code));
        $cleanName = trim((string) $name);
        if ($cleanName === '-') {
            $cleanName = '';
        }
        if ($key !== '' && $key !== '-' && isset($this->classes[$key])) {
            return $this->classes[$key];
        }
        if ($key === '' || $key === '-') {
            $upper = mb_strtoupper($cleanName);
            if ($upper !== '' && isset($this->classesByName[$upper])) {
                return $this->classesByName[$upper];
            }

            return null;
        }
        $upperName = mb_strtoupper($cleanName);
        if ($upperName !== '' && isset($this->classesByName[$upperName])) {
            return $this->classesByName[$upperName];
        }
        $existing = ServiceClass::whereRaw('UPPER(code) = ?', [$key])->first();
        if ($existing) {
            $this->classes[$key] = $existing;
            $this->classesByName[mb_strtoupper(trim($existing->name))] = $existing;

            return $existing;
        }
        $finalName = $cleanName !== '' ? mb_substr($cleanName, 0, 255) : $key;
        try {
            $model = ServiceClass::create(['code' => $key, 'name' => $finalName, 'status' => 'active']);
        } catch (\Illuminate\Database\QueryException) {
            $model = ServiceClass::whereRaw('UPPER(code) = ?', [$key])->first();
            if (! $model) {
                throw new \RuntimeException('Gagal membuat master Kelas "'.$key.'".');
            }
        }
        $this->classes[$key] = $model;
        $this->classesByName[mb_strtoupper(trim($model->name))] = $model;
        unset($this->pendingClasses[$key]);

        return $model;
    }

    /**
     * Tulis semua master yang direncanakan saat SCAN ke DB sekaligus
     * (dipakai commit agar tidak N+1). Aman dipanggil berulang.
     */
    public function flushPending(): void
    {
        foreach ($this->pendingProviders as $key => $p) {
            $this->ensureProvider($p['code'], $p['name']);
        }
        foreach ($this->pendingServices as $key => $p) {
            $this->ensureService($p['code'], $p['description'] ?? $p['name']);
        }
        foreach ($this->pendingClasses as $key => $p) {
            $this->ensureServiceClass($p['code'], $p['name']);
        }
        $this->pendingProviders = [];
        $this->pendingServices = [];
        $this->pendingClasses = [];
    }

    public function pendingCounts(): array
    {
        return [
            'providers' => count($this->pendingProviders),
            'services' => count($this->pendingServices),
            'classes' => count($this->pendingClasses),
        ];
    }

    public function resetPending(): void
    {
        $this->pendingProviders = [];
        $this->pendingServices = [];
        $this->pendingClasses = [];
    }

    // -----------------------------------------------------------------
    // Kandidat antar-request (scan bertahap via AJAX): resolver hidup
    // per-request, jadi kandidat unik harus dititipkan di $state cache
    // lalu disuntik kembali. Tanpa ini slice kedua akan mengulang
    // perhitungan unik dari nol.
    // -----------------------------------------------------------------

    /** @return array{providers: array, services: array, classes: array} */
    public function exportCandidates(): array
    {
        return [
            'providers' => $this->pendingProviders,
            'services' => $this->pendingServices,
            'classes' => $this->pendingClasses,
        ];
    }

    /** @param array{providers?: array, services?: array, classes?: array} $candidates */
    public function importCandidates(array $candidates): void
    {
        $this->preload();
        foreach (['providers' => 'pendingProviders', 'services' => 'pendingServices', 'classes' => 'pendingClasses'] as $key => $prop) {
            foreach ((array) ($candidates[$key] ?? []) as $codeKey => $attrs) {
                if (! is_array($attrs) || ! isset($attrs['code'])) {
                    continue;
                }
                $upper = mb_strtoupper(trim((string) $attrs['code']));
                if ($upper === '' || isset($this->{$prop}[$upper])) {
                    continue;
                }
                $this->{$prop}[$upper] = $attrs;
            }
        }
    }

    /**
     * Daftar kandidat unik untuk preview (sudah dedup per business key).
     * @return array{providers: array<int, array{code:string,name:string}>, services: array, classes: array}
     */
    public function candidateLists(int $limit = 100): array
    {
        $take = function (array $pending) use ($limit): array {
            $list = array_values($pending);
            usort($list, fn ($a, $b) => strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? '')));

            return array_slice($list, 0, $limit);
        };

        return [
            'providers' => $take($this->pendingProviders),
            'services' => $take($this->pendingServices),
            'classes' => $take($this->pendingClasses),
        ];
    }

    public function candidateCounts(): array
    {
        return [
            'providers' => count($this->pendingProviders),
            'services' => count($this->pendingServices),
            'classes' => count($this->pendingClasses),
        ];
    }

    // -----------------------------------------------------------------
    // COMMIT: persist kandidat satu kali secara batch dalam transaksi.
    // Dipanggil sekali per chunk: 1x SELECT + 1x INSERT per tipe master,
    // bukan 1 query per row. Aman dari race via unique constraint.
    // -----------------------------------------------------------------

    /** @param array<string, array{code:string,name:string}> $candidates */
    public function ensureManyProviders(array $candidates): void
    {
        $this->preload();
        $missing = [];
        foreach ($candidates as $key => $attrs) {
            $upper = mb_strtoupper(trim((string) ($attrs['code'] ?? $key)));
            if ($upper === '' || isset($this->providers[$upper])) {
                continue;
            }
            $missing[$upper] = $attrs;
        }
        if ($missing === []) {
            return;
        }
        $existing = Provider::whereIn('code', array_keys($missing))->get();
        foreach ($existing as $model) {
            $upper = mb_strtoupper(trim($model->code));
            $this->providers[$upper] = $model;
            unset($missing[$upper]);
        }
        // Cek case-insensitive untuk DB collation case-sensitive.
        if ($missing !== []) {
            $ci = Provider::whereRaw('UPPER(code) IN ('.implode(',', array_fill(0, count($missing), '?')).')', array_keys($missing))->get();
            foreach ($ci as $model) {
                $upper = mb_strtoupper(trim($model->code));
                $this->providers[$upper] = $model;
                unset($missing[$upper]);
            }
        }
        if ($missing === []) {
            return;
        }
        $now = now();
        $rows = [];
        foreach ($missing as $upper => $attrs) {
            $name = trim((string) ($attrs['name'] ?? ''));
            if ($name === '' || $name === '-') {
                $name = $upper;
            }
            $rows[] = ['code' => $upper, 'name' => mb_substr($name, 0, 255), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
        }
        try {
            Provider::insertOrIgnore($rows);
        } catch (\Throwable) {
            // Fallback per-row bila driver tidak dukung insertOrIgnore.
            foreach ($rows as $row) {
                try {
                    Provider::firstOrCreate(['code' => $row['code']], $row);
                } catch (\Throwable) {
                }
            }
        }
        foreach (Provider::whereIn('code', array_keys($missing))->get() as $model) {
            $this->providers[mb_strtoupper(trim($model->code))] = $model;
        }
        foreach (array_keys($missing) as $upper) {
            unset($this->pendingProviders[$upper]);
        }
    }

    /** @param array<string, array{code:string,name:string,description:?string}> $candidates */
    public function ensureManyServices(array $candidates): void
    {
        $this->preload();
        $missing = [];
        foreach ($candidates as $key => $attrs) {
            $upper = mb_strtoupper(trim((string) ($attrs['code'] ?? $key)));
            if ($upper === '' || isset($this->services[$upper])) {
                continue;
            }
            $missing[$upper] = $attrs;
        }
        if ($missing === []) {
            return;
        }
        foreach (Service::whereIn('code', array_keys($missing))->get() as $model) {
            $upper = mb_strtoupper(trim($model->code));
            $this->services[$upper] = $model;
            unset($missing[$upper]);
        }
        if ($missing !== []) {
            $ci = Service::whereRaw('UPPER(code) IN ('.implode(',', array_fill(0, count($missing), '?')).')', array_keys($missing))->get();
            foreach ($ci as $model) {
                $upper = mb_strtoupper(trim($model->code));
                $this->services[$upper] = $model;
                unset($missing[$upper]);
            }
        }
        if ($missing === []) {
            return;
        }
        $now = now();
        $rows = [];
        foreach ($missing as $upper => $attrs) {
            $desc = trim((string) ($attrs['description'] ?? $attrs['name'] ?? ''));
            if ($desc === '' || $desc === '-') {
                $desc = '';
            }
            $rows[] = [
                'code' => $upper,
                'name' => $desc !== '' ? mb_substr($desc, 0, 255) : $upper,
                'description' => $desc !== '' ? $desc : null,
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        try {
            Service::insertOrIgnore($rows);
        } catch (\Throwable) {
            foreach ($rows as $row) {
                try {
                    Service::firstOrCreate(['code' => $row['code']], $row);
                } catch (\Throwable) {
                }
            }
        }
        foreach (Service::whereIn('code', array_keys($missing))->get() as $model) {
            $this->services[mb_strtoupper(trim($model->code))] = $model;
        }
        foreach (array_keys($missing) as $upper) {
            unset($this->pendingServices[$upper]);
        }
    }

    /** @param array<string, array{code:string,name:string}> $candidates */
    public function ensureManyClasses(array $candidates): void
    {
        $this->preload();
        $missing = [];
        foreach ($candidates as $key => $attrs) {
            $upper = mb_strtoupper(trim((string) ($attrs['code'] ?? $key)));
            if ($upper === '' || isset($this->classes[$upper])) {
                continue;
            }
            $missing[$upper] = $attrs;
        }
        if ($missing === []) {
            return;
        }
        foreach (ServiceClass::whereIn('code', array_keys($missing))->get() as $model) {
            $upper = mb_strtoupper(trim($model->code));
            $this->classes[$upper] = $model;
            $this->classesByName[mb_strtoupper(trim($model->name))] = $model;
            unset($missing[$upper]);
        }
        if ($missing !== []) {
            $ci = ServiceClass::whereRaw('UPPER(code) IN ('.implode(',', array_fill(0, count($missing), '?')).')', array_keys($missing))->get();
            foreach ($ci as $model) {
                $upper = mb_strtoupper(trim($model->code));
                $this->classes[$upper] = $model;
                $this->classesByName[mb_strtoupper(trim($model->name))] = $model;
                unset($missing[$upper]);
            }
        }
        if ($missing === []) {
            return;
        }
        $now = now();
        $rows = [];
        foreach ($missing as $upper => $attrs) {
            $name = trim((string) ($attrs['name'] ?? ''));
            if ($name === '' || $name === '-') {
                $name = $upper;
            }
            $rows[] = ['code' => $upper, 'name' => mb_substr($name, 0, 255), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
        }
        try {
            ServiceClass::insertOrIgnore($rows);
        } catch (\Throwable) {
            foreach ($rows as $row) {
                try {
                    ServiceClass::firstOrCreate(['code' => $row['code']], $row);
                } catch (\Throwable) {
                }
            }
        }
        foreach (ServiceClass::whereIn('code', array_keys($missing))->get() as $model) {
            $upper = mb_strtoupper(trim($model->code));
            $this->classes[$upper] = $model;
            $this->classesByName[mb_strtoupper(trim($model->name))] = $model;
        }
        foreach (array_keys($missing) as $upper) {
            unset($this->pendingClasses[$upper]);
        }
    }

    /**
     * Cek konsistensi nama Excel vs master (untuk WARNING, bukan error).
     */
    public static function nameMismatch(?string $excel, ?string $master): bool
    {
        $a = mb_strtolower(trim((string) $excel));
        $b = mb_strtolower(trim((string) $master));

        return $a !== '' && $b !== '' && $a !== $b;
    }

    public function providerCount(): int
    {
        $this->preload();

        return count($this->providers);
    }

    public function serviceCount(): int
    {
        $this->preload();

        return count($this->services);
    }

    public function classCount(): int
    {
        $this->preload();

        return count($this->classes);
    }
}
