<?php

namespace App\Domain\Ba;

use Carbon\CarbonImmutable;

final class BaResponsibility
{
    public function captureLocked(array $link, string $occurredAt, array $proofs): array
    {
        $ref = ['task_version_id' => $link['task_version_id'], 'occurred_at' => $occurredAt];
        $key = hash('sha256', BaContract::canonical($ref));
        // Only the exact independently captured task interval is evidence of responsibility.
        foreach ($proofs as $result) {
            if (($result['valid'] ?? false) && isset($result['responsibilities'][$key])) {
                return $result['responsibilities'][$key];
            }
        }

        return ['attribution' => 'unknown', 'reason_code' => 'historical_proof_missing', 'employee_id' => null] + $ref;
    }

    public function age(array $episode, string $now): array
    {
        $seconds = null;
        try {
            if (! empty($episode['requested_at'])) {
                $start = CarbonImmutable::parse($episode['requested_at']);
                $end = CarbonImmutable::parse($episode['response_at'] ?? $now);
                $clock = CarbonImmutable::parse($now);
                if ($start->lessThanOrEqualTo($end) && $end->lessThanOrEqualTo($clock)) {
                    $seconds = (int) $start->diffInSeconds($end);
                }
            }
        } catch (\Throwable) {
            // Missing or inconsistent time never becomes zero waiting time.
        }

        return ['elapsed_seconds' => $seconds, 'elapsed_age_reason' => $seconds === null ? 'time_unknown' : null, 'business_age' => null, 'business_age_reason' => 'calendar_unavailable'];
    }
}
