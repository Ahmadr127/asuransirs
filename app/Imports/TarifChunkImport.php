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
        static $chunkNum = 0;
        $chunkNum++;
        $rowsCount = $rows->count();
        $mem = round(memory_get_usage(true)/1024/1024,1);
        $peak = round(memory_get_peak_usage(true)/1024/1024,1);
        \Illuminate\Support\Facades\Log::info("CHUNK {$chunkNum} COLLECTION START", [
            'batch'=> $this->jenisTarifId,
            'chunk' => $chunkNum,
            'rows' => $rowsCount,
            'mem_cur' => $mem,
            'mem_peak' => $peak,
            'ts' => now()->toDateTimeString(),
        ]);
        $t0 = microtime(true);
        $this->service->importChunk($rows, $this->jenisTarifId, $this->headerMap, $this->seenKeys, $this->existingKeys, $this);
        $elapsed = round(microtime(true)-$t0,2);
        $mem2 = round(memory_get_usage(true)/1024/1024,1);
        $peak2 = round(memory_get_peak_usage(true)/1024/1024,1);
        \Illuminate\Support\Facades\Log::info("CHUNK {$chunkNum} COLLECTION COMPLETE", [
            'chunk' => $chunkNum,
            'rows' => $rowsCount,
            'elapsed' => $elapsed,
            'mem_cur' => $mem2,
            'mem_peak' => $peak2,
            'processedRows' => $this->processedRows,
            'inserted' => $this->inserted,
        ]);

        if (is_callable($this->onChunk)) {
            \Illuminate\Support\Facades\Log::info("CHUNK {$chunkNum} PROGRESS UPDATE START", ['chunk'=>$chunkNum, 'processed'=>$this->processedRows]);
            $upT0 = microtime(true);
            ($this->onChunk)($this);
            \Illuminate\Support\Facades\Log::info("CHUNK {$chunkNum} PROGRESS UPDATE COMPLETE", ['chunk'=>$chunkNum, 'elapsed'=>round(microtime(true)-$upT0,2)]);
        }
        \Illuminate\Support\Facades\Log::info("CHUNK {$chunkNum} COMPLETE", ['chunk'=>$chunkNum]);
    }
}
