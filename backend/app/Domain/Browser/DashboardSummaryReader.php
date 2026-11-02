<?php

namespace App\Domain\Browser;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\Ba\BaReadService;
use App\Domain\Lifecycle\ProjectCoordination;
use App\Domain\Qa\QaReadService;
use App\Support\ReadDeliveryFence;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DashboardSummaryReader
{
    private const TASK_STATUSES = ['unstarted', 'in_progress', 'blocked', 'implemented_awaiting_verification', 'done'];

    public function read(TrustedActor $actor, string $project): array
    {
        $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
        app(AccessGateway::class)->authorize($actor, 'project.read', $scope);
        if (app()->bound(ReadDeliveryFence::class)) {
            throw new \LogicException('Nested dashboard reads are prohibited.');
        }
        $fence = new ReadDeliveryFence($actor, $project);
        app()->instance(ReadDeliveryFence::class, $fence);

        // One project lock keeps the multiple native queue pages on the same lifecycle state.
        try {
            return app(ProjectCoordination::class)->read($actor, $project, 'progress.read', function () use ($actor, $project, $scope, $fence): array {
                $tasks = $this->block($actor, $scope, ['requirements.read'], 'current_native_task_versions', function () use ($project) {
                    if (DB::table('lifecycle_project_state')->where('project_id', $project)->value('is_closed')) {
                        return $this->unknown('current_native_task_versions', ['project_closed'], 'unavailable');
                    }
                    $rows = DB::table('lifecycle_tasks as t')->leftJoin('task_versions as v', function ($join) {
                        $join->on('v.id', '=', 't.current_version_id')->on('v.tenant_id', '=', 't.tenant_id')->on('v.project_id', '=', 't.project_id')->on('v.task_id', '=', 't.id');
                    })->where('t.project_id', $project)->orderBy('t.id')->limit(1001)->get(['v.status']);
                    if ($rows->count() > 1000) {
                        return $this->unknown('current_native_task_versions', ['coverage_limit']);
                    }
                    $counts = array_fill_keys(self::TASK_STATUSES, 0);
                    foreach ($rows as $row) {
                        if (! in_array($row->status, self::TASK_STATUSES, true)) {
                            return $this->unknown('current_native_task_versions', ['current_version_unavailable']);
                        }
                        $counts[$row->status]++;
                    }

                    return $this->complete('current_native_task_versions', $counts + ['total' => $rows->count()]);
                });
                $qa = $this->block($actor, $scope, ['qa.read', 'qa.source.read'], 'approved_plan_criteria', function () use ($actor, $project) {
                    $native = app(QaReadService::class)->verification($actor, $project);
                    if ($native['coverage_status'] !== 'complete' || ($native['counts']['omitted_count'] ?? 0) > 0) {
                        return $this->unknown('approved_plan_criteria', $native['reason_codes'] ?: ['coverage_limit'], $native['coverage_status'] === 'unavailable' ? 'unavailable' : 'unknown');
                    }
                    $c = $native['counts'];

                    return $this->complete('approved_plan_criteria', ['executed_criteria' => $c['numerator'], 'required_criteria' => $c['denominator'], 'all_required_passed_criteria' => $c['all_required_passed_criterion_count'], 'fully_executed_criteria' => $c['fully_executed_criterion_count'], 'excluded_criteria' => $c['excluded_criterion_count'], 'omitted_criteria' => $c['omitted_count']]);
                });
                $dependencies = [];
                foreach (['qa', 'ba'] as $kind) {
                    $basis = $kind === 'qa' ? 'current_authorized_qa_handoffs' : 'current_authorized_ba_clarifications';
                    $dependencies[$kind] = $this->block($actor, $scope, [$kind.'.read', $kind.'.source.read'], $basis, fn () => $this->dependencies($actor, $project, $kind, $basis));
                }
                // Later native reads may cross a grant's expiry; recheck every delivered block.
                $tasks = $this->block($actor, $scope, ['requirements.read'], 'current_native_task_versions', fn () => $tasks);
                $qa = $this->block($actor, $scope, ['qa.read', 'qa.source.read'], 'approved_plan_criteria', fn () => $qa);
                foreach (['qa', 'ba'] as $kind) {
                    $value = $dependencies[$kind];
                    $dependencies[$kind] = $this->block($actor, $scope, [$kind.'.read', $kind.'.source.read'], $value['basis'], fn () => $value);
                }
                app(AccessGateway::class)->authorize($actor, 'project.read', $scope);
                $instant = DB::scalar('select clock_timestamp()');
                if (($reason = $fence->reason($qa['basis'], $instant)) !== null) {
                    $qa = $this->unknown($qa['basis'], [$reason]);
                }
                foreach ($dependencies as $kind => $value) {
                    if (($reason = $fence->reason($value['basis'], $instant)) !== null) {
                        $dependencies[$kind] = $this->unknown($value['basis'], [$reason]);
                    }
                }

                return ['version' => 'dashboard-summary-v1', 'project_id' => $project, 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s.uP'), 'synthetic' => DemoProvenance::synthetic($actor->tenantId, $project), 'ai_interpretation' => 'unavailable', 'tasks' => $tasks, 'qa' => $qa, 'dependencies' => $dependencies];
            });
        } finally {
            app()->forgetInstance(ReadDeliveryFence::class);
        }
    }

    private function block(TrustedActor $actor, array $scope, array $purposes, string $basis, Closure $read): array
    {
        try {
            foreach ($purposes as $purpose) {
                app(AccessGateway::class)->authorize($actor, $purpose, $scope);
            }
            $result = app(ReadDeliveryFence::class)->within($basis, $read);
            foreach ($purposes as $purpose) {
                app(AccessGateway::class)->authorize($actor, $purpose, $scope);
            }

            return $result;
        } catch (AccessDeniedHttpException) {
            return $this->unknown($basis, ['permission_unavailable'], 'unavailable');
        } catch (ConflictHttpException) {
            return $this->unknown($basis, ['current_state_changed']);
        }
    }

    private function dependencies(TrustedActor $actor, string $project, string $kind, string $basis): array
    {
        if (DB::table('lifecycle_project_state')->where('project_id', $project)->value('is_closed')) {
            return $this->unknown($basis, ['project_closed'], 'unavailable');
        }
        $cursor = null;
        $seen = [];
        $open = 0;
        // A final empty BA page may be needed after exactly 1000 records.
        for ($page = 0; $page < 21; $page++) {
            $native = $kind === 'qa'
                ? app(QaReadService::class)->queue($actor, $project, 50, $cursor)
                : app(BaReadService::class)->queue($actor, $project, ['limit' => 50, 'cursor' => $cursor]);
            if ($native['coverage_status'] !== 'complete') {
                return $this->unknown($basis, $native['reason_codes'] ?: ['current_evaluation_unavailable'], $native['coverage_status'] === 'unavailable' ? 'unavailable' : 'unknown');
            }
            foreach ($native['items'] as $item) {
                $id = $item[$kind === 'qa' ? 'id' : 'clarification_id'];
                if (isset($seen[$id]) || count($seen) >= 1000) {
                    return $this->unknown($basis, ['coverage_limit']);
                }
                if (($item['applicability'] ?? null) !== 'current') {
                    return $this->unknown($basis, ['current_evaluation_unavailable']);
                }
                $seen[$id] = true;
                if ($kind === 'qa' || ($item['dependency_status'] === 'unresolved' && $item['clarification_state'] !== 'withdrawn')) {
                    $open++;
                }
            }
            $next = $native['next_cursor'];
            if ($next === null) {
                return $this->complete($basis, ['open' => $open, 'total' => count($seen)]);
            }
            if ($next === $cursor || ! $native['items']) {
                return $this->unknown($basis, ['coverage_limit']);
            }
            $cursor = $next;
        }

        return $this->unknown($basis, ['coverage_limit']);
    }

    private function unknown(string $basis, array $reasons, string $coverage = 'unknown'): array
    {
        return ['coverage_status' => $coverage, 'basis' => $basis, 'counts' => null, 'reason_codes' => array_values(array_unique($reasons))];
    }

    private function complete(string $basis, array $counts): array
    {
        return ['coverage_status' => 'complete', 'basis' => $basis, 'counts' => $counts, 'reason_codes' => []];
    }
}
