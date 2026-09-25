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
     * apa yang dicek, bagaimana cara cocoknya, tarif efektif beserta
     * sumbernya, dan langkah manualnya. Status tetap NOT_FOUND — tarif
     * efektif hanya referensi, bukan dasar pemetaan otomatis.
     *
     * @param  array<string, mixed>  $normalized  hasil RowNormalizer
     */
    public function explain(array $normalized, ?string $masterClassCode): string
    {
        $desc = trim((string) ($normalized['service_description'] ?? ''));
        $kelas = trim((string) ($normalized['class_name'] ?? ''));
        $key = (string) ($normalized['mapping_key'] ?? '');

        $text = "Kunci '{$key}' (description \"{$desc}\" + kelas \"{$kelas}\", setelah uppercase + rapikan spasi) tidak ditemukan persis di master Tarif, sehingga baris ini berstatus NOT_FOUND. ";
        $text .= 'Pencarian master memakai kecocokan persis terhadap nama maupun deskripsi service + nama kelas — bukan pencarian mirip. ';
        $text .= $masterClassCode !== null
            ? "Kelasnya sendiri dikenali master (kode {$masterClassCode}), jadi kolom SERVICECODE KELAS tetap bisa diperbaiki saat generate; yang hilang hanya kode service-nya. "
            : 'Kelasnya pun tidak dikenali master, sehingga kolom SERVICECODE KELAS memakai bawaan Excel. ';
        $text .= $this->tariffSentence($normalized).' ';
        $text .= 'Kemungkinan penyebab: beda penulisan/singkatan vs master, layanan baru yang belum ada di master, atau salah kolom kelas. ';
        $text .= 'Langkah: buka Petakan Manual dan gunakan tarif efektif di atas sebagai pembanding saat mencari service yang benar — kelas ikut otomatis dari master.';

        return $text;
    }

    /**
     * Kalimat tarif efektif sesuai sumbernya (excel / hitung /
     * grup / tak tersedia).
     *
     * @param  array<string, mixed>  $normalized
     */
    protected function tariffSentence(array $normalized): string
    {
        $excel = isset($normalized['tariff']) && is_numeric($normalized['tariff'])
            ? (float) $normalized['tariff']
            : null;
        $effective = $normalized['effective_tariff'] ?? null;
        $effective = is_numeric($effective) ? (float) $effective : null;
        $source = $normalized['tariff_source'] ?? null;
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');

        if ($effective !== null && $source === \App\Services\Bridge\BridgeTarifEffectiveTariff::SOURCE_EXCEL) {
            return "Tarif efektif menggunakan nilai TARIFF Excel sebesar {$rp($effective)}.";
        }
        if ($effective !== null && $source === \App\Services\Bridge\BridgeTarifEffectiveTariff::SOURCE_COMPUTED) {
            $total = isset($normalized['total_billed']) && is_numeric($normalized['total_billed'])
                ? $rp($normalized['total_billed']) : '-';
            $qty = $normalized['quantity'] ?? '-';
            $qtyText = is_numeric($qty) ? (string) ((float) $qty == (int) $qty ? (int) $qty : (float) $qty) : '-';

            return "Kolom TARIFF pada row ini kosong. Sistem menggunakan tarif efektif {$rp($effective)} yang dihitung dari TOTAL BILLED {$total} ÷ QUANTITY {$qtyText} sebagai referensi tarif.";
        }
        if ($effective !== null && $source === \App\Services\Bridge\BridgeTarifEffectiveTariff::SOURCE_GROUP) {
            return "Kolom TARIFF dan TOTAL BILLED ÷ QUANTITY row ini kosong. Sistem memakai tarif efektif {$rp($effective)} dari row lain dengan description + kelas yang sama sebagai referensi tarif.";
        }

        return 'Tarif tidak tersedia (TARIFF kosong dan TOTAL BILLED ÷ QUANTITY tidak bisa dihitung) sehingga pembanding tarif tidak digunakan.';
    }
}
