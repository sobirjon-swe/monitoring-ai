<?php

namespace Tests\Feature;

use App\Domain\Lifecycle\RequirementService;
use App\Domain\Lifecycle\TaskService;
use App\Domain\Qa\QaDefectService;
use App\Domain\Qa\QaDefinitionService;
use App\Domain\Qa\QaEligibility;
use App\Domain\Qa\QaExecutionService;
use App\Domain\Qa\QaFixtureService;
use App\Domain\Qa\QaPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QaTestCase;

final class QaDefectTest extends QaTestCase
{
    public static function scopeStates(): array
    {
        return [['open'], ['triaged'], ['fixed_awaiting_retest'], ['reopened'], ['verified_closed']];
    }

    #[DataProvider('scopeStates')]
    public function test_scope_revision_preserves_history_and_requires_new_exact_request(string $state): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'bug-a');
        $revision = 1;
        $oldRequest = $oldPass = null;
        if ($state !== 'open') {
            $this->defectTransition($defect['id'], $revision++, 'triaged', []);
        }
        if (in_array($state, ['fixed_awaiting_retest', 'reopened', 'verified_closed'], true)) {
            $fixed = $this->defectTransition($defect['id'], $revision++, 'fix_requested', $this->affectedDefectPins($body) + ['fix_snapshot_id' => $this->qaFixSnapshot()]);
            $oldRequest = $fixed['id'];
            $oldPass = $this->execution($body + ['fix_request_event_id' => $oldRequest]);
            if ($state !== 'fixed_awaiting_retest') {
                $this->defectTransition($defect['id'], $revision++, 'verified_closed', ['execution_id' => $oldPass['record_id'], 'fix_request_event_id' => $oldRequest, 'selection_id' => $this->currentQaSelection()]);
                if ($state === 'reopened') {
                    $this->defectTransition($defect['id'], $revision++, 'reopened', ['cause' => 'regression', 'execution_id' => $oldPass['record_id']]);
                }
            }
        }
        $original = $this->qaDefectRow($defect['id']);
        $requirements = app(RequirementService::class);
        $draft = $this->qaRun(fn () => DB::table('requirement_drafts')->where('project_id', $this->p)->first());
        $data = json_decode($draft->content, true);
        $data['criteria'][0]['text'] = 'Synthetic replacement criterion';
        $requirements->updateDraft($this->actor(), $this->p, $draft->id, $data, 1);
        $approved = $requirements->approve($this->actor(), $this->p, $draft->id, 2, $this->generation, 'Synthetic scope revision');
        $this->criteria = $approved['criteria'];
        $this->generation = $approved['generation'];
        $this->baseline($this->scope());
        $definition = app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('definition', $this->definitionBody(revision: 2), 'test-a', 2), 'definition-2', 1);
        app(QaDefinitionService::class)->review($this->actor(), $this->p, $definition['record_id'], ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => 'Synthetic replacement review']);
        $task = $this->qaTask();
        $newBody = array_replace($body, ['definition_version_id' => $definition['record_id'], 'task_version_id' => $task['version_id']]);
        $replacement = $this->affectedDefectPins($newBody) + ['cause' => 'new_scope', 'fix_snapshot_id' => $this->qaFixSnapshot()];
        if ($state === 'verified_closed') {
            $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], $revision, 'scope_revised', $replacement));
        }
        $result = $this->defectTransition($defect['id'], $revision++, $state === 'verified_closed' ? 'reopened' : 'scope_revised', $replacement);
        $this->assertSame($state === 'verified_closed' ? 'reopened' : $state, $result['state']);
        $this->assertSame($original->criterion_version_id, $this->qaDefectRow($defect['id'])->criterion_version_id);
        $this->assertSame($original->definition_id, $this->qaDefectRow($defect['id'])->definition_id);
        $events = $this->qaRun(fn () => DB::table('qa_defect_events')->where('defect_id', $defect['id'])->orderBy('revision')->get());
        $this->assertSame($original->criterion_version_id, $events->first()->criterion_version_id);
        $this->assertSame($this->criteria[0]['version_id'], $events->last()->criterion_version_id);
        $this->assertSame('new_scope', $events->last()->cause);
        if ($state === 'fixed_awaiting_retest') {
            $before = $this->defectWriteCounts();
            $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], $revision, 'verified_closed', ['execution_id' => $oldPass['record_id'], 'fix_request_event_id' => $oldRequest, 'selection_id' => $this->currentQaSelection()]));
            $this->assertSame($before, $this->defectWriteCounts());
            $plan = $this->composeEntries([$this->planEntry(0, [['definition_version_id' => $definition['record_id'], 'source_instance_id' => $this->sourceId(), 'required' => true]], key: 'scope-entry-0'), $this->planEntry(1, key: 'scope-entry-1'), $this->planEntry(2, key: 'scope-entry-2')], 'scope-plan');
            app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic replacement plan']);
            $this->selectPlan($plan['id'], $body['build_id'], $body['environment_version_id'], 1);
            $unbound = $this->execution($newBody, object: 'new-scope-unbound');
            $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], $revision, 'verified_closed', ['execution_id' => $unbound['record_id'], 'fix_request_event_id' => $oldRequest, 'selection_id' => $this->currentQaSelection()]));
            $newFix = $this->defectTransition($defect['id'], $revision++, 'fix_requested', $this->affectedDefectPins($newBody) + ['fix_snapshot_id' => $this->qaFixSnapshot()]);
            $retest = $this->execution(array_replace($newBody, ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $this->qaExecutionRow($unbound['record_id'])->attempt_id]) + ['fix_request_event_id' => $newFix['id']], object: 'new-scope-retest', seriesRevision: 1);
            $this->assertSame('verified_closed', $this->defectTransition($defect['id'], $revision, 'verified_closed', ['execution_id' => $retest['record_id'], 'fix_request_event_id' => $newFix['id'], 'selection_id' => $this->currentQaSelection()])['state']);
        }
    }

    public function test_explicit_envelope_source_is_required_and_source_replay_alias_is_safe(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $report = $this->defectReport($body);
        try {
            app(QaDefectService::class)->report($this->actor(), $this->p, $report['body'], 'missing-source');
            $this->fail('Body-only report must not invent source facts.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $report, 'first');
        $before = $this->defectWriteCounts();
        $alias = app(QaDefectService::class)->report($this->actor(), $this->p, $report, 'alias');
        $this->assertSame($defect['id'], $alias['id']);
        $after = $this->defectWriteCounts();
        $before['qa_request_keys']++;
        $this->assertSame($before, $after);
    }

    public function test_duplicate_logical_key_on_a_new_source_object_conflicts_without_writes(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $report = $this->defectReport($body);
        app(QaDefectService::class)->report($this->actor(), $this->p, $report, 'first');
        $report['object_id'] = 'another-source-object';
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => app(QaDefectService::class)->report($this->actor(), $this->p, $report, 'second'));
        $this->assertSame($before, $this->defectWriteCounts());
    }

    public function test_initial_and_context_execution_links_require_the_exact_task_version(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $execution = $this->execution($body);
        $otherTask = $this->qaTask();
        $otherBody = array_replace($body, ['task_version_id' => $otherTask['version_id']]);
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($otherBody, $execution['record_id']), 'bad-task'));
        $this->assertSame($before, $this->defectWriteCounts());
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body, $execution['record_id']), 'exact-task');
        $correction = $this->execution($otherBody, revision: 2, sourceRevision: 1, seriesRevision: 1);
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], 1, 'context_added', ['execution_id' => $correction['record_id']]));
        $this->assertSame($before, $this->defectWriteCounts());
    }

    public function test_fresh_valid_retest_does_not_inherit_revoked_predecessor_authority(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $old = $this->execution(array_replace($body, ['outcome' => 'failed']));
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'bug');
        $fixed = $this->fixedDefect($body, $defect);
        $this->revokeQaGrant('qa.execution.record');
        $this->grant('qa.execution.record', $this->p);
        $fresh = $this->execution(array_replace($body, ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $this->qaExecutionRow($old['record_id'])->attempt_id]) + ['fix_request_event_id' => $fixed['id']], object: 'fresh', seriesRevision: 1);
        $this->assertSame('verified_closed', $this->defectTransition($defect['id'], 3, 'verified_closed', ['execution_id' => $fresh['record_id'], 'fix_request_event_id' => $fixed['id'], 'selection_id' => $this->currentQaSelection()])['state']);
    }

    public function test_unrelated_selected_defect_proof_stales_only_its_test_and_subject_proof_still_blocks_closure(): void
    {
        $this->qaInputs();
        [$a] = $this->executionInputs();
        $definitionB = $this->reviewedDefinition(1, 'test-b');
        $taskB = $this->qaTask(1);
        $b = array_replace($a, ['definition_version_id' => $definitionB['id'], 'task_version_id' => $taskB['version_id']]);
        $plan = $this->composeEntries([$this->planEntry(0, [['definition_version_id' => $a['definition_version_id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(1, [['definition_version_id' => $definitionB['id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(2)]);
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic two-subject plan']);
        $this->selectPlan($plan['id'], $a['build_id'], $a['environment_version_id'], 0);
        $defectA = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($a, key: 'a'), 'a');
        $fixA = $this->fixedDefect($a, $defectA);
        $reviewer = $this->distinctQaReviewer();
        $this->qaRun(fn () => DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $reviewer->id, 'policy_version_id' => $this->policy, 'purpose' => 'qa.defect.review', 'scope_type' => 'project', 'valid_from' => now()->subDay()]));
        $reportB = $this->defectReport($b, key: 'b');
        $reportB['body']['criterion_version_id'] = $this->criteria[1]['version_id'];
        $defectB = app(QaDefectService::class)->report($reviewer, $this->p, $reportB, 'b');
        app(QaDefectService::class)->transition($reviewer, $this->p, $defectB['id'], ['expected_revision' => 1, 'kind' => 'triaged', 'reason' => 'Synthetic B triage', 'pins' => new \stdClass]);
        $pinsB = array_replace($this->affectedDefectPins($b), ['criterion_version_id' => $this->criteria[1]['version_id'], 'fix_snapshot_id' => $this->qaFixSnapshot()]);
        $fixB = app(QaDefectService::class)->transition($reviewer, $this->p, $defectB['id'], ['expected_revision' => 2, 'kind' => 'fix_requested', 'reason' => 'Synthetic B request', 'pins' => $pinsB]);
        $passA = $this->execution($a + ['fix_request_event_id' => $fixA['id']], 'a-pass');
        $this->execution($b + ['fix_request_event_id' => $fixB['id']], 'b-pass');
        $this->qaRun(fn () => DB::table('access_grants')->where('user_id', $reviewer->id)->where('purpose', 'qa.defect.review')->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => DB::raw('revision+1')]));
        $eligible = $this->eligibilityInputs();
        $facts = app(QaEligibility::class)->inspectLocked($this->p, $eligible['inputs'], $eligible['proofs']);
        $criteria = collect($facts['criteria'])->keyBy('criterion_id');
        $this->assertSame('passed', $criteria[$this->criteria[0]['id']]['tests'][0]['status']);
        $this->assertSame('stale', $criteria[$this->criteria[1]['id']]['tests'][0]['status']);
        $this->assertSame('verified_closed', $this->defectTransition($defectA['id'], 3, 'verified_closed', ['execution_id' => $passA['record_id'], 'fix_request_event_id' => $fixA['id'], 'selection_id' => $this->currentQaSelection()])['state']);
        $this->defectTransition($defectA['id'], 4, 'reopened', ['cause' => 'regression', 'execution_id' => $passA['record_id']]);
        $this->defectTransition($defectA['id'], 5, 'triaged', []);
        $newFix = $this->defectTransition($defectA['id'], 6, 'fix_requested', ['fix_snapshot_id' => $this->qaFixSnapshot()]);
        $fresh = $this->execution(array_replace($a, ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $this->qaExecutionRow($passA['record_id'])->attempt_id]) + ['fix_request_event_id' => $newFix['id']], 'fresh-a', seriesRevision: 1);
        $this->revokeQaGrant('qa.defect.review');
        $this->grant('qa.defect.review', $this->p);
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => $this->defectTransition($defectA['id'], 7, 'verified_closed', ['execution_id' => $fresh['record_id'], 'fix_request_event_id' => $newFix['id'], 'selection_id' => $this->currentQaSelection()]));
        $this->assertSame($before, $this->defectWriteCounts());
    }

    public function test_context_and_duplicate_preserve_current_request_without_automatic_closure(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'bug-a');
        $canonical = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body, key: 'canonical'), 'canonical');
        $this->defectTransition($canonical['id'], 1, 'triaged', []);
        $fixed = $this->fixedDefect($body, $defect);
        $this->assertSame('fixed_awaiting_retest', $this->defectTransition($defect['id'], 3, 'context_added', [])['state']);
        $this->defectTransition($defect['id'], 4, 'duplicate', ['canonical_defect_id' => $canonical['id']]);
        $pass = $this->execution($body + ['fix_request_event_id' => $fixed['id']]);
        $this->assertSame('fixed_awaiting_retest', $this->qaDefectRow($defect['id'])->state);
        $this->assertSame('verified_closed', $this->defectTransition($defect['id'], 5, 'verified_closed', ['execution_id' => $pass['record_id'], 'fix_request_event_id' => $fixed['id'], 'selection_id' => $this->currentQaSelection()])['state']);
        $this->assertSame('duplicate', $this->qaDefectRow($defect['id'])->disposition);
    }

    public function test_invalid_current_pass_correction_has_no_authorized_historical_fallback(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'bug-a');
        $fixed = $this->fixedDefect($body, $defect);
        $body['fix_request_event_id'] = $fixed['id'];
        $original = $this->execution($body);
        $reviewer = $this->distinctQaReviewer();
        $this->qaRun(fn () => DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'user_id' => $reviewer->id, 'policy_version_id' => $this->policy, 'purpose' => 'qa.execution.record', 'scope_type' => 'project', 'project_id' => $this->p, 'valid_from' => now()->subDay()]));
        $envelope = $this->qaEnvelope('execution', $body, 'execution-1', 2);
        $corrected = app(QaExecutionService::class)->register($reviewer, $this->p, $envelope, 'correction', 1, 1);
        $this->qaRun(fn () => DB::table('access_grants')->where('user_id', $reviewer->id)->where('purpose', 'qa.execution.record')->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => 2]));
        $before = $this->defectWriteCounts();
        foreach ([$original['record_id'], $corrected['record_id']] as $execution) {
            $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], 3, 'verified_closed', ['execution_id' => $execution, 'fix_request_event_id' => $fixed['id'], 'selection_id' => $this->currentQaSelection()]));
        }
        $this->assertSame($before, $this->defectWriteCounts());
    }

    public function test_unrelated_or_removed_criterion_cannot_force_scope_or_closure(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'bug-a');
        $fixed = $this->fixedDefect($body, $defect);
        $pass = $this->execution($body + ['fix_request_event_id' => $fixed['id']]);
        $other = $this->reviewedDefinition(1, 'test-b');
        $task = $this->qaTask(1);
        $replacement = ['criterion_version_id' => $this->criteria[1]['version_id'], 'definition_version_id' => $other['id'], 'task_version_id' => $task['version_id'], 'build_id' => $body['build_id'], 'environment_version_id' => $body['environment_version_id'], 'cause' => 'new_scope', 'fix_snapshot_id' => $this->qaFixSnapshot()];
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], 3, 'scope_revised', $replacement));
        $this->assertSame($before, $this->defectWriteCounts());
        $sameCriterion = $this->reviewedDefinition(0, 'unrelated-test');
        $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], 3, 'scope_revised', array_replace($this->affectedDefectPins($body), ['definition_version_id' => $sameCriterion['id'], 'cause' => 'new_scope', 'fix_snapshot_id' => $replacement['fix_snapshot_id']])));
        $draft = $this->qaRun(fn () => DB::table('requirement_drafts')->where('project_id', $this->p)->first());
        $data = json_decode($draft->content, true);
        array_shift($data['criteria']);
        $requirements = app(RequirementService::class);
        $requirements->updateDraft($this->actor(), $this->p, $draft->id, $data, 1);
        $requirements->approve($this->actor(), $this->p, $draft->id, 2, $this->generation, 'Synthetic removed criterion');
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], 3, 'verified_closed', ['execution_id' => $pass['record_id'], 'fix_request_event_id' => $fixed['id'], 'selection_id' => $this->currentQaSelection()]));
        $this->assertSame($before, $this->defectWriteCounts());
    }

    public function test_changed_selection_and_unbound_pass_cannot_close_current_request(): void
    {
        $this->qaInputs();
        [$body, $plan] = $this->selectedExecutionInputs();
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'bug-a');
        $fixed = $this->fixedDefect($body, $defect);
        $pass = $this->execution($body);
        $pins = ['execution_id' => $pass['record_id'], 'fix_request_event_id' => $fixed['id'], 'selection_id' => $this->currentQaSelection()];
        $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], 3, 'verified_closed', $pins));
        $build = $this->qaBuild(2, 1);
        $this->selectPlan($plan['id'], $build['record_id'], $body['environment_version_id'], 1);
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], 3, 'verified_closed', $pins));
        $this->assertSame($before, $this->defectWriteCounts());
    }

    public function test_task_revision_requires_explicit_scope_and_fresh_request_before_closure(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'bug-a');
        $fixed = $this->fixedDefect($body, $defect);
        $pass = $this->execution($body + ['fix_request_event_id' => $fixed['id']]);
        $task = $this->qaRun(fn () => DB::table('task_versions')->find($body['task_version_id']));
        $revised = app(TaskService::class)->revise($this->actor(), $this->p, $task->task_id, ['title' => 'Synthetic revised task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic only', 'remaining_effort' => null, 'expected_test_keys' => ['test-a'], 'status' => 'in_progress', 'criterion_links' => [['criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id']]]], 1, $this->generation);
        $this->generation = $revised['generation'];
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], 3, 'verified_closed', ['execution_id' => $pass['record_id'], 'fix_request_event_id' => $fixed['id'], 'selection_id' => $this->currentQaSelection()]));
        $this->assertSame($before, $this->defectWriteCounts());
        $body['task_version_id'] = $revised['version_id'];
        $this->defectTransition($defect['id'], 3, 'scope_revised', $this->affectedDefectPins($body) + ['cause' => 'new_scope', 'fix_snapshot_id' => $this->qaFixSnapshot()]);
        $newFix = $this->defectTransition($defect['id'], 4, 'fix_requested', $this->affectedDefectPins($body) + ['fix_snapshot_id' => $this->qaFixSnapshot()]);
        $fresh = $this->execution(array_replace($body, ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $this->qaExecutionRow($pass['record_id'])->attempt_id]) + ['fix_request_event_id' => $newFix['id']], object: 'revised-task', seriesRevision: 1);
        $this->assertSame('verified_closed', $this->defectTransition($defect['id'], 5, 'verified_closed', ['execution_id' => $fresh['record_id'], 'fix_request_event_id' => $newFix['id'], 'selection_id' => $this->currentQaSelection()])['state']);
        $first = $this->qaRun(fn () => DB::table('qa_defect_events')->where('defect_id', $defect['id'])->orderBy('revision')->first());
        $this->assertSame($task->id, $first->task_version_id);
    }

    public function test_report_replay_and_exact_human_closure_preserve_initial_failure(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $failed = $this->execution(array_replace($body, ['outcome' => 'failed']));
        $report = $this->defectReport($body, $failed['record_id']);
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $report, 'bug-a');
        $before = $this->defectWriteCounts();
        $replay = app(QaDefectService::class)->report($this->actor(), $this->p, $report, 'bug-a');
        $this->assertSame($defect['id'], $replay['id']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($before, $this->defectWriteCounts());
        $fixed = $this->fixedDefect($body, $defect);
        $retest = $this->execution(array_replace($body, ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $this->qaExecutionRow($failed['record_id'])->attempt_id, 'fix_request_event_id' => $fixed['id']]), object: 'retest', seriesRevision: 1);
        $this->assertSame('fixed_awaiting_retest', $this->qaDefectRow($defect['id'])->state);
        $closed = $this->defectTransition($defect['id'], 3, 'verified_closed', ['execution_id' => $retest['record_id'], 'fix_request_event_id' => $fixed['id'], 'selection_id' => $this->currentQaSelection()]);
        $this->assertSame('verified_closed', $closed['state']);
        $this->assertSame('failed', $this->qaExecutionRow($failed['record_id'])->outcome);
        $this->assertSame(1, $this->verification()['counts']['numerator']);
        $this->assertQaConflict(fn () => $this->defectTransition($defect['id'], 4, 'reopened', []));
        $reopened = $this->defectTransition($defect['id'], 4, 'reopened', ['cause' => 'regression', 'execution_id' => $retest['record_id']]);
        $this->assertSame('reopened', $reopened['state']);
        $this->assertSame('triaged', $this->defectTransition($defect['id'], 5, 'triaged', [])['state']);
    }

    public static function laterOutcomes(): array
    {
        return [['failed'], ['blocked'], ['correction']];
    }

    #[DataProvider('laterOutcomes')]
    public function test_old_pass_later_failure_block_and_current_correction_never_close(string $mode): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'bug');
        $fixed = $this->fixedDefect($body, $defect);
        $pass = $this->execution($body + ['fix_request_event_id' => $fixed['id']]);
        $this->historicalClose = [$defect['id'], 3, ['execution_id' => $pass['record_id'], 'fix_request_event_id' => $fixed['id'], 'selection_id' => $this->currentQaSelection()]];
        if ($mode === 'correction') {
            $this->execution(array_replace($body, ['outcome' => 'failed']) + ['fix_request_event_id' => $fixed['id']], revision: 2, sourceRevision: 1, seriesRevision: 1);
        } else {
            $this->execution(array_replace($body, ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $this->qaExecutionRow($pass['record_id'])->attempt_id, 'outcome' => $mode]) + ['fix_request_event_id' => $fixed['id']] + ($mode === 'blocked' ? ['blocked_reason' => 'Synthetic blocker'] : []), object: 'later', seriesRevision: 1);
        }
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => $this->closeUsingHistoricalPass());
        $this->assertSame($before, $this->defectWriteCounts());
    }

    public function test_unbound_pass_another_defect_and_old_request_do_not_close(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $a = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body, key: 'a'), 'a');
        $b = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body, key: 'b'), 'b');
        $fa = $this->fixedDefect($body, $a);
        $fb = $this->fixedDefect($body, $b);
        $pass = $this->execution($body + ['fix_request_event_id' => $fb['id']]);
        $pins = ['execution_id' => $pass['record_id'], 'fix_request_event_id' => $fa['id'], 'selection_id' => $this->currentQaSelection()];
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => $this->defectTransition($a['id'], 3, 'verified_closed', $pins));
        $this->assertSame($before, $this->defectWriteCounts());
        $new = $this->defectTransition($b['id'], 3, 'fix_requested', $this->affectedDefectPins($body) + ['fix_snapshot_id' => $this->qaFixSnapshot()]);
        $this->assertQaConflict(fn () => $this->defectTransition($b['id'], 4, 'verified_closed', array_replace($pins, ['fix_request_event_id' => $fb['id']])));
        $this->assertNotSame($new['id'], $fb['id']);
    }

    public function test_duplicate_is_separate_reviewed_disposition_without_cycles_or_hidden_failures(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $fail = $this->execution(array_replace($body, ['outcome' => 'failed']));
        $a = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body, key: 'a'), 'a');
        $b = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body, key: 'b'), 'b');
        $this->assertQaConflict(fn () => $this->defectTransition($a['id'], 1, 'duplicate', ['canonical_defect_id' => $b['id']]));
        $this->defectTransition($b['id'], 1, 'triaged', []);
        $this->assertQaConflict(fn () => $this->defectTransition($a['id'], 1, 'duplicate', ['canonical_defect_id' => $a['id']]));
        $this->defectTransition($a['id'], 1, 'duplicate', ['canonical_defect_id' => $b['id']]);
        $this->assertSame('open', $this->qaDefectRow($a['id'])->state);
        $this->defectTransition($a['id'], 2, 'triaged', []);
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => $this->defectTransition($b['id'], 2, 'duplicate', ['canonical_defect_id' => $a['id']]));
        $this->assertSame($before, $this->defectWriteCounts());
        $this->assertSame('failed', $this->qaExecutionRow($fail['record_id'])->outcome);
        $this->assertNotEmpty($this->verification()['critical_signals'] ?? $this->verification()['counts']);
    }
}
