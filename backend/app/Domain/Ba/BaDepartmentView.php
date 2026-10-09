<?php

namespace App\Domain\Ba;

final class BaDepartmentView
{
    public function filterLocked(array $items, array $proofs, array $namedRefs): array
    {
        $current = $proofs['current'] ?? [];
        if (! ($current['valid'] ?? false)) {
            return [];
        }
        $department = $current['proof']['department_id'] ?? null;

        return array_values(array_filter($items, function ($item) use ($current, $department, $namedRefs) {
            foreach ($namedRefs[$item['clarification_id']] ?? [] as $id) {
                if (! isset($current['owners'][$id]) || ($department !== null && ! isset($current['department_subjects'][$id]))) {
                    return false;
                }
            }

            return true;
        }));
    }
}
