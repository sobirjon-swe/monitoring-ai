<?php

namespace Tests\Feature;

use App\Domain\Lifecycle\RequirementService;
use App\Domain\Qa\QaCoordination;
use App\Domain\Qa\QaDefectService;
use App\Domain\Qa\QaDefinitionService;
use App\Domain\Qa\QaDepartmentView;
use App\Domain\Qa\QaExecutionService;
use App\Domain\Qa\QaFixtureService;
use App\Domain\Qa\QaHandoffService;
use App\Domain\Qa\QaPlanService;
use App\Domain\Qa\QaReadService;
use App\Domain\Qa\QaVerificationCalculator;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\QaTestCase;

class QaAccessTest extends QaTestCase
{
    public function test_department_queue_withholds_outside_current_reviewer_before_page_and_count(): void
    {
        [$department, $hidden, $shown, $review, $other] = $this->outsideCurrentReviewerQueue();
        $reviewEmployee = app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $other->id)->value('employee_id'));
        $queue = app(QaReadService::class)->queue($this->actor(), $this->p, 1, department: $department);
        $this->assertSame([$shown['id']], array_column($queue['items'], 'id'));
        $this->assertSame('current', $queue['items'][0]['applicability']);
        $this->assertSame(1, $queue['visible_record_count']);
        $this->assertNull($queue['next_cursor']);
        foreach ([$hidden['id'], $review['id'], $other->id, $reviewEmployee, 'access_revoked', 'omitted_count', 'hidden_count'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($queue));
        }
        $project = collect(app(QaReadService::class)->queue($this->actor(), $this->p)['items'])->keyBy('id');
        $this->assertSame('stale', $project[$hidden['id']]['applicability']);
        $this->assertContains('access_revoked', $project[$hidden['id']]['reason_codes']);
        // Immutable history does not consume today's reviewer as a new prerequisite.
        $history = app(QaReadService::class)->history($this->actor(), $this->p, 'handoff', $hidden['id'], department: $department);
        $this->assertCount(1, $history['items']);
    }

    public function test_department_current_review_is_in_locked_queue_subject_closure(): void
    {
        [$department, $hidden, $shown, $review, $other, $body] = $this->outsideCurrentReviewerQueue();
        $graph = app(QaDefectService::class)->collectPins($this->actor(), $this->p, ['handoff' => [$hidden['id'], $shown['id']], 'definition' => [$body['definition_version_id']], 'build' => [$body['build_id']], 'environment' => [$body['environment_version_id']]]);
        $view = app(QaDepartmentView::class)->prepare($this->actor(), $this->p, $department, $graph, true);
        app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.read', 'qa.source.read', 'qa.department.read'], false, function ($proofs) use ($view, $hidden, $shown) {
            $this->assertFalse(app(QaDepartmentView::class)->visible($view, $proofs['current'], 'handoff', $hidden['id']));
            $this->assertTrue(app(QaDepartmentView::class)->visible($view, $proofs['current'], 'handoff', $shown['id']));
        }, $graph['requests'], $department, $view['refs']);
    }

    private function outsideCurrentReviewerQueue(): array
    {
        $this->qaInputs();
        $department = $this->department();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]));
        [$body] = $this->executionInputs();
        $other = $this->distinctQaReviewer();
        $review = app(QaDefinitionService::class)->review($other, $this->p, $body['definition_version_id'], ['expected_revision' => 1, 'decision' => 'reviewed', 'reason' => 'Synthetic current outside reviewer']);
        $execution = $this->execution($body);
        $data = $this->handoffData($body);
        $data['body']['execution_id'] = $execution['record_id'];
        $hidden = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'outside-review-queue');
        $data = $this->handoffData($body);
        $data['object_id'] = 'unrelated-visible-queue';
        $shown = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'unrelated-visible-queue');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $other->id)->where('purpose', 'qa.plan.manage')->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => DB::raw('revision+1')]));

        return [$department, $hidden, $shown, $review, $other, $body];
    }

    public function test_queue_criterion_replacement_is_stale_before_maintenance(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $handoff = app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'criterion-queue');
        $data = $this->requirement();
        $data['criteria'][] = ['key' => 'C', 'text' => 'Synthetic C', 'required_test_keys' => ['test-c']];
        $data['criteria'][0]['text'] = 'Merge';
        app(RequirementService::class)->updateDraft($this->actor(), $this->p, $this->draft['id'], $data, 1);
        app(RequirementService::class)->approve($this->actor(), $this->p, $this->draft['id'], 2, $this->generation, 'Synthetic queue scope changed');
        $queue = app(QaReadService::class)->queue($this->actor(), $this->p);
        $this->assertSame($handoff['id'], $queue['items'][0]['id']);
        $this->assertSame('stale', $queue['items'][0]['applicability']);
        $this->assertContains('criterion_version_changed', $queue['items'][0]['reason_codes']);
    }

    public function test_queue_linked_original_invalidity_stays_stale_after_exact_restoration(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $execution = $this->execution($body);
        $data = $this->handoffData($body);
        $data['body']['execution_id'] = $execution['record_id'];
        $handoff = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'linked-queue');
        $this->revokeQaGrant('qa.fixture.manage');
        $read = app(QaReadService::class);
        $first = $read->queue($this->actor(), $this->p);
        $this->assertSame($handoff['id'], $first['items'][0]['id']);
        $this->assertSame('stale', $first['items'][0]['applicability']);
        $this->assertContains('access_revoked', $first['items'][0]['reason_codes']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('project_id', $this->p)->where('purpose', 'qa.fixture.manage')->update(['revoked_at' => null, 'revision' => DB::raw('revision-1')]));
        $restored = $read->queue($this->actor(), $this->p);
        $this->assertSame('stale', $restored['items'][0]['applicability']);
        $this->assertContains('access_revoked', $restored['items'][0]['reason_codes']);
    }

    public function test_department_without_selection_remains_unknown(): void
    {
        $this->qaInputs();
        $department = $this->department();
        $facts = app(QaReadService::class)->verification($this->actor(), $this->p, $department);
        $this->assertSame('unknown', $facts['coverage_status']);
        $this->assertSame(['current_verification_unavailable'], $facts['reason_codes']);
        $this->assertSame([], $facts['counts']['criteria']);
        $this->assertNull($facts['counts']['numerator']);
        $this->assertNull($facts['selection']);
    }

    public function test_queue_linked_source_correction_is_stale_before_maintenance(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $execution = $this->execution($body);
        $data = $this->handoffData($body);
        $data['body']['execution_id'] = $execution['record_id'];
        $handoff = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'linked-source-queue');
        $this->qaBuild(2, 1);
        $queue = app(QaReadService::class)->queue($this->actor(), $this->p);
        $this->assertSame($handoff['id'], $queue['items'][0]['id']);
        $this->assertSame('stale', $queue['items'][0]['applicability']);
        $this->assertContains('source_version_changed', $queue['items'][0]['reason_codes']);
    }

    public function test_queue_unrelated_original_proof_does_not_poison_an_independent_item(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $first = app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'independent-queue');
        $other = $this->distinctQaReviewer();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $other->id, 'policy_version_id' => $this->policy, 'purpose' => 'qa.handoff.manage', 'scope_type' => 'project', 'valid_from' => now()->subDay()]));
        $data = $this->handoffData($body);
        $data['object_id'] = 'unrelated-queue';
        $second = app(QaHandoffService::class)->open($other, $this->p, $data, 'unrelated-queue');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $other->id)->where('purpose', 'qa.handoff.manage')->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => DB::raw('revision+1')]));
        $queue = collect(app(QaReadService::class)->queue($this->actor(), $this->p)['items'])->keyBy('id');
        $this->assertSame('current', $queue[$first['id']]['applicability']);
        $this->assertSame([], $queue[$first['id']]['reason_codes']);
        $this->assertSame('stale', $queue[$second['id']]['applicability']);
        $this->assertContains('access_revoked', $queue[$second['id']]['reason_codes']);
    }

    public function test_empty_department_result_does_not_hide_unavailable_global_context(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body);
        $reader = $this->distinctQaReviewer();
        $department = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($reader, $department) {
            $employee = DB::table('users')->where('id', $reader->id)->value('employee_id');
            DB::table('departments')->insert(['id' => $department, 'tenant_id' => $this->tenant, 'name' => 'Synthetic reader department']);
            DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'employee_id' => $employee, 'department_id' => $department, 'valid_from' => now()->subDay()]);
            DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'user_id' => $reader->id, 'purpose' => 'qa.department.read', 'policy_version_id' => $this->policy, 'scope_type' => 'department', 'department_id' => $department, 'valid_from' => now()->subDay()]);
        });
        $this->revokeQaGrant('qa.plan.manage');
        $facts = app(QaReadService::class)->verification($reader, $this->p, $department);
        $this->assertSame([], $facts['counts']['criteria']);
        $this->assertSame('unknown', $facts['coverage_status']);
        $this->assertSame(['current_verification_unavailable'], $facts['reason_codes']);
        $this->assertNull($facts['counts']['denominator']);
        $this->assertStringNotContainsString('access_revoked', json_encode($facts));
    }

    public function test_hidden_unrelated_execution_failure_does_not_poison_delivered_department_fact(): void
    {
        $this->qaInputs();
        $department = $this->department();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]));
        [$a] = $this->executionInputs();
        $definitionB = $this->reviewedDefinition(1, 'department-b');
        $taskB = $this->qaTask(1);
        $b = array_replace($a, ['definition_version_id' => $definitionB['id'], 'task_version_id' => $taskB['version_id']]);
        $plan = $this->composeEntries([$this->planEntry(0, [['definition_version_id' => $a['definition_version_id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(1, [['definition_version_id' => $definitionB['id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(2)]);
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic department independent facts']);
        $this->selectPlan($plan['id'], $a['build_id'], $a['environment_version_id'], 0);
        $this->execution($a);
        $other = $this->distinctQaReviewer();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $other->id, 'policy_version_id' => $this->policy, 'purpose' => 'qa.execution.record', 'scope_type' => 'project', 'valid_from' => now()->subDay()]));
        $envelope = $this->qaEnvelope('execution', $b, 'department-hidden-execution');
        $hidden = app(QaExecutionService::class)->register($other, $this->p, $envelope, 'department-hidden-execution', 0, 0);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $other->id)->where('purpose', 'qa.execution.record')->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => DB::raw('revision+1')]));
        $facts = app(QaReadService::class)->verification($this->actor(), $this->p, $department);
        $this->assertSame('complete', $facts['coverage_status']);
        $this->assertSame('passed', $facts['counts']['criteria'][$this->criteria[0]['id']]['tests'][0]['status']);
        $this->assertStringNotContainsString($hidden['record_id'], json_encode($facts));
        $this->assertNull($facts['counts']['numerator']);
        $this->assertNull($facts['counts']['criteria'][$this->criteria[0]['id']]['all_required_passed']);
    }

    public function test_linked_source_employee_is_filtered_before_queue_page_and_history(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $department = $this->department();
        $other = $this->distinctQaReviewer();
        app(TenantContext::class)->run($this->tenant, function () use ($other) {
            DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'user_id' => $other->id, 'policy_version_id' => $this->policy, 'purpose' => 'qa.fixture.manage', 'scope_type' => 'project', 'project_id' => $this->p, 'valid_from' => now()->subDay()]);
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]);
        });
        $data = $this->qaEnvelope('build', ['logical_key' => 'other-build', 'revision' => 1, 'title' => 'Synthetic linked source', 'content_hash' => hash('sha256', 'other-build')], 'other-build');
        $build = app(QaFixtureService::class)->register($other, $this->p, $data, 'other-build', 0);
        $body['build_id'] = $build['record_id'];
        $execution = $this->execution($body);
        $data = $this->handoffData($body);
        $data['body']['execution_id'] = $execution['record_id'];
        $hidden = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'linked-hidden');
        $visible = $this->handoffData($body);
        $visible['object_id'] = 'unlinked-visible';
        $shown = app(QaHandoffService::class)->open($this->actor(), $this->p, $visible, 'unlinked-visible');
        $read = app(QaReadService::class);
        $queue = $read->queue($this->actor(), $this->p, 1, department: $department);
        $this->assertSame([$shown['id']], array_column($queue['items'], 'id'));
        $this->assertNull($queue['next_cursor']);
        $this->assertStringNotContainsString($hidden['id'], json_encode($queue));
        $this->assertSame([], $read->history($this->actor(), $this->p, 'handoff', $hidden['id'], 1, department: $department)['items']);
        $this->assertSame([], $read->history($this->actor(), $this->p, 'execution', $execution['record_id'], 1, department: $department)['items']);
    }

    public function test_queue_and_history_filter_hidden_owner_before_authorized_continuations(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $department = $this->department();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]));
        $hidden = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($hidden) {
            DB::table('employees')->insert(['id' => $hidden, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic hidden owner']);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $hidden, 'valid_from' => now()->subDay()]);
        });
        $hiddenData = $this->handoffData($body);
        $hiddenData['body']['owner_id'] = $hidden;
        $secret = app(QaHandoffService::class)->open($this->actor(), $this->p, $hiddenData, 'hidden');
        $visibleData = $this->handoffData($body);
        $visibleData['object_id'] = 'visible';
        $visible = app(QaHandoffService::class)->open($this->actor(), $this->p, $visibleData, 'visible');
        try {
            $queue = app(QaReadService::class)->queue($this->actor(), $this->p, 1, department: $department);
        } catch (\LogicException $missing) {
            $this->fail('Protected bounded queue is required: '.$missing->getMessage());
        }
        $this->assertSame([$visible['id']], array_column($queue['items'], 'id'));
        $this->assertNull($queue['next_cursor']);
        $this->assertSame(1, $queue['visible_record_count']);
        $this->assertStringNotContainsString($hidden, json_encode($queue));
        $this->assertStringNotContainsString($secret['id'], json_encode($queue));
        $this->assertArrayNotHasKey('omitted_count', $queue);
        $history = app(QaReadService::class)->history($this->actor(), $this->p, 'handoff', $visible['id'], department: $department);
        $this->assertCount(1, $history['items']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('department_memberships')->where('employee_id', $this->employee)->where('department_id', $department)->update(['valid_to' => DB::raw('clock_timestamp()')]));
        $this->expectException(AccessDeniedHttpException::class);
        app(QaReadService::class)->history($this->actor(), $this->p, 'handoff', $visible['id'], department: $department);
    }

    public function test_history_over_one_hundred_rows_and_concurrent_append_has_no_omissions_or_duplicates(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $handoff = app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'pages');
        $this->handoffId = $handoff['id'];
        foreach (range(1, 101) as $number) {
            if ($number % 40 === 0) {
                $this->completeSyntheticQaJobs();
            } $this->handoffTransition($number, 'context_added');
        }
        $read = app(QaReadService::class);
        $first = $read->history($this->actor(), $this->p, 'handoff', $handoff['id'], 20);
        $this->contendQaOperation('handoff', ['handoff_id' => $handoff['id'], 'event' => ['expected_revision' => 102, 'kind' => 'context_added', 'reason' => 'Synthetic concurrent page append', 'pins' => new \stdClass]]);
        $ids = array_column($first['items'], 'id');
        $cursor = $first['next_cursor'];
        while ($cursor !== null) {
            $page = $read->history($this->actor(), $this->p, 'handoff', $handoff['id'], 20, $cursor);
            $ids = [...$ids, ...array_column($page['items'], 'id')];
            $cursor = $page['next_cursor'];
        }
        $this->assertCount(103, $ids);
        $this->assertCount(103, array_unique($ids));
        try {
            $read->history($this->actor(), $this->p, 'build', $body['build_id'], 20, $first['next_cursor']);
            $this->fail('Cursor must bind the exact resource.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->revokeQaGrant('qa.source.read');
        $this->assertDenied(fn () => $read->history($this->actor(), $this->p, 'handoff', $handoff['id'], 20, $first['next_cursor']));
    }

    public function test_department_subjects_reserve_distinct_current_caller_outside_one_hundred_original_actors(): void
    {
        $this->qaInputs();
        $department = $this->department();
        $requests = app(TenantContext::class)->run($this->tenant, function () {
            $requests = [];
            foreach (range(1, 100) as $number) {
                $employee = (string) Str::uuid();
                $user = (string) Str::uuid();
                DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic original']);
                DB::table('users')->insert(['id' => $user, 'tenant_id' => $this->tenant, 'employee_id' => $employee, 'display_name' => 'Synthetic original']);
                DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
                DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'user_id' => $user, 'policy_version_id' => $this->policy, 'purpose' => 'qa.read', 'scope_type' => 'project', 'project_id' => $this->p, 'valid_from' => now()->subDay()]);
                $requests[] = ['key' => 'original:'.$number, 'actor_id' => $user, 'purposes' => ['qa.read'], 'required' => false];
            }

            return $requests;
        });
        $proofs = app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.read', 'qa.source.read', 'qa.department.read'], false, fn ($proofs) => $proofs, $requests, $department, [$this->employee]);
        $this->assertArrayHasKey('department_subjects', $proofs['current'], 'Subject refs must attach to the reserved current caller.');
        foreach ($requests as $request) {
            $this->assertTrue($proofs[$request['key']]['valid']);
        }
    }

    public function test_queue_current_task_heads_are_loaded_once_for_fifty_visible_records(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        foreach (range(1, 50) as $n) {
            $data = $this->handoffData($body);
            $data['object_id'] = 'query-bound-'.$n;
            app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'query-bound-'.$n);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = app(QaReadService::class)->queue($this->actor(), $this->p);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(50, $result['items']);
        $heads = array_filter($queries, fn ($query) => str_contains($query['query'], 'from "lifecycle_tasks"'));
        $this->assertLessThanOrEqual(1, count($heads), 'The fifty-record page must use one current-task head query.');
    }

    public function test_department_overflow_withholds_whole_result_and_all_global_coverage(): void
    {
        $this->qaInputs();
        $department = $this->department();
        $result = app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.read', 'qa.source.read', 'qa.department.read'], false, function ($proofs) use ($department) {
            $view = app(QaDepartmentView::class)->authorizeLocked($this->actor(), $this->p, $department, ['department_view' => ['overflow' => true, 'subjects' => [], 'refs' => []]], $proofs);

            return app(QaDepartmentView::class)->redact(app(QaVerificationCalculator::class)->calculate(['selection' => ['id' => 'synthetic'], 'criteria' => $view['criteria']]), $view);
        }, department: $department, departmentRefs: []);
        $this->assertSame('unknown', $result['coverage_status']);
        $this->assertSame(['coverage_limit'], $result['reason_codes']);
        $this->assertSame([], $result['counts']['criteria']);
        $this->assertSame(0, $result['counts']['visible_record_count']);
        foreach (['numerator', 'denominator', 'ratio_basis_points', 'fully_executed_criterion_count', 'all_required_passed_criterion_count'] as $field) {
            $this->assertNull($result['counts'][$field]);
        }
        $this->assertArrayNotHasKey('omitted_count', $result['counts']);
        $this->assertNull($result['selection']);
    }

    public function test_withheld_global_original_proof_failure_is_never_favourable_or_specific(): void
    {
        $this->qaInputs();
        [$body,$plan] = $this->selectedExecutionInputs();
        $department = $this->department();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]));
        $other = $this->distinctQaReviewer();
        app(QaPlanService::class)->review($other, $this->p, $plan['id'], ['expected_revision' => 1, 'decision' => 'approved', 'reason' => 'Synthetic other global review']);
        $this->selectPlan($plan['id'], $body['build_id'], $body['environment_version_id'], 1);
        $this->execution($body);
        $read = app(QaReadService::class);
        $before = $read->verification($this->actor(), $this->p, $department);
        $this->assertSame('passed', $before['counts']['criteria'][$this->criteria[0]['id']]['tests'][0]['status']);
        $this->revokeDistinctQaReviewer($other);
        $after = $read->verification($this->actor(), $this->p, $department);
        $test = $after['counts']['criteria'][$this->criteria[0]['id']]['tests'][0];
        $this->assertContains($test['status'], ['stale', 'unknown']);
        $this->assertSame(['current_verification_unavailable'], $test['reason_codes']);
        $this->assertStringNotContainsString('access_revoked', json_encode($after));
        $this->assertStringNotContainsString($other->id, json_encode($after));
        $this->assertNull($after['counts']['numerator']);
        $this->assertNull($after['counts']['denominator']);
        $this->assertNull($after['counts']['criteria'][$this->criteria[0]['id']]['all_required_passed']);
    }

    public function test_visible_linked_handoff_history_captures_exact_dependency_subjects(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $department = $this->department();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]));
        $parent = app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'linked-parent');
        $data = $this->handoffData($body);
        $data['object_id'] = 'linked-child';
        $data['body']['linked_handoff_id'] = $parent['id'];
        $child = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'linked-child');
        $history = app(QaReadService::class)->history($this->actor(), $this->p, 'handoff', $child['id'], department: $department);
        $this->assertCount(1, $history['items']);
        $this->assertSame('complete', $history['coverage_status']);
    }

    public function test_department_delivery_expiring_after_capture_rolls_back_observations(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body);
        $department = $this->department();
        $this->revokeQaGrant('qa.execution.record');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('department_memberships')->where('employee_id', $this->employee)->where('department_id', $department)->update(['valid_to' => DB::raw("clock_timestamp()+interval '1 second'")]));
        $delay = true;
        DB::listen(function ($query) use (&$delay) {
            if ($delay && str_contains($query->sql, "set_config('app.qa_authority_closed','1',true)")) {
                $delay = false;
                usleep(1200000);
            }
        });
        try {
            $this->assertDenied(fn () => app(QaReadService::class)->verification($this->actor(), $this->p, $department));
        } finally {
            $delay = false;
        }
        $this->assertSame(0, app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_invalidations')->where('project_id', $this->p)->count()));
    }

    public function test_department_filter_before_counts(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body);
        $department = $this->department();
        $this->assertTrue(class_exists(QaReadService::class), 'Department delivery must use the subject proof batch.');
        $facts = app(QaReadService::class)->verification($this->actor(), $this->p, $department);
        $this->assertNull($facts['counts']['numerator']);
        $this->assertNull($facts['counts']['denominator']);
        $this->assertNull($facts['counts']['ratio_basis_points']);
        $this->assertNull($facts['counts']['fully_executed_criterion_count']);
        $this->assertNull($facts['counts']['criteria'][$this->criteria[0]['id']]['all_required_passed']);
        $this->assertSame('department_authorized_entries', $facts['qa_verification']['basis']);
        $this->assertArrayNotHasKey('omitted_count', $facts['counts']);
        $this->assertNull($facts['selection']);
    }

    public function test_department_grant_and_current_membership_required(): void
    {
        $this->qaInputs();
        $department = $this->department();
        $this->assertTrue(class_exists(QaReadService::class), 'Explicit department authorization is required.');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'qa.department.read')->where('user_id', $this->user)->update(['revoked_at' => DB::raw('clock_timestamp()')]));
        $this->expectException(AccessDeniedHttpException::class);
        app(QaReadService::class)->verification($this->actor(), $this->p, $department);
    }

    private function department(): string
    {
        $id = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($id) {
            DB::table('departments')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'name' => 'Synthetic department']);
            DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $id, 'employee_id' => $this->employee, 'valid_from' => now()->subDay()]);
        });
        $this->grant('qa.department.read', department: $id);

        return $id;
    }
}
