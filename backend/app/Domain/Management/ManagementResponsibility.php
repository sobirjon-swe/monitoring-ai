<?php

namespace App\Domain\Management;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ManagementResponsibility
{
    public function captureLocked(string $project, array $reference, string $occurredAt, ?array $assignment, array $proofs): array
    {
        $this->locked();

        return $this->calculate($project, $reference, $occurredAt, $assignment, $proofs);
    }

    /** One fence check, followed by calculations using only the captured snapshot. */
    public function captureBatchLocked(string $project, array $slots, array $rows, array $proofs): array
    {
        $this->locked();
        $results = [];
        foreach ($slots as $slot) {
            $assignment = $rows['management_assignments'][$slot['assignment_id'] ?? ''] ?? null;
            if (isset($slot['assignment_id']) && $assignment === null) {
                $results[$slot['key']] = ['attribution' => 'unknown', 'reason_code' => 'assignment_missing', 'employee_id' => null, 'owner_id' => null, 'helper_id' => null, 'task_version_id' => $slot['task_version_id'], 'occurred_at' => $slot['occurred_at']];
            } else {
                $results[$slot['key']] = $this->calculate($project, ['task_version_id' => $slot['task_version_id']], $slot['occurred_at'], $assignment, $proofs);
            }
        }

        return $results;
    }

    private function locked(): void
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.management_authority_closed',true),'')") !== '1') {
            throw new LogicException('Management responsibility requires coordination.');
        }
    }

    private function calculate(string $project, array $reference, string $occurredAt, ?array $assignment, array $proofs): array
    {
        $unknown = ['attribution' => 'unknown', 'reason_code' => 'time_unknown', 'employee_id' => null, 'owner_id' => null, 'helper_id' => null, 'task_version_id' => $reference['task_version_id'], 'occurred_at' => $occurredAt];
        $at = $this->instant($occurredAt);
        $now = $this->instant($proofs['current']['proof']['captured_at'] ?? '');
        if (! $at || ! $now || $at->greaterThan($now)) {
            return $unknown;
        }
        $key = hash('sha256', ManagementContract::canonical(['task_version_id' => $reference['task_version_id'], 'occurred_at' => $occurredAt]));
        $task = $proofs['current']['responsibilities'][$key] ?? null;
        if (($task['attribution'] ?? 'unknown') !== 'known') {
            return array_replace($unknown, ['reason_code' => $task['reason_code'] ?? 'historical_proof_missing']);
        }
        if ($assignment !== null) {
            $start = $this->instant($assignment['assigned_at'] ?? '');
            $end = isset($assignment['next_assignment_at']) ? $this->instant($assignment['next_assignment_at']) : null;
            if (($assignment['project_id'] ?? null) !== $project || ($assignment['interval_known'] ?? false) !== true || ! $start || $at->lessThan($start) || ($end && ($end->lessThan($start) || $at->greaterThanOrEqualTo($end)))) {
                return $unknown;
            }
            foreach (['owner_id', 'helper_id'] as $field) {
                $employee = $assignment[$field] ?? null;
                $saved = $assignment['authority_proof'] ?? [];
                $saved = is_string($saved) ? json_decode($saved, true, 32, JSON_THROW_ON_ERROR) : $saved;
                $named = array_values(array_filter($saved['named'] ?? [], fn ($n) => $n['employee_id'] === $employee));
                $membership = $named[0]['membership'] ?? ($employee === $task['employee_id'] ? ($task['membership'] ?? null) : null);
                $from = $membership ? $this->instant($membership['valid_from']) : null;
                $until = isset($membership['valid_to']) ? $this->instant($membership['valid_to']) : null;
                if (! $from || $at->lessThan($from) || ($until && $at->greaterThanOrEqualTo($until))) {
                    return array_replace($unknown, ['reason_code' => 'historical_proof_missing']);
                }
            }
        }

        return array_replace($unknown, $task, ['owner_id' => $assignment['owner_id'] ?? null, 'helper_id' => $assignment['helper_id'] ?? null]);
    }

    public function age(string $openedAt, ?string $episodeOpenedAt, string $now): array
    {
        $opened = $this->instant($openedAt);
        $episode = $episodeOpenedAt === null ? null : $this->instant($episodeOpenedAt);
        $clock = $this->instant($now);
        $valid = $opened && $clock && $opened->lessThanOrEqualTo($clock) && ($episodeOpenedAt === null || ($episode && $episode->greaterThanOrEqualTo($opened) && $episode->lessThanOrEqualTo($clock)));
        $seconds = $valid ? (int) $opened->diffInSeconds($clock) : null;
        $episodeSeconds = $valid && $episode ? (int) $episode->diffInSeconds($clock) : null;

        return ['elapsed_seconds' => $seconds, 'elapsed_age' => $seconds === null ? null : ['seconds' => $seconds], 'episode_opened_at' => $episodeOpenedAt, 'episode_elapsed_seconds' => $episodeSeconds, 'business_age' => null, 'reason_codes' => $valid ? ['calendar_unavailable'] : ['time_unknown', 'calendar_unavailable']];
    }

    private function instant(string $value): ?CarbonImmutable
    {
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)\z/D', $value)) {
            return null;
        }
        try {
            $at = CarbonImmutable::parse($value)->utc();
            if (! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) || (int) substr($value, 11, 2) > 23 || (int) substr($value, 14, 2) > 59 || (int) substr($value, 17, 2) > 59) {
                return null;
            }

            return $at;
        } catch (\Throwable) {
            return null;
        }
    }
}
