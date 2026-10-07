<?php

namespace Tests\Feature;

use App\Domain\Lifecycle\TaskService;
use App\Domain\Qa\QaContract;
use App\Domain\Qa\QaCoordination;
use App\Domain\Qa\QaDefectService;
use App\Domain\Qa\QaDepartmentView;
use App\Domain\Qa\QaExecutionService;
use App\Domain\Qa\QaHandoffService;
use App\Domain\Qa\QaPlanService;
use App\Domain\Qa\QaReadService;
use App\Domain\Qa\QaResponsibility;
use App\Domain\Qa\QaVerificationCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QaTestCase;

class QaFinalRegressionTest extends QaTestCase
{
    public function test_open_critical_defect_and_passed_retest_need_current_manual_clearance(): void
    {
        $this->qaInputs();
        [$body, $definition] = $this->executionInputs();
        $otherDefinition = $this->reviewedDefinition(1, 'test-b');
        $otherTask = $this->qaTask(1);
        $plan = $this->composeEntries([$this->planEntry(0, [['definition_version_id' => $definition['id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(1, [['definition_version_id' => $otherDefinition['id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(2)]);
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic approval']);
        $this->selectPlan($plan['id'], $body['build_id'], $body['environment_version_id'], 0);
        $registrar = $this->distinctQaReviewer();
        $this->qaRun(fn () => DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'user_id' => $registrar->id, 'policy_version_id' => $this->policy, 'purpose' => 'qa.execution.record', 'scope_type' => 'project', 'project_id' => $this->p, 'valid_from' => now()->subDay()]));
        $envelope = $this->qaEnvelope('execution', $body, 'historical-execution');
        $envelope['occurred_at'] = $envelope['source_updated_at'] = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $first = app(QaExecutionService::class)->register($registrar, $this->p, $envelope, 'historical-execution', 0, 0);
        $this->execution(array_replace($body, ['definition_version_id' => $otherDefinition['id'], 'task_version_id' => $otherTask['version_id'], 'outcome' => 'blocked', 'blocked_reason' => 'Synthetic unavailable environment']), 'blocked');
        $report = $this->defectReport($body, $first['record_id']);
        $report['body']['severity'] = 'critical';
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $report, 'bug-a');
        $this->assertContains($defect['id'], array_column($this->read()['critical_signals']['items'], 'defect_id'));
        $fixed = $this->fixedDefect($body, $defect);
        $this->qaRun(fn () => DB::table('access_grants')->where('user_id', $registrar->id)->where('purpose', 'qa.execution.record')->update(['revision' => DB::raw('revision+1'), 'revoked_at' => DB::raw('clock_timestamp()')]));
        $this->assertSame('stale', $this->read()['counts']['criteria'][$this->criteria[0]['id']]['tests'][0]['status']);
        $again = $this->execution(array_replace($body, ['attempt_ordinal' => 2, 'outcome' => 'failed', 'predecessor_attempt_id' => $this->qaExecutionRow($first['record_id'])->attempt_id, 'fix_request_event_id' => $fixed['id']]), 'failed-retest', seriesRevision: 1);
        $pass = $this->execution(array_replace($body, ['attempt_ordinal' => 3, 'predecessor_attempt_id' => $this->qaExecutionRow($again['record_id'])->attempt_id, 'fix_request_event_id' => $fixed['id']]), 'retest', seriesRevision: 2);
        $this->assertContains('critical_defect_unresolved', $this->read()['critical_signals']['codes']);
        $this->defectTransition($defect['id'], 3, 'verified_closed', ['execution_id' => $pass['record_id'], 'fix_request_event_id' => $fixed['id'], 'selection_id' => $this->currentQaSelection()]);
        $this->assertNotContains($defect['id'], array_column($this->read()['critical_signals']['items'], 'defect_id'));
        $this->selectPlan($plan['id'], $body['build_id'], $body['environment_version_id'], 1);
        $this->assertContains($defect['id'], array_column($this->read()['critical_signals']['items'], 'defect_id'));
    }

    public static function mandatoryGrants(): array
    {
        return array_map(fn ($purpose) => [$purpose], ['qa.execution.record', 'qa.defect.review', 'qa.plan.manage', 'qa.source.read']);
    }

    #[DataProvider('mandatoryGrants')]
    public function test_observed_execution_revocation_cannot_be_restored_into_a_closure(string $purpose): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'bug-a');
        $fixed = $this->fixedDefect($body, $defect);
        $pass = $this->execution($body + ['fix_request_event_id' => $fixed['id']]);
        $viewer = $this->distinctQaReviewer();
        $grant = $this->qaRun(fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', $purpose)->first());
        $this->revokeQaGrant($purpose);
        $this->assertSame('stale', app(QaReadService::class)->verification($viewer, $this->p)['counts']['criteria'][$this->criteria[0]['id']]['tests'][0]['status']);
        $this->qaRun(fn () => DB::table('access_grants')->where('id', $grant->id)->update(['revision' => $grant->revision, 'revoked_at' => null]));
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], 3, 'verified_closed', ['execution_id' => $pass['record_id'], 'fix_request_event_id' => $fixed['id'], 'selection_id' => $this->currentQaSelection()]));
        $this->assertSame($before, $this->defectWriteCounts());
    }

    public function test_event_time_task_interval_excludes_successor_boundary_but_keeps_late_history(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $this->role();
        $task = $this->qaRun(fn () => DB::table('task_versions')->find($body['task_version_id']));
        $other = $this->distinctQaReviewer();
        $employee = $this->qaRun(fn () => DB::table('users')->where('id', $other->id)->value('employee_id'));
        $data = ['title' => 'Reassigned task', 'assignee_id' => $employee, 'estimate_basis' => 'Synthetic', 'remaining_effort' => null, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => [['criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id']]]];
        $next = app(TaskService::class)->revise($this->actor(), $this->p, $task->task_id, $data, 1, $this->generation);
        $boundary = $this->qaRun(fn () => DB::table('task_versions')->where('id', $next['version_id'])->value('effective_at'));
        app(TaskService::class)->revise($this->actor(), $this->p, $task->task_id, array_replace($data, ['title' => 'Later revision']), 2, $next['generation']);
        foreach ([$task->effective_at, $boundary, CarbonImmutable::parse($boundary)->addMicrosecond()->toISOString()] as $i => $at) {
            $envelope = $this->qaEnvelope('execution', $body, 'late-interval', $i + 1);
            $envelope['occurred_at'] = $envelope['source_updated_at'] = CarbonImmutable::parse($at)->utc()->format('Y-m-d\TH:i:s.u\Z');
            $fact = app(QaExecutionService::class)->register($this->actor(), $this->p, $envelope, 'late-'.$i, $i, min(1, $i));
            $snapshot = json_decode($this->qaExecutionRow($fact['record_id'])->responsibility, true);
            $this->assertSame($i === 0 ? 'known' : 'unknown', $snapshot['attribution']);
            $this->assertSame($i === 0 ? $this->employee : null, $snapshot['employee_id']);
        }
        $missing = (string) Str::uuid();
        $unknown = $this->qaRun(fn () => app(QaResponsibility::class)->captureLocked($this->p, $missing, $task->effective_at, null), [['key' => 'missing-task', 'actor_id' => $this->user, 'purposes' => ['qa.read'], 'required' => true, 'responsibility_refs' => [['task_version_id' => $missing, 'occurred_at' => $task->effective_at]]]]);
        $this->assertSame('unknown', $unknown['attribution']);
        $this->qaRun(function () use ($task) {
            $memberships = [];
            for ($i = 0; $i < 101; $i++) {
                $memberships[] = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'valid_from' => CarbonImmutable::parse($task->effective_at)->subMicrosecond(), 'valid_to' => CarbonImmutable::parse($task->effective_at)->addMicrosecond()];
            }
            DB::table('project_memberships')->insert($memberships);
        });
        $overflow = $this->qaRun(fn () => app(QaResponsibility::class)->captureLocked($this->p, $task->id, $task->effective_at, null), [['key' => 'bounded-task', 'actor_id' => $this->user, 'purposes' => ['qa.read'], 'required' => true, 'responsibility_refs' => [['task_version_id' => $task->id, 'occurred_at' => $task->effective_at]]]]);
        $this->assertSame('unknown', $overflow['attribution']);
        $this->assertSame('coverage_limit', $overflow['reason_code']);
    }

    public function test_department_critical_signal_continuation_is_visible_only(): void
    {
        foreach ([100, 101] as $count) {
            $tests = array_fill(0, $count, ['required' => true, 'status' => 'failed', 'release_critical' => true, 'definition_version_id' => 'visible', 'execution_id' => 'visible', 'reason_codes' => []]);
            $input = ['selection' => ['id' => 'synthetic'], 'criteria' => [['criterion_id' => 'visible', 'included' => true, 'tests' => $tests]]];
            $result = app(QaVerificationCalculator::class)->calculate($input);
            $result['critical_signals']['items'][] = ['code' => 'private', 'defect_id' => 'hidden'];
            $result['critical_signals']['omitted_count'] = 777;
            $redacted = app(QaDepartmentView::class)->redact($result, ['coverage_status' => 'complete', 'reason_codes' => []]);
            $this->assertSame($count - 100, $redacted['critical_signals']['omitted_count'] ?? null);
            $this->assertSame($count > 100, $redacted['critical_signals']['continuation_required'] ?? null);
            $this->assertCount(100, $redacted['critical_signals']['items']);
            $this->assertStringNotContainsString('hidden', json_encode($redacted));
        }
    }

    public function test_legal_history_page_exposes_original_actor_coverage_overflow(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $this->role();
        $handoff = app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'actor-bound');
        $actors = [];
        for ($i = 0; $i < 100; $i++) {
            $actors[] = $this->distinctQaReviewer();
        }
        $requests = array_map(fn ($actor) => ['key' => 'actor-'.$actor->id, 'actor_id' => $actor->id, 'purposes' => ['qa.read', 'qa.source.read'], 'required' => true], $actors);
        app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.handoff.manage', 'qa.source.read'], false, function ($proofs) use ($actors, $handoff) {
            $template = (array) DB::table('qa_handoff_events')->where('handoff_id', $handoff['id'])->first();
            foreach ($actors as $i => $actor) {
                $row = array_replace($template, ['id' => (string) Str::uuid(), 'revision' => $i + 2, 'event_type' => 'context_added', 'actor_id' => $actor->id, 'authority_proof' => QaContract::canonical($proofs['actor-'.$actor->id]['proof'])]);
                DB::table('qa_handoff_events')->insert($row);
                DB::table('qa_handoffs')->where('id', $handoff['id'])->update(['revision' => $row['revision'], 'current_event_id' => $row['id']]);
            }
        }, $requests);
        $small = app(QaReadService::class)->history($this->actor(), $this->p, 'handoff', $handoff['id'], 10);
        $this->assertSame('complete', $small['coverage_status']);
        $large = app(QaReadService::class)->history($this->actor(), $this->p, 'handoff', $handoff['id'], 100);
        $this->assertSame('unknown', $large['coverage_status']);
        $this->assertContains('coverage_limit', $large['reason_codes']);
    }

    public function test_legal_queue_dependency_expansion_is_unknown_without_terminal_revocation(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $this->role();
        $department = $this->department();
        $handoff = app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'queue-actor-bound');
        $actors = [];
        for ($i = 0; $i < 100; $i++) {
            $actor = $this->distinctQaReviewer();
            $actors[] = $actor;
            $this->qaRun(function () use ($actor, $department) {
                $employee = DB::table('users')->where('id', $actor->id)->value('employee_id');
                DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
            });
        }
        $requests = array_map(fn ($actor) => ['key' => 'actor-'.$actor->id, 'actor_id' => $actor->id, 'purposes' => ['qa.read', 'qa.source.read'], 'required' => true], $actors);
        app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.handoff.manage', 'qa.source.read'], false, function ($proofs) use ($actors, $handoff) {
            $root = (array) DB::table('qa_handoffs')->find($handoff['id']);
            $receipt = (array) DB::table('qa_receipts')->find($root['receipt_id']);
            $event = (array) DB::table('qa_handoff_events')->find($root['current_event_id']);
            foreach (array_chunk($actors, 2) as $pair) {
                $id = (string) Str::uuid();
                $rid = (string) Str::uuid();
                $eid = (string) Str::uuid();
                DB::table('qa_receipts')->insert(array_replace($receipt, ['id' => $rid, 'object_id' => $id, 'actor_id' => $pair[0]->id, 'authority_proof' => QaContract::canonical($proofs['actor-'.$pair[0]->id]['proof'])]));
                DB::table('qa_handoffs')->insert(array_replace($root, ['id' => $id, 'receipt_id' => $rid, 'current_event_id' => null, 'revision' => 0]));
                DB::table('qa_handoff_events')->insert(array_replace($event, ['id' => $eid, 'handoff_id' => $id, 'actor_id' => $pair[1]->id, 'authority_proof' => QaContract::canonical($proofs['actor-'.$pair[1]->id]['proof'])]));
                DB::table('qa_handoffs')->where('id', $id)->update(['revision' => 1, 'current_event_id' => $eid]);
            }
        }, $requests);
        foreach ([null, $department] as $scope) {
            $small = app(QaReadService::class)->queue($this->actor(), $this->p, 10, department: $scope);
            $this->assertSame('complete', $small['coverage_status']);
            $this->assertSame(['current'], array_values(array_unique(array_column($small['items'], 'applicability'))));
            $before = $this->qaRun(fn () => DB::table('qa_applicability')->where('project_id', $this->p)->where('status', '<>', 'current')->count());
            $large = app(QaReadService::class)->queue($this->actor(), $this->p, 50, department: $scope);
            $this->assertSame('unknown', $large['coverage_status']);
            $this->assertSame(['coverage_limit'], $large['reason_codes']);
            $this->assertSame([], $large['items']);
            $this->assertNull($large['visible_record_count']);
            $this->assertSame($before, $this->qaRun(fn () => DB::table('qa_applicability')->where('project_id', $this->p)->where('status', '<>', 'current')->count()));
        }
    }

    public function test_critical_inventory_is_bounded_after_department_filtering(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $department = $this->department();
        $report = $this->defectReport($body);
        $report['body']['severity'] = 'critical';
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $report, 'bug-a');
        $other = $this->distinctQaReviewer();
        $this->qaRun(fn () => DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $other->id, 'policy_version_id' => $this->policy, 'purpose' => 'qa.defect.review', 'scope_type' => 'project', 'valid_from' => now()->subDay()]));
        $hidden = $this->defectReport($body, key: 'hidden-critical');
        $hidden['body']['severity'] = 'critical';
        $hidden = app(QaDefectService::class)->report($other, $this->p, $hidden, 'hidden-critical');
        $smallQueries = $this->measuredRead($department, 'visible1_hidden1')[1];
        $this->cloneCritical($defect['id'], 99);
        [$visible, $largeQueries] = $this->measuredRead($department, 'visible100_hidden1');
        $this->assertSame($smallQueries, $largeQueries, 'Critical inventory SQL count must not grow per defect.');
        $this->assertCount(100, $visible['critical_signals']['items']);
        $this->assertFalse($visible['critical_signals']['continuation_required']);
        $this->assertSame(0, $visible['critical_signals']['omitted_count']);
        $this->assertStringNotContainsString($hidden['id'], json_encode($visible));
        $project = $this->read();
        $this->assertSame('unknown', $project['coverage_status']);
        $this->assertTrue($project['critical_signals']['continuation_required']);
        $this->cloneCritical($defect['id'], 1);
        $overflow = app(QaReadService::class)->verification($this->actor(), $this->p, $department);
        $this->assertSame('unknown', $overflow['coverage_status']);
        $this->assertTrue($overflow['critical_signals']['continuation_required']);
        $this->assertSame([], $overflow['critical_signals']['items']);
        $this->assertStringNotContainsString($hidden['id'], json_encode($overflow));
    }

    public function test_closed_duplicate_does_not_clear_an_unresolved_canonical_defect(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $canonical = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body, key: 'canonical'), 'canonical');
        $this->defectTransition($canonical['id'], 1, 'triaged', []);
        $report = $this->defectReport($body);
        $report['body']['severity'] = 'critical';
        $duplicate = app(QaDefectService::class)->report($this->actor(), $this->p, $report, 'bug-a');
        $fix = $this->fixedDefect($body, $duplicate);
        $this->defectTransition($duplicate['id'], 3, 'duplicate', ['canonical_defect_id' => $canonical['id']]);
        $pass = $this->execution($body + ['fix_request_event_id' => $fix['id']]);
        $this->defectTransition($duplicate['id'], 4, 'verified_closed', ['execution_id' => $pass['record_id'], 'fix_request_event_id' => $fix['id'], 'selection_id' => $this->currentQaSelection()]);
        $this->assertContains($duplicate['id'], array_column($this->read()['critical_signals']['items'], 'defect_id'));
    }

    private function cloneCritical(string $id, int $count): void
    {
        app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.defect.review', 'qa.source.read'], false, function () use ($id, $count) {
            $root = (array) DB::table('qa_defects')->find($id);
            $receipt = (array) DB::table('qa_receipts')->find($root['receipt_id']);
            $event = (array) DB::table('qa_defect_events')->find($root['current_event_id']);
            for ($i = 0; $i < $count; $i++) {
                $did = (string) Str::uuid();
                $rid = (string) Str::uuid();
                $eid = (string) Str::uuid();
                $envelope = json_decode($receipt['envelope'], true);
                $envelope['object_id'] = $envelope['body']['logical_key'] = $did;
                DB::table('qa_receipts')->insert(array_replace($receipt, ['id' => $rid, 'object_id' => $did, 'envelope' => QaContract::canonical($envelope), 'payload_hash' => hash('sha256', QaContract::canonical($envelope))]));
                DB::table('qa_source_heads')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'source_instance_id' => $receipt['source_instance_id'], 'object_id' => $did, 'source_revision' => 1, 'revision' => 1, 'receipt_id' => $rid]);
                DB::table('qa_defects')->insert(array_replace($root, ['id' => $did, 'logical_key' => $did, 'receipt_id' => $rid, 'current_event_id' => null, 'revision' => 0]));
                DB::table('qa_defect_events')->insert(array_replace($event, ['id' => $eid, 'defect_id' => $did]));
                DB::table('qa_defects')->where('id', $did)->update(['revision' => 1, 'current_event_id' => $eid]);
            }
        });
    }

    public function test_restored_execution_proof_cannot_resolve_handoff_or_create_correction(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $pass = $this->execution($body);
        $handoff = app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'terminal-handoff');
        $grant = $this->qaRun(fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'qa.execution.record')->first());
        $this->revokeQaGrant('qa.execution.record');
        $this->read();
        $this->qaRun(fn () => DB::table('access_grants')->where('id', $grant->id)->update(['revision' => $grant->revision, 'revoked_at' => null]));
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => app(QaHandoffService::class)->transition($this->actor(), $this->p, $handoff['id'], ['expected_revision' => 1, 'kind' => 'resolved', 'reason' => 'Synthetic terminal dependency', 'pins' => ['execution_id' => $pass['record_id']]]));
        $this->assertSame($before, $this->defectWriteCounts());
        $this->assertQaConflict(fn () => $this->execution($body, revision: 2, sourceRevision: 1, seriesRevision: 1));
        $this->assertSame($before, $this->defectWriteCounts());
        $fresh = $this->execution(array_replace($body, ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $this->qaExecutionRow($pass['record_id'])->attempt_id]), 'fresh-pass', seriesRevision: 1);
        $resolved = app(QaHandoffService::class)->transition($this->actor(), $this->p, $handoff['id'], ['expected_revision' => 1, 'kind' => 'resolved', 'reason' => 'Synthetic fresh dependency', 'pins' => ['execution_id' => $fresh['record_id']]]);
        $this->assertSame('resolved', $resolved['state']);
    }

    public function test_restored_fix_request_proof_cannot_authorize_a_new_execution(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $report = $this->defectReport($body);
        $report['body']['severity'] = 'critical';
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $report, 'bug-a');
        $fixed = $this->fixedDefect($body, $defect);
        $grant = $this->qaRun(fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'qa.defect.review')->first());
        $this->revokeQaGrant('qa.defect.review');
        $this->read();
        $this->qaRun(fn () => DB::table('access_grants')->where('id', $grant->id)->update(['revision' => $grant->revision, 'revoked_at' => null]));
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => $this->execution($body + ['fix_request_event_id' => $fixed['id']]));
        $this->assertSame($before, $this->defectWriteCounts());
    }

    public function test_terminal_unrelated_defect_does_not_poison_healthy_subject_closure(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $a = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'bug-a');
        $fix = $this->fixedDefect($body, $a);
        $pass = $this->execution($body + ['fix_request_event_id' => $fix['id']]);
        $other = $this->distinctQaReviewer();
        $grant = (string) Str::uuid();
        $this->qaRun(fn () => DB::table('access_grants')->insert(['id' => $grant, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $other->id, 'policy_version_id' => $this->policy, 'purpose' => 'qa.defect.review', 'scope_type' => 'project', 'valid_from' => now()->subDay()]));
        $report = $this->defectReport($body, key: 'unrelated');
        $report['body']['severity'] = 'critical';
        $b = app(QaDefectService::class)->report($other, $this->p, $report, 'unrelated');
        $this->qaRun(fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => 2]));
        $this->assertContains($b['id'], array_column($this->read()['critical_signals']['items'], 'defect_id'));
        $this->qaRun(fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => null, 'revision' => 1]));
        $this->assertContains('critical_defect_applicability_unknown', $this->read()['critical_signals']['codes']);
        $closed = $this->defectTransition($a['id'], 3, 'verified_closed', ['execution_id' => $pass['record_id'], 'fix_request_event_id' => $fix['id'], 'selection_id' => $this->currentQaSelection()]);
        $this->assertSame('verified_closed', $closed['state']);
    }

    private function department(): string
    {
        $id = (string) Str::uuid();
        $this->qaRun(function () use ($id) {
            DB::table('departments')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'name' => 'Synthetic final regression']);
            DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $id, 'employee_id' => $this->employee, 'valid_from' => now()->subDay()]);
        });
        $this->grant('qa.department.read', department: $id);

        return $id;
    }

    private function measuredRead(?string $department, string $label): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = app(QaReadService::class)->verification($this->actor(), $this->p, $department);
            $queries = count(DB::getQueryLog());
            fwrite(STDOUT, 'QA_PUBLIC_READ '.$label.' queries='.$queries."\n");
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [$result, $queries];
    }

    private function read(): array
    {
        return app(QaReadService::class)->verification($this->actor(), $this->p);
    }

    private function role(): void
    {
        $this->qaRun(fn () => DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]));
    }
}
