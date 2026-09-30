<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider default Bridge Tarif
    |--------------------------------------------------------------------------
    |
    | Kolom PROVID / PROVIDER_NAME yang masih kosong di file upload akan
    | diisi nilai ini saat generate. Sel yang sudah terisi tidak diubah.
    |
    */

    'provider_code' => env('BRIDGE_PROVIDER_CODE', 'OAZRA0-000'),
    'provider_name' => env('BRIDGE_PROVIDER_NAME', 'RS AZRA'),

    /*
    |--------------------------------------------------------------------------
    | Pengecualian bendera RUANG BEDAH (OK / NON OK)
    |--------------------------------------------------------------------------
    |
    | Deskripsi yang memuat kata "tindakan"/"bedah" menjadi OK, KECUALI
    | bila memuat salah satu kata di bawah ini (nama alat/bahan, mis.
    | "Pisau Bedah") — perbandingan whole-word, case-insensitive.
    | Tambah kata baru di sini tanpa menyentuh kode.
    |
    */

    'surgery' => [
        'excluded_words' => [
            'pisau',
            'foto',
            'benang',
            'gunting',
            'pinset',
            'klem',
            'jarum',
            'hecting',
            'catgut',
            'masker',
            'baju',
            'topi',
            'doek',
            'sarung',
            'handscoen',
            'handschoen',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Kamus pencarian NOT_FOUND (jangka panjang: edit di sini, bukan kode)
    |--------------------------------------------------------------------------
    |
    | Seluruh perilaku word-based BridgeServiceSearch / NotFoundResolver
    | dibaca dari kamus ini dengan fallback default di masing-masing
    | class. Kasus baru (typo, sinonim, spesialisasi, stopword) cukup
    | ditambah di sini — tanpa mengubah logika di app/Services/Bridge.
    |
    */

    'search' => [

        // Kata taksonomi master yang tidak membedakan tindakan.
        'stopwords' => [
            'golongan', 'tindakan', 'medis', 'besar', 'khusus',
            'kecil', 'sedang', 'umum', 'layanan', 'jasa',
        ],

        // Query -> istilah master (typo, ejaan Inggris, metode anestesi).
        'clinical_aliases' => [
            'varicocelectomy' => 'varicocele',
            'anasthesy' => 'anestesi',
            'anasthesi' => 'anestesi',
            'anesthesia' => 'anestesi',
            'anesthesy' => 'anestesi',
            'ortohopedi' => 'ortopedi',
            'sedasi' => 'anestesi',
            'narkose' => 'anestesi',
            'bius' => 'anestesi',
        ],

        // Dikeluarkan dari recall SAJA (membanjiri pool 500); scoring
        // tetap memakai token penuh.
        'recall_excluded_role' => [
            'anestesi', 'anasthesy', 'anasthesi', 'anesthesia', 'anesthesy',
            'anastesi', 'narkose', 'sedasi',
        ],

        // Penanda personel: dipotong HANYA sebagai kualifikasi akhir
        // (setelah koma/dash). Di tengah frasa ("Konsultasi Dokter Umum")
        // dipertahankan karena bagian nama tindakan.
        'personnel_noise' => [
            'narkose', 'sedasi', 'bius', 'dokter', 'operator',
            'bidan', 'spesialis', 'dpjp', 'konsulen',
        ],

        // Pola specialty [pattern, kanonis], urutan penting (frasa dulu).
        // "umum" hanya via frasa "bedah umum" agar "dokter umum" (role)
        // tidak terbaca sebagai spesialisasi.
        'specialties' => [
            ['/bedah\s+anak/iu', 'anak'],
            ['/\banak\b/iu', 'anak'],
            ['/bedah\s+umum/iu', 'umum'],
            ['/urologi/iu', 'urologi'],
            ['/jantung|cardio|kardiovaskular/iu', 'jantung'],
            ['/saraf|neuro/iu', 'saraf'],
            ['/mata|ophthalm/iu', 'mata'],
            ['/obgyn|kandungan|obstetri|ginekologi/iu', 'obgyn'],
            ['/ortopedi|orthopedi|ortohopedi/iu', 'ortopedi'],
            ['/\btulang\b/iu', 'ortopedi'],
            ['/digestif/iu', 'digestif'],
            ['/plastik/iu', 'plastik'],
            ['/paru|thorax|toraks/iu', 'paru'],
            ['/ginjal|nefro/iu', 'ginjal'],
            ['/\btht\b|telinga|hidung|tenggorokan/iu', 'tht'],
            ['/gigi|dental|\bmulut\b/iu', 'gigi'],
            ['/kulit|kelamin|dermato|venereologi/iu', 'kulit'],
        ],

        // Pola peran berurutan: kamar > operator > anestesi.
        'roles' => [
            'kamar' => '/\bkamar\s+operasi\b|\bruang\s+operasi\b|\bruang\s+bedah\b|\bsarana\b/u',
            'operator' => '/\boperator\b/u',
            'anestesi' => '/\banestesi\b|\banasthesy\b|\banasthesi\b|\banesthesia\b|\banesthesy\b|\banastesi\b|\bnarkose\b|\bsedasi\b/u',
        ],

        // Kata generik yang dibuang saat membangun query prosedur
        // jangkar kamar (suggestKamarSibling).
        'sibling_generic' => [
            'golongan', 'tindakan', 'medis', 'besar', 'khusus',
            'kecil', 'sedang', 'umum', 'layanan', 'jasa',
            'operasi', 'bedah', 'kamar', 'ruang', 'dokter', 'sarana',
            'biaya', 'sewa', 'charge', 'paket', 'pemakaian',
        ],

    ],

];
