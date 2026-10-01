<?php

namespace App\Services\Bridge\NotFound;


final class KamarSiblingRule
{

    public static function promote(array $ranked, ?string $roleHint, array $queryTokens): array
    {
        if ($roleHint !== 'kamar' || $queryTokens !== [] || count($ranked) < 2) {
            return $ranked;
        }
        $topCode = mb_strtoupper(trim((string) ($ranked[0]['service_code'] ?? '')));
        if ($topCode === '') {
            return $ranked;
        }
        $kamarFirst = [];
        $rest = [];
        foreach ($ranked as $row) {
            if (mb_strtoupper(trim((string) ($row['service_code'] ?? ''))) === $topCode
                && ($row['class_code'] ?? null) !== null
                && NotFoundResolver::detectRole((string) ($row['service_description'] ?? '')) === 'kamar'
            ) {
                $kamarFirst[] = $row;
            } else {
                $rest[] = $row;
            }
        }

        return $kamarFirst !== [] ? array_merge($kamarFirst, $rest) : $ranked;
    }
}
