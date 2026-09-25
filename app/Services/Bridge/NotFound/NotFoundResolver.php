<?php

namespace App\Services\Bridge\NotFound;

/**
 * Application layer NOT_FOUND.
 *
 * Resolver ini sengaja TIDAK menebak service (strategi otomatis masih
 * dipikirkan user): selalu kembalikan manual() agar alur lama (pilih
 * manual via BridgeServiceSearch) tetap berlaku. Tugasnya saat ini
 * adalah menjelaskan HASIL PENGECEKAN untuk modal detail analisa
 * per baris via explain().
 */
final class NotFoundResolver
{
    public function __construct(protected NotFoundRepository $repository) {}

    public function repository(): NotFoundRepository
    {
        return $this->repository;
    }

    /** @param  array<string, mixed>  $normalized */
    public function resolve(array $normalized): NotFoundResult
    {
        return NotFoundResult::manual();
    }

    /**
     * Penjelasan analisa (Indonesia) untuk modal detail per baris:
     * apa yang dicek, bagaimana cara cocoknya, dan langkah manualnya.
     *
     * @param  array<string, mixed>  $normalized  hasil RowNormalizer
     */
    public function explain(array $normalized, ?string $masterClassCode): string
    {
        $desc = trim((string) ($normalized['service_description'] ?? ''));
        $kelas = trim((string) ($normalized['class_name'] ?? ''));
        $key = (string) ($normalized['mapping_key'] ?? '');
        $tariff = isset($normalized['tariff']) && is_numeric($normalized['tariff'])
            ? 'Rp '.number_format((float) $normalized['tariff'], 0, ',', '.')
            : 'tidak terbaca/kosong';

        $text = "Kunci '{$key}' (description \"{$desc}\" + kelas \"{$kelas}\", setelah uppercase + rapikan spasi) tidak ditemukan persis di master Tarif, sehingga baris ini berstatus NOT_FOUND. ";
        $text .= 'Pencarian master memakai kecocokan persis terhadap nama maupun deskripsi service + nama kelas — bukan pencarian mirip. ';
        $text .= $masterClassCode !== null
            ? "Kelasnya sendiri dikenali master (kode {$masterClassCode}), jadi kolom SERVICECODE KELAS tetap bisa diperbaiki saat generate; yang hilang hanya kode service-nya. "
            : 'Kelasnya pun tidak dikenali master, sehingga kolom SERVICECODE KELAS memakai bawaan Excel. ';
        $text .= "Tarif Excel baris ini: {$tariff}. ";
        $text .= 'Kemungkinan penyebab: beda penulisan/singkatan vs master, layanan baru yang belum ada di master, atau salah kolom kelas. ';
        $text .= 'Langkah: buka Petakan Manual (pencarian service memakai kecocokan kata + tarif sebagai pembanding), pilih service yang benar — kelas ikut otomatis dari master.';

        return $text;
    }
}
