<?php

namespace App\Imports;

use App\Http\Services\TarifImportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;

/**
 * Import commit via chunk reading (1000 row/chunk) agar file 13.000 row
 * stabil. Header dipetakan oleh TarifImportColumnMapper milik service —
 * import class ini hanya orkestrasi baca-tulis per chunk.
 *
 * Dipakai oleh TarifImportController@commit melalui Excel::import().
 */
class TarifChunkImport implements ToCollection, WithChunkReading
{
    protected ?array $headerMap = null;

    /** @var array<string, bool> */
    protected array $seenKeys = [];

    /** @var array<string, bool> */
    protected array $existingKeys = [];

    public int $inserted = 0;

    public int $skippedError = 0;

    public int $skippedDuplicate = 0;

    public int $processedRows = 0;

    /**
     * Hook opsional per chunk (dipakai queue job untuk update progress
     * batch tanpa mengubah mekanisme chunking). Dipanggil dengan $this.
     *
     * @var callable|null
     */
    public $onChunk = null;

    public function __construct(
        protected TarifImportService $service,
        protected int $jenisTarifId,
    ) {}

    public function chunkSize(): int
    {
        return TarifImportService::CHUNK_SIZE;
    }

    public function collection(Collection $rows): void
    {
        $this->service->importChunk($rows, $this->jenisTarifId, $this->headerMap, $this->seenKeys, $this->existingKeys, $this);

        if (is_callable($this->onChunk)) {
            ($this->onChunk)($this);
        }
    }
}
