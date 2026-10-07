<?php

namespace App\Domain\Qa;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class QaResponsibility
{
    public static function contextKey(string $taskVersion, string $occurredAt, ?string $identityId): string
    {
        return hash('sha256', QaContract::canonical([$taskVersion, $occurredAt, $identityId]));
    }

    public function captureLocked(string $project, string $taskVersion, string $occurredAt, ?string $identityId): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.qa_authority_closed',true),'')") !== '1') {
            throw new LogicException('Responsibility requires prelocked inputs.');
        }
        $data = json_decode(DB::scalar("select nullif(current_setting('app.qa_responsibility',true),'')") ?? '{}', true);
        $unknown = ['attribution' => 'unknown', 'reason_code' => 'historical_proof_missing', 'employee_id' => null, 'task_version_id' => $taskVersion, 'occurred_at' => $occurredAt];
        if (($data['project_id'] ?? '') !== $project) {
            return $unknown;
        }
        $key = self::contextKey($taskVersion, $occurredAt, $identityId);
        if (! isset($data['contexts'][$key])) {
            return array_replace($unknown, ['reason_code' => 'historical_context_unknown']);
        }
        if (isset($data['overflow'][$key])) {
            return array_replace($unknown, ['reason_code' => 'coverage_limit']);
        }
        $task = collect($data['tasks'])->firstWhere('id', $taskVersion);
        if (! $task) {
            return $unknown;
        }
        try {
            $at = CarbonImmutable::parse($occurredAt);
        } catch (\Throwable) {
            return $unknown;
        }
        if (! ($task['interval_known'] ?? false) || CarbonImmutable::parse($task['effective_at'])->greaterThan($at) || (isset($task['effective_until']) && CarbonImmutable::parse($task['effective_until'])->lessThanOrEqualTo($at))) {
            return $unknown;
        }
        $effective = fn ($r) => CarbonImmutable::parse($r['valid_from'])->lessThanOrEqualTo($at) && ($r['valid_to'] === null || CarbonImmutable::parse($r['valid_to'])->greaterThan($at));
        $employee = $task['assignee_id'];
        $mapping = null;
        if ($identityId !== null) {
            $mappings = collect($data['mappings'])->filter(fn ($m) => $m['identity_id'] === $identityId && $m['status'] === 'approved' && $effective($m))->values();
            if ($mappings->count() !== 1 || $mappings[0]['employee_id'] !== $employee) {
                return $unknown + ['identity_id' => $identityId];
            }
            $mapping = $mappings[0];
        }
        $members = collect($data['memberships'])->filter(fn ($m) => $m['employee_id'] === $employee && $effective($m))->values();
        $roles = collect($data['roles'])->filter(fn ($r) => $r['employee_id'] === $employee && $effective($r))->values();
        if ($members->count() !== 1 || $roles->isEmpty() || $roles->count() > 100) {
            return $unknown;
        }
        $fields = fn ($row) => array_intersect_key($row, array_flip(['id', 'revision', 'employee_id', 'role', 'identity_id', 'valid_from', 'valid_to', 'status']));
        $snapshot = ['attribution' => 'known', 'reason_code' => null, 'employee_id' => $employee, 'task_version_id' => $taskVersion, 'task_revision' => $task['revision'], 'task_hash' => $task['content_hash'], 'occurred_at' => $occurredAt, 'membership' => $fields($members[0]), 'roles' => $roles->map($fields)->all(), 'mapping' => $mapping === null ? null : $fields($mapping)];
        $snapshot['input_hash'] = hash('sha256', QaContract::canonical($snapshot));

        return $snapshot;
    }

    public function elapsedAge(string $openedAt, string $dbInstant): array
    {
        $base = ['age_basis' => 'elapsed_utc', 'business_age' => null, 'business_age_reason' => 'calendar_unavailable'];
        try {
            foreach ([$openedAt, $dbInstant] as $instant) {
                if (! preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)\z/D', $instant)) {
                    throw new \InvalidArgumentException;
                }
                new \DateTimeImmutable($instant);
                if (\DateTimeImmutable::getLastErrors() !== false) {
                    throw new \InvalidArgumentException;
                }
            }
            $opened = CarbonImmutable::parse($openedAt);
            $now = CarbonImmutable::parse($dbInstant);
            if ($opened->greaterThan($now)) {
                return $base + ['elapsed_seconds' => null, 'reason_code' => 'future_timestamp'];
            }

            return $base + ['elapsed_seconds' => (int) floor($opened->diffInSeconds($now)), 'reason_code' => null];
        } catch (\Throwable) {
            return $base + ['elapsed_seconds' => null, 'reason_code' => 'invalid_timestamp'];
        }
    }
}
