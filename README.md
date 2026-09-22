# Laravel Starterpack: Auth, Role & Permission

Starterpack Laravel ini menyediakan fitur otentikasi (login/register/logout) serta manajemen role dan permission berbasis middleware. Cocok untuk memulai project Laravel yang membutuhkan sistem user management dan kontrol akses.

## Fitur

- **Authentication**: Login, Register, Logout
- **Manajemen User**: CRUD user, filter & pencarian
- **Role & Permission**: CRUD role & permission, assign ke user
- **Proteksi Route**: Middleware berbasis permission
- **UI Sederhana**: Blade + Tailwind, siap dikembangkan
- **Code Quality**: PHPStan 2.x + Larastan 3.x (level 5, `phpstan.neon`)

## Instalasi

1. **Clone repository**
    ```
    git clone <repo-url>
    cd <nama-folder>
    ```

2. **Install dependency**
    ```
    composer install
    npm install && npm run dev
    ```

3. **Copy file environment**
    ```
    cp .env.example .env
    ```

4. **Generate key**
    ```
    php artisan key:generate
    ```

5. **Atur koneksi database**  
    Edit `.env` dan sesuaikan DB_DATABASE, DB_USERNAME, DB_PASSWORD.

6. **Migrasi & seeder**
    ```
    php artisan migrate --seed
    ```

7. **Jalankan server**
    ```
    php artisan serve
    ```

## Akun Default

Setelah seeding, tersedia akun admin:
- **Email**: `admin@example.com`
- **Password**: `password` (ubah di database jika perlu)

## Struktur Fitur

- **routes/web.php**  
  Routing otentikasi, dashboard, user, role, permission, dengan proteksi middleware.
- **database/seeders/DatabaseSeeder.php**  
  Seeder untuk role, permission, dan user admin.
- **resources/views/users/index.blade.php**  
  Halaman kelola user, filter, dan aksi.
- **resources/views/components/table-filter.blade.php**  
  Komponen filter tabel (search, date, reset, dsb).

## Manajemen Role & Permission

- Tambahkan/ubah role & permission di menu Role dan Permission.
- Assign role ke user saat create/edit user.
- Middleware:  
  - `permission:manage_users`  
  - `permission:manage_roles`  
  - `permission:manage_permissions`

## Kustomisasi

- Tambahkan field user, role, atau permission sesuai kebutuhan.
- Modifikasi tampilan di folder `resources/views/`.
- Tambahkan fitur lain sesuai kebutuhan project.

## Analisa Statis (PHPStan + Larastan)

Starterpack sudah terkonfigurasi **PHPStan 2.x** + **Larastan 3.x** untuk Laravel 12 (level 5, 0 errors).

**Konfigurasi:** `phpstan.neon` (include `vendor/larastan/larastan/extension.neon`)
```neon
parameters:
  level: 5 # naik bertahap 5 -> 6 -> 8 -> 10
  paths: [app, config, database, routes]
```

**Perintah:**
```bash
composer phpstan              # analisa (vendor/bin/phpstan analyse --memory-limit=1G)
composer phpstan:baseline     # generate phpstan-baseline.neon (lalu uncomment di phpstan.neon)
.\vendor\bin\phpstan analyse --level=8  # coba level lebih ketat
```

**Workflow baseline (untuk legacy/CI):**
1. Jalankan `composer phpstan:baseline` saat ada error yang belum sempat di-fix.
2. Uncomment `- phpstan-baseline.neon` di `includes` pada `phpstan.neon`.
3. Commit baseline, CI tetap green sambil cicil fix.

**Catatan:**
- Model (`User`, `Role`, `Permission`, `OrganizationUnit`, `OrganizationType`) sudah diberi return type `BelongsTo<>`, `HasMany<>`, `BelongsToMany<>` agar magic property Larastan tidak `property.notFound`.
- `trait.unused` dan `nullsafe.neverNull` sudah di-fix di `ActivityLogService.php` & `OrganizationUnitService.php`.
- Naikkan `level` setelah codebase stabil, lihat https://phpstan.org/user-guide/rule-levels

## Kontribusi

Pull request & issue sangat terbuka untuk pengembangan starterpack ini.

---

**Happy Coding!**
