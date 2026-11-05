<?php

namespace App\Services\Bridge;

use App\Services\Bridge\NotFound\RoomChargeQueryRule;

/**
 * Sinkronisasi kolom kelas untuk baris kamar (Room Charge / Kamar
 * Perawatan). Satu file dianggap satu kelas: level yang terbaca dari
 * service description dibakukan ke SELURUH baris — kolom KELAS maupun
 * SERVICECODE KELAS sekaligus. Kolom KELAS memakai nama kelas MASTER
 * (bukan label level), agar konsisten dengan database (mis. level
 * "SUITE ROOM" -> master "SUITE").
 */
class RoomChargeClassService
{
    public function __construct(protected TarifBridgeRepository $repository) {}

  
    public function apply(array &$decisions, array &$preview, array $normalizedList): ?array
    {
        $class = $this->resolve($normalizedList, $decisions);
        if ($class === null) {
            return null;
        }

        foreach ($decisions as $excelRow => $decision) {
            $decisions[$excelRow]['new_class_code'] = $class['code'];
            $decisions[$excelRow]['new_class_name'] = $class['name'];
        }
        foreach ($preview as $i => $row) {
            $key = (int) ($row['excel_row'] ?? 0);
            if (! isset($decisions[$key]['new_class_name'])) {
                continue;
            }
            $preview[$i]['new_class_code'] = $decisions[$key]['new_class_code'];
            $preview[$i]['new_class_name'] = $decisions[$key]['new_class_name'];
        }

        return $class;
    }


    public function resolve(array $normalizedList, array $decisions): ?array
    {
        foreach ($normalizedList as $normalized) {
            $excelRow = (int) ($normalized['excel_row'] ?? 0);
            $description = trim((string) ($decisions[$excelRow]['new_service_description'] ?? ''));
            if ($description === '') {
                $description = trim((string) ($normalized['service_description'] ?? ''));
            }
            if (! RoomChargeQueryRule::isRoomCharge($description)) {
                continue;
            }
            $level = RoomChargeQueryRule::levelFrom($description);
            if ($level === null) {
                continue;
            }
            $classCode = $this->repository->classCodeForLevel($level);
            if ($classCode === null) {
                continue;
            }

            // Nama kelas dari master (bukan label level) agar kolom KELAS
            // hasil sinkronisasi konsisten dengan master database
            // (mis. level "SUITE ROOM" -> master "SUITE").
            $className = $this->repository->classNameForCode($classCode) ?? $level;

            return ['code' => $classCode, 'name' => $className];
        }

        return null;
    }
}
