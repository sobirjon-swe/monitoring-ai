<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementActionService;
use App\Domain\Management\ManagementContract;
use App\Domain\Management\ManagementPins;
use App\Domain\Management\ManagementReviewService;
use App\Domain\Management\ManagementSupportService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\ManagementTestCase;

class ManagementReviewTest extends ManagementTestCase
{
    public function test_full_workflow_preserves_qa_ba_obligations_heads_and_accepted_weights(): void
    {
        $this->managementInputs();
        $qa = $this->managementQaHandoff();
        $ba = $this->managementBaReviewedFixture();
        $qaEvent = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->where('id', $qa['id'])->value('current_event_id'));
        $qaRef = array_replace($this->lifecycleReference(), ['kind' => 'qa_handoff_event', 'qa_handoff_event_id' => $qaEvent]);
        $baRef = array_replace($this->lifecycleReference(), ['kind' => 'ba_review', 'ba_review_id' => $ba['review']['review_id']]);
        $refs = [$this->lifecycleReference(), $qaRef, $baRef];
        $snapshot = fn () => app(TenantContext::class)->run($this->tenant, function () {
            $result = [];
            foreach (['lifecycle_project_state', 'lifecycle_tasks', 'baseline_items', 'human_acceptance_decisions', 'qa_project_state', 'qa_source_heads', 'qa_handoffs', 'qa_receipts', 'qa_changes', 'qa_applicability', 'qa_invalidations', 'ba_clarifications', 'ba_source_heads', 'ba_scope_obligations', 'ba_receipts', 'ba_terminal_applicability'] as $table) {
                $rows = DB::table($table)->where('project_id', $this->p)->get()->map(fn ($r) => (array) $r)->all();
                usort($rows, fn ($a, $b) => strcmp(ManagementContract::canonical($a), ManagementContract::canonical($b)));
                $result[$table] = hash('sha256', ManagementContract::canonical($rows));
            }

            return $result;
        });
        $before = $snapshot();

        $head = $this->managementActor('department_head');
        $helper = $this->managementActor('developer');
        $department = (string) Str::uuid();
        $helperId = app(TenantContext::class)->run($this->tenant, function () use ($head, $helper, $department) {
            $headId = DB::table('users')->where('id', $head->id)->value('employee_id');
            $helperId = DB::table('users')->where('id', $helper->id)->value('employee_id');
            DB::table('departments')->insert(['id' => $department, 'tenant_id' => $this->tenant, 'name' => 'Synthetic support department']);
            foreach ([$this->employee, $headId, $helperId] as $employee) {
                DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
            }

            return $helperId;
        });
        $sourcePurposes = ['management.source.read', 'requirements.read', 'progress.read', 'qa.read', 'qa.source.read', 'ba.read', 'ba.source.read'];
        $this->managementGrantActor($head, ['management.support.assign', 'management.department.manage', ...$sourcePurposes], $department);
        $this->managementGrantActor($helper, ['management.support.record', ...$sourcePurposes]);
        $this->grant('management.outcome.review', $this->p);
        $actor = $this->actor();
        $actionService = app(ManagementActionService::class);
        $supportService = app(ManagementSupportService::class);
        $reviewService = app(ManagementReviewService::class);
        $openEnvelope = $this->managementEnvelope('action.open', $this->actionInput($refs));
        $assignmentBody = ['operation' => 'assignment.set', 'expected_revision' => 1, 'owner_id' => $this->employee, 'helper_id' => $helperId, 'department_id' => $department, 'reason' => 'Synthetic assignment'];

        $measure = function (\Closure $operation): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $started = hrtime(true);
            try {
                $result = $operation();
                $queries = DB::getQueryLog();
            } finally {
                $elapsed = (hrtime(true) - $started) / 1_000_000;
                DB::disableQueryLog();
            }
            $sourceWrites = 0;
            foreach ($queries as $query) {
                $writesSource = preg_match('/^\s*(insert\s+into|update|delete\s+from|truncate)\s+"?(qa_|ba_|lifecycle_|task_|requirement|criterion|baseline_items|human_acceptance)/i', $query['query']) === 1;
                $sourceWrites += (int) $writesSource;
            }
            $this->assertSame(0, $sourceWrites);

            return [$result, ['queries' => count($queries), 'database_ms' => round(array_sum(array_column($queries, 'time')), 3), 'elapsed_ms' => round($elapsed, 3), 'source_writes' => $sourceWrites]];
        };

        [$open, $openMeasure] = $measure(fn () => $actionService->open($actor, $this->p, $openEnvelope, 'open', 0));
        [$assignment, $assignmentMeasure] = $measure(fn () => $actionService->event($head, $this->p, $open['action_id'], $assignmentBody, 'assignment'));
        $f = compact('open', 'assignment', 'helper');
        $body = $this->workflowSupportBody($f);
        $body['references'] = $refs;
        $supportEnvelope = $this->managementEnvelope('support.record', $body, 'mixed-support');
        [$support, $supportMeasure] = $measure(fn () => $supportService->record($helper, $this->p, $open['action_id'], $supportEnvelope, 'mixed-support', 0));
        $reviewBody = $this->workflowReviewBody($support);
        [$review, $reviewMeasure] = $measure(fn () => $reviewService->review($actor, $this->p, $open['action_id'], $reviewBody, 'mixed-review'));
        $this->assertSame('completed', $review['current_outcome']);
        $this->assertSame($before, $snapshot());
        file_put_contents(base_path('../.local/management-t3-service-query-counts.json'), json_encode(['reference_kinds' => ['lifecycle', 'qa_handoff_event', 'ba_review'], 'measurement_scope' => 'exact service calls; fixture, actor, grant and body preparation excluded', 'action.open' => $openMeasure, 'assignment.set' => $assignmentMeasure, 'support.record' => $supportMeasure, 'outcome.confirm' => $reviewMeasure, 'source_writes' => 0], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    public function test_independent_pm_confirms_exact_current_support(): void
    {
        $f = $this->workflowAssigned();
        $support = app(ManagementSupportService::class)->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $this->workflowSupportBody($f), 'support'), 'support', 0);
        $this->grant('management.outcome.review', $this->p);
        $review = app(ManagementReviewService::class)->review($this->actor(), $this->p, $f['open']['action_id'], $this->workflowReviewBody($support), 'review');
        $this->assertSame(4, $review['revision']);
        $this->assertSame('completed', $review['current_outcome']);
    }

    public function test_two_accounts_of_helper_cannot_establish_employee_independence(): void
    {
        $f = $this->workflowAssigned();
        $support = app(ManagementSupportService::class)->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $this->workflowSupportBody($f), 'support'), 'support', 0);
        $employee = app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $f['helper']->id)->value('employee_id'));
        $second = $this->managementActor('pm', $employee);
        $this->managementGrantActor($second, ['management.outcome.review', 'management.source.read', 'requirements.read', 'progress.read']);
        $this->expectException(AccessDeniedHttpException::class);
        app(ManagementReviewService::class)->review($second, $this->p, $f['open']['action_id'], $this->workflowReviewBody($support), 'self-confirm');
    }

    public function test_department_head_reviews_only_with_explicit_department_and_project_purposes(): void
    {
        $f = $this->workflowAssigned();
        $support = app(ManagementSupportService::class)->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $this->workflowSupportBody($f), 'support'), 'support', 0);
        $this->managementGrantActor($f['head'], ['management.outcome.review']);
        $review = app(ManagementReviewService::class)->review($f['head'], $this->p, $f['open']['action_id'], $this->workflowReviewBody($support), 'department-review');
        $this->assertSame('completed', $review['current_outcome']);
    }

    public function test_confirm_revoke_fresh_confirm_and_reject_clear_exact_projection(): void
    {
        $f = $this->workflowAssigned();
        $support = app(ManagementSupportService::class)->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $this->workflowSupportBody($f), 'support'), 'support', 0);
        $this->grant('management.outcome.review', $this->p);
        $service = app(ManagementReviewService::class);
        $review = $service->review($this->actor(), $this->p, $f['open']['action_id'], $this->workflowReviewBody($support), 'confirm');
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$f['open']['action_id']]);
        $this->assertSame('complete', $graph['coverage'], json_encode($graph['budget']));
        $this->assertLessThanOrEqual(24, $graph['budget']['named_slots']);
        file_put_contents(base_path('../.local/management-t3-reviewed-budget.json'), json_encode($graph['budget'], JSON_THROW_ON_ERROR));
        $body = $this->workflowReviewBody($support, 'outcome.revoke');
        $body['expected_revision'] = 4;
        $body['predecessor_review_id'] = $review['review_id'];
        $revoked = $service->review($this->actor(), $this->p, $f['open']['action_id'], $body, 'revoke');
        $this->assertNull($revoked['current_outcome']);
        $body = $this->workflowReviewBody($support, 'outcome.reject');
        $body['expected_revision'] = 5;
        $rejected = $service->review($this->actor(), $this->p, $f['open']['action_id'], $body, 'reject');
        $this->assertNull($rejected['current_outcome']);
        app(TenantContext::class)->run($this->tenant, function () use ($rejected, $support) {
            $event = DB::table('management_events')->where('id', $rejected['event_id'])->first();
            $this->assertSame($support['support_id'], $event->subject_support_id);
            $this->assertNull($event->support_id);
            $this->assertSame('rejected', $event->review_status);
        });
        $replay = $service->review($this->actor(), $this->p, $f['open']['action_id'], $body, 'reject');
        $this->assertTrue($replay['replayed']);
        $this->assertNull($replay['current_outcome']);
    }
}
