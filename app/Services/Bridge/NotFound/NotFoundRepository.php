<?php

namespace App\Services\Bridge\NotFound;

use App\Services\Bridge\TarifBridgeRepository;

/**
 * Infrastructure layer NOT_FOUND (kerangka — belum dipakai).
 *
 * TODO(user): strategi NOT_FOUND masih dipikirkan. Kandidat implementasi:
 *  - fuzzy description (mirip BridgeServiceSearch tapi per grup),
 *  - tarif terdekat dalam kelas yang sama,
 *  - riwayat resolusi manual sebelumnya.
 * Sampai diputuskan, class ini hanya membungkus base repository tanpa
 * query tambahan.
 */
final class NotFoundRepository
{
    public function __construct(protected TarifBridgeRepository $base) {}

    public function preload(): self
    {
        $this->base->preload();

        return $this;
    }
}
