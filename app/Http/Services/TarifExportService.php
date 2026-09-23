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
     * @param  iterable<int, Tarif>  $tarifs
     */
    public static function toRow(Tarif $tarif): array
    {
        return [
            $tarif->jenisTarif->name ?? '-',
            $tarif->service->code ?? '-',
            $tarif->service->name ?? '-',
            $tarif->service->description ?? '-',
            $tarif->provider->code ?? '-',
            $tarif->provider->name ?? '-',
            $tarif->serviceClass->code ?? '-',
            $tarif->serviceClass->name ?? '-',
            $tarif->surgery_type ?? '-',
            $tarif->helper ?? '-',
            $tarif->tariff,
            $tarif->valid_date_from->format('Y-m-d'),
            $tarif->end_date_to->format('Y-m-d'),
            $tarif->status,
        ];
    }

    public static function headings(): array
    {
        return ['Jenis', 'Service Code', 'Service Name', 'Service Description', 'Provider Code', 'Provider Name', 'Kode Kelas', 'Kelas', 'Surgery Type', 'Helper', 'Tarif', 'Berlaku Dari', 'Berlaku Sampai', 'Status'];
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
