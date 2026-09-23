<?php

namespace App\Exports;

use App\Http\Services\TarifExportService;
use App\Models\Tarif;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Export XLSX memakai filter yang sama dengan halaman index
 * (query logic milik TarifService via TarifExportService).
 */
class TarifExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping
{
    use Exportable;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(protected array $filters = []) {}

    public function query()
    {
        return Tarif::with(['jenisTarif', 'provider', 'service', 'serviceClass'])
            ->when(! empty($this->filters['search']), function ($q) {
                $search = $this->filters['search'];
                $q->where(function ($qq) use ($search) {
                    $qq->where('helper', 'like', "%{$search}%")
                        ->orWhereHas('service', fn ($sq) => $sq->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                        ->orWhereHas('provider', fn ($pq) => $pq->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
                });
            })
            ->when(! empty($this->filters['jenis_tarif_id']), fn ($q) => $q->where('jenis_tarif_id', $this->filters['jenis_tarif_id']))
            ->when(! empty($this->filters['provider_id']), fn ($q) => $q->where('provider_id', $this->filters['provider_id']))
            ->when(! empty($this->filters['service_id']), fn ($q) => $q->where('service_id', $this->filters['service_id']))
            ->when(! empty($this->filters['class_id']), fn ($q) => $q->where('class_id', $this->filters['class_id']))
            ->when(! empty($this->filters['surgery_type']), fn ($q) => $q->where('surgery_type', $this->filters['surgery_type']))
            ->orderBy('created_at', 'desc');
    }

    /**
     * @param  Tarif  $tarif
     */
    public function map($tarif): array
    {
        return TarifExportService::toRow($tarif);
    }

    public function headings(): array
    {
        return TarifExportService::headings();
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
