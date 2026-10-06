<?php

namespace App\Domain\DeveloperAnalysis;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AnalysisInvalidation
{
    public function __construct(private readonly TenantContext $context, private readonly AnalysisPins $pins, private readonly DeveloperAuthority $authority) {}

    /** Internal conservative maintenance only: this context never grants delivery or computation. */
    public function refresh(TrustedActor $scope, string $project, ?string $after = null): int
    {
        $count = 0;
        $ids = $this->context->run($scope->tenantId, function () use ($project, $after) {
            $query = DB::table('developer_runs')->where('project_id', $project)->where('applicability', 'current')->orderBy('id');
            if ($after !== null) {
                $query->where('id', '>', $after);
            }

            return $query->limit(100)->pluck('id')->all();
        });
        foreach ($ids as $id) {
            $count += $this->refreshRun($scope, $project, $id);
        }

        return $count;
    }

    public function refreshRun(TrustedActor $scope, string $project, string $id): int
    {
        return $this->context->run($scope->tenantId, function () use ($scope, $project, $id): int {
            if (! DB::table('projects')->where('id', $project)->lockForUpdate()->first()) {
                return 0;
            }
            DB::table('lifecycle_project_state')->where('project_id', $project)->lockForUpdate()->first();
            $hint = DB::table('developer_runs')->where('project_id', $project)->where('id', $id)->first();
            if (! $hint || $hint->applicability !== 'current') {
                return 0;
            }
            $reason = null;
            if (DB::scalar("select nullif(current_setting('app.developer_authority_closed',true),'')") !== null) {
                throw new LogicException('Internal proof refresh requires an open authority phase.');
            }
            try {
                $proof = json_decode($hint->authority, true);
                $proofs = $this->authority->captureBatch($scope, $project, [['actor_id' => $hint->requester_id, 'purposes' => $proof['purposes'], 'pinned_proof' => $proof]]);
                $this->authority->assertEffective($proofs, DB::scalar('select clock_timestamp()'));
            } catch (AccessDeniedHttpException) {
                $reason = 'access_revoked';
            }
            $reason ??= $this->reason($scope, $hint);
            if ($reason === null) {
                return 0;
            }
            $this->invalidate($scope, $project, $id, $reason, 'refresh:'.$reason);

            return 1;
        });
    }

    /** Called after project/state and authority locks, before run/outbox locks. No authority reacquisition. */
    public function observe(TrustedActor $scope, object $run, string $eventKey = 'observation'): bool
    {
        if ($run->applicability !== 'current') {
            return false;
        }
        $reason = $this->reason($scope, $run);
        if ($reason === null) {
            return false;
        }
        $this->invalidate($scope, $run->project_id, $run->id, $reason, $eventKey);

        return true;
    }

    /** Synchronous E04-only source/pin hook inside the existing trusted project transaction. */
    public function observeProject(TrustedActor $scope, string $project, string $eventKey, array $selection = []): int
    {
        $this->assertScope($scope, $project);
        $count = 0;
        $query = DB::table('developer_runs')->where('project_id', $project)->where('applicability', 'current');
        if (isset($selection['source'])) {
            $query->whereIn('snapshot_id', DB::table('developer_snapshots')->where($selection['source'])->select('id'));
        }
        foreach (['criterion_version_ids', 'task_version_ids'] as $key) {
            if (isset($selection[$key])) {
                if (! $selection[$key]) {
                    return 0;
                }
                $query->where(function ($q) use ($selection, $key): void {
                    foreach ($selection[$key] as $id) {
                        $q->orWhereJsonContains('pins->'.$key, $id);
                    }
                });
            }
        }
        // Acquire every relevant source lock before retaining any run/outbox lock in this batch.
        $heads = DB::table('developer_source_heads')->where('project_id', $project);
        if (isset($selection['source'])) {
            $heads->where($selection['source']);
        }
        $heads->orderBy('source_instance_id')->orderBy('repository_id')->orderBy('mr_id')->sharedLock()->get();
        $query->orderBy('id')->chunkById(100, function ($runs) use ($scope, $eventKey, &$count): void {
            foreach ($runs as $run) {
                $count += (int) $this->observe($scope, $run, $eventKey);
            }
        });

        return $count;
    }

    /** A denied publisher can record only a conservative invalidation in trusted tenant/project context. */
    public function revoked(TrustedActor $scope, string $project, string $run): void
    {
        $this->context->run($scope->tenantId, function () use ($scope, $project, $run): void {
            if (! DB::table('projects')->where('id', $project)->lockForUpdate()->first()) {
                return;
            }
            DB::table('lifecycle_project_state')->where('project_id', $project)->lockForUpdate()->first();
            $this->invalidate($scope, $project, $run, 'access_revoked', 'publisher:access_revoked');
        });
    }

    private function reason(TrustedActor $scope, object $run): ?string
    {
        $this->pins->lockSource($scope, $run->project_id, $run->snapshot_id);
        if (DB::table('lifecycle_project_state')->where('project_id', $run->project_id)->value('is_closed')) {
            return 'project_closed';
        }
        $snapshot = DB::table('developer_snapshots')->where('project_id', $run->project_id)->where('id', $run->snapshot_id)->first();
        if (! $snapshot || ! $this->pins->snapshotCurrent($scope, $run->project_id, $snapshot)) {
            return 'source_changed';
        }
        if (! $this->pins->current($scope, $run->project_id, json_decode($run->pins, true))) {
            return 'pins_changed';
        }

        return null;
    }

    private function invalidate(TrustedActor $scope, string $project, string $run, string $reason, string $eventKey): void
    {
        $this->assertScope($scope, $project);
        $binding = DeveloperContract::canonical(['tenant_id' => $scope->tenantId, 'project_id' => $project, 'actor_id' => $scope->id]);
        $previous = DB::scalar("select current_setting('app.developer_invalidation',true)");
        DB::select("select set_config('app.developer_invalidation',?,true)", [$binding]);
        try {
            $this->mark($run, $reason, $eventKey);
        } finally {
            DB::select("select set_config('app.developer_invalidation',?,true)", [$previous ?? '']);
        }
    }

    public function mark(string $run, string $reason, string $eventKey): void
    {
        $binding = json_decode(DB::scalar("select nullif(current_setting('app.developer_invalidation',true),'')") ?? 'null', true);
        if (! $binding || ! Str::isUuid($run) || ! in_array($reason, ['source_changed', 'pins_changed', 'access_revoked', 'project_closed'], true) || strlen($eventKey) > 256 || ! $eventKey) {
            throw new LogicException('Invalidation requires internal trusted scope.');
        }
        $scope = new TrustedActor($binding['actor_id'], $binding['tenant_id']);
        $this->assertScope($scope, $binding['project_id']);
        $row = DB::table('developer_runs')->where('project_id', $binding['project_id'])->where('id', $run)->lockForUpdate()->first();
        if (! $row || $row->applicability !== 'current') {
            return;
        }
        $id = (string) Str::uuid();
        DB::table('developer_invalidations')->insert(['id' => $id, 'tenant_id' => $scope->tenantId, 'project_id' => $row->project_id, 'run_id' => $run, 'reason_code' => $reason, 'actor_id' => $scope->id, 'event_key' => hash('sha256', $run.'|'.$eventKey)]);
        $values = ['applicability' => 'stale', 'reason_code' => $reason];
        if (in_array($row->status, ['queued', 'running'], true)) {
            $values += ['status' => 'cancelled', 'lease_token' => null, 'lease_until' => null, 'completed_at' => DB::raw('clock_timestamp()')];
        }
        DB::table('developer_runs')->where('id', $run)->update($values);
        $execution = DB::table('developer_outbox')->where('run_id', $run)->lockForUpdate()->first();
        if ($execution && ! in_array($execution->status, ['completed', 'dead_letter', 'blocked'], true)) {
            DB::table('developer_outbox')->where('id', $execution->id)->update(['status' => 'blocked', 'reason_code' => $reason, 'lease_token' => null, 'lease_until' => null]);
        }
        DB::table('developer_outbox')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $scope->tenantId, 'project_id' => $row->project_id, 'invalidation_id' => $id]);
    }

    private function assertScope(TrustedActor $scope, string $project): void
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.tenant_id',true),'')") !== $scope->tenantId
            || ! DB::table('projects')->where('tenant_id', $scope->tenantId)->where('id', $project)->exists()) {
            throw new LogicException('Invalidation requires a trusted tenant/project transaction.');
        }
    }
}
