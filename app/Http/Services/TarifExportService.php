<?php

namespace App\Http\Services;

use App\Models\JenisTarif;
use App\Models\Tarif;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export tarif memakai filter yang sama dengan halaman index
 * (reuse TarifService::applyFilters — tidak ada duplikasi query logic
 * di controller). Mendukung CSV (existing) dan XLSX (baru).
 */
class TarifExportService
{
    public function __construct(protected TarifService $tarifService) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, Tarif>
     */
    public function getExportData(array $filters = [])
    {
        $query = Tarif::with(['jenisTarif', 'provider', 'service', 'serviceClass']);
        $this->tarifService->applyFilters($query, $filters);

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filename(array $filters, string $extension): string
    {
        $jenisCode = ! empty($filters['jenis_tarif_id'])
            ? (JenisTarif::where('id', $filters['jenis_tarif_id'])->value('code') ?? 'filter')
            : 'semua';

        return 'tarif-'.$jenisCode.'-'.now()->format('Ymd-His').'.'.$extension;
    }

    /**
     * Kolom yang tidak boleh ikut ke file export (perbandingan
     * case-insensitive terhadap nama header). Berlaku untuk XLSX
     * maupun CSV karena keduanya memakai headings()/toRow().
     */
    public const EXCLUDED_COLUMNS = ['LoS'];

    /**
     * Label kolom Surgery Type di file export: 'OK' bila nama/deskripsi
     * service memuat kata "tindakan" atau "bedah", selain itu 'NON OK'.
     * Aturan tunggal di BridgeTarifSurgeryFlag (sama dengan export bridge).
     */
    public static function surgeryLabel(?string $name, ?string $description): string
    {
        return \App\Services\Bridge\BridgeTarifSurgeryFlag::label($name, $description);
    }

    /**
     * @param  iterable<int, Tarif>  $tarifs
     */
    public static function toRow(Tarif $tarif): array
    {
        $row = [
            'Jenis' => $tarif->jenisTarif->name ?? '-',
            'Service Code' => $tarif->service->code ?? '-',
            'Service Name' => $tarif->service->name ?? '-',
            'Service Description' => $tarif->service->description ?? '-',
            'Provider Code' => $tarif->provider->code ?? '-',
            'Provider Name' => $tarif->provider->name ?? '-',
            'Kode Kelas' => $tarif->serviceClass->code ?? '-',
            'Kelas' => $tarif->serviceClass->name ?? '-',
            'Surgery Type' => self::surgeryLabel($tarif->service->name ?? null, $tarif->service->description ?? null),
            'Helper' => $tarif->helper ?? '-',
            'Tarif' => $tarif->tariff,
            'Berlaku Dari' => $tarif->valid_date_from->format('Y-m-d'),
            'Berlaku Sampai' => $tarif->end_date_to->format('Y-m-d'),
            'Status' => $tarif->status,
        ];

        return array_values(array_intersect_key($row, array_flip(self::headings())));
    }

    public static function headings(): array
    {
        return array_values(array_filter(
            ['Jenis', 'Service Code', 'Service Name', 'Service Description', 'Provider Code', 'Provider Name', 'Kode Kelas', 'Kelas', 'Surgery Type', 'Helper', 'Tarif', 'Berlaku Dari', 'Berlaku Sampai', 'Status'],
            fn ($h) => ! in_array(mb_strtolower(trim($h)), self::EXCLUDED_COLUMNS, true)
        ));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportCsv(array $filters = []): StreamedResponse
    {
        $tarifs = $this->getExportData($filters);

        return response()->streamDownload(function () use ($tarifs) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::headings());
            foreach ($tarifs as $tarif) {
                fputcsv($out, self::toRow($tarif));
            }
            fclose($out);
        }, $this->filename($filters, 'csv'), ['Content-Type' => 'text/csv']);
    }
}
