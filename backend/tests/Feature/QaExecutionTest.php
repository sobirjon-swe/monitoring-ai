<?php

namespace Tests\Feature;

use App\Domain\Qa\QaContract;
use App\Domain\Qa\QaCoordination;
use App\Domain\Qa\QaExecutionService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QaTestCase;

final class QaExecutionTest extends QaTestCase
{
    public function test_execution_replay_correction_and_retest_keep_exact_immutable_heads(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $first = $this->execution($body);
        $counts = $this->qaCounts();
        $replay = $this->execution($body);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['record_id'], $replay['record_id']);
        $this->assertSame($counts, $this->qaCounts());
        $corrected = $this->execution(array_replace($body, ['outcome' => 'failed']), revision: 3, sourceRevision: 1, seriesRevision: 1);
        $late = $this->execution(array_replace($body, ['outcome' => 'blocked', 'blocked_reason' => 'Synthetic blocker']), revision: 2, sourceRevision: 2, seriesRevision: 1);
        $this->assertFalse($late['current']);
        $attempt = $this->executionRow($first['record_id']);
        $head = $this->qaRun(fn () => DB::table('qa_execution_attempts')->find($attempt->attempt_id));
        $this->assertSame($corrected['record_id'], $head->current_version_id);
        $this->assertSame(3, $head->source_revision);
        $retest = $this->execution(array_replace($body, ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $head->id]), object: 'execution-2', seriesRevision: 1);
        $this->assertNotSame($first['record_id'], $retest['record_id']);
        $this->qaRun(function () use ($attempt, $retest) {
            $series = DB::table('qa_execution_series')->find($attempt->series_id);
            $this->assertSame(2, $series->revision);
            $this->assertSame(2, $series->current_ordinal);
            $this->assertSame($this->executionRow($retest['record_id'])->attempt_id, $series->current_attempt_id);
            $this->assertSame(2, DB::table('qa_execution_attempts')->where('series_id', $series->id)->count());
        });
    }

    public function test_conflicting_replay_identity_switch_and_order_gaps_roll_back_every_write(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $first = $this->execution($body);
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => $this->execution(array_replace($body, ['outcome' => 'failed'])));
        $this->assertQaConflict(fn () => $this->execution(array_replace($body, ['attempt_ordinal' => 2]), revision: 2, sourceRevision: 1, seriesRevision: 1));
        $this->assertQaConflict(fn () => $this->execution(array_replace($body, ['attempt_ordinal' => 3, 'predecessor_attempt_id' => $this->executionRow($first['record_id'])->attempt_id]), object: 'gap', seriesRevision: 1));
        $this->assertQaConflict(fn () => $this->execution(array_replace($body, ['attempt_ordinal' => 2]), object: 'missing-predecessor', seriesRevision: 1));
        $this->assertSame($before, $this->qaCounts());
    }

    public function test_new_build_series_starts_at_one_with_separate_historical_retest_origin(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $first = $this->execution($body);
        $oldAttempt = $this->executionRow($first['record_id'])->attempt_id;
        $build = $this->qaBuild(2, 1);
        $new = array_replace($body, ['build_id' => $build['record_id']]);
        $this->assertQaConflict(fn () => $this->execution($new, revision: 2, sourceRevision: 1, seriesRevision: 0));
        $this->assertQaConflict(fn () => $this->execution($new + ['predecessor_attempt_id' => $oldAttempt], object: 'wrong-series'));
        $next = $this->execution($new + ['retests_execution_id' => $oldAttempt], object: 'new-build');
        $this->assertNotSame($this->executionRow($first['record_id'])->series_id, $this->executionRow($next['record_id'])->series_id);
        $this->qaRun(function () use ($next, $oldAttempt) {
            $attempt = DB::table('qa_execution_attempts')->find($this->executionRow($next['record_id'])->attempt_id);
            $this->assertNull($attempt->predecessor_id);
            $this->assertSame($oldAttempt, $attempt->retests_execution_id);
        });
    }

    public function test_new_retest_has_independent_proof_after_old_registrar_grant_revocation(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $first = $this->execution($body);
        $attempt = $this->executionRow($first['record_id'])->attempt_id;
        $this->revokeQaGrant('qa.execution.record');
        $this->grant('qa.execution.record', $this->p);
        $this->assertQaConflict(fn () => $this->execution($body));
        $this->assertQaConflict(fn () => $this->execution(array_replace($body, ['outcome' => 'failed']), revision: 2, sourceRevision: 1, seriesRevision: 1));
        $next = $this->execution(array_replace($body, ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $attempt]), object: 'new-proof', seriesRevision: 1);
        $this->assertFalse($next['replayed']);
        $facts = $this->verification();
        $this->assertSame(1, $facts['counts']['numerator']);
        $this->assertSame('complete', $facts['coverage_status']);
    }

    public function test_execution_requires_current_source_authority_and_exact_task_criterion(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $definition = $this->reviewedDefinition(1, 'test-b');
        $this->assertQaConflict(fn () => $this->execution(array_replace($body, ['definition_version_id' => $definition['id']])));
        $before = $this->qaCounts();
        $this->revokeQaGrant('qa.execution.record');
        $this->assertDenied(fn () => $this->execution($body));
        $this->assertSame($before, $this->qaCounts());
    }

    public function test_execution_is_local_only_and_blocked_requires_a_reason(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        try {
            $this->execution(array_replace($body, ['outcome' => 'blocked']));
            $this->fail('Expected bounded blocked reason validation.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        app()->detectEnvironment(fn () => 'production');
        try {
            $this->expectException(\LogicException::class);
            $this->execution($body);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_historical_retest_origin_requires_same_stable_test_identity(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $old = $this->execution($body);
        $origin = $this->executionRow($old['record_id'])->attempt_id;
        $other = $this->reviewedDefinition(0, 'unrelated-test');
        $this->assertQaConflict(fn () => $this->execution(array_replace($body, ['definition_version_id' => $other['id'], 'retests_execution_id' => $origin]), object: 'unrelated-origin'));
    }

    public function test_execution_fix_request_must_match_exact_existing_current_defect_pins(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $request = $this->executionFixRequest($body);
        $wrong = $this->qaBuild(2, 1);
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => $this->execution(array_replace($body, ['build_id' => $wrong['record_id'], 'fix_request_event_id' => $request]), object: 'wrong-request'));
        $this->assertSame($before, $this->qaCounts());
        // A new series may retest an exact request at ordinal1, without a false cross-series predecessor.
        $valid = $this->execution(array_replace($body, ['build_id' => $wrong['record_id']]));
        $newBody = array_replace($body, ['build_id' => $wrong['record_id'], 'attempt_ordinal' => 2, 'predecessor_attempt_id' => $this->executionRow($valid['record_id'])->attempt_id]);
        $matching = $this->executionFixRequest($newBody, 'matching-fix');
        $registered = $this->execution($newBody + ['fix_request_event_id' => $matching], object: 'matching-request', seriesRevision: 1);
        $this->assertSame('passed', $this->executionRow($registered['record_id'])->outcome);
    }

    public function test_execution_captures_exact_event_time_responsibility_and_preserves_it_after_role_change(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $role = (string) Str::uuid();
        $this->qaRun(fn () => DB::table('role_assignments')->insert(['id' => $role, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]));
        $instant = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $envelope = $this->qaEnvelope('execution', $body, 'event-time');
        $envelope['occurred_at'] = $envelope['source_updated_at'] = $instant;
        $record = app(QaExecutionService::class)->register($this->actor(), $this->p, $envelope, 'event-time', 0, 0);
        $snapshot = json_decode($this->executionRow($record['record_id'])->responsibility, true);
        $this->assertSame('known', $snapshot['attribution']);
        $this->assertSame($this->employee, $snapshot['employee_id']);
        $this->assertSame($role, $snapshot['roles'][0]['id']);
        $this->assertSame($instant, $snapshot['occurred_at']);
        $this->qaRun(fn () => DB::table('role_assignments')->where('id', $role)->update(['valid_to' => DB::raw('clock_timestamp()'), 'revision' => 2]));
        $this->assertSame($snapshot, json_decode($this->executionRow($record['record_id'])->responsibility, true));
    }

    public function test_context_and_duplicate_after_fix_keep_the_exact_request_available_for_a_new_retest(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $request = $this->executionFixRequest($body);
        $this->appendRequestEvent($request, 'context_added');
        $canonical = $this->executionFixRequest($body, 'reviewed-canonical');
        $canonicalId = $this->qaRun(fn () => DB::table('qa_defect_events')->where('id', $canonical)->value('defect_id'));
        $this->appendRequestEvent($request, 'duplicate', ['disposition' => 'duplicate', 'canonical_defect_id' => $canonicalId]);
        $registered = $this->execution($body + ['fix_request_event_id' => $request], object: 'context-retest');
        $row = $this->executionRow($registered['record_id']);
        $this->assertSame('passed', $row->outcome);
        $this->qaRun(function () use ($row, $request) {
            $attempt = DB::table('qa_execution_attempts')->find($row->attempt_id);
            $defect = DB::table('qa_defects')->where('id', DB::table('qa_defect_events')->where('id', $request)->value('defect_id'))->first();
            $this->assertSame($request, $attempt->fix_request_event_id);
            $this->assertSame('fixed_awaiting_retest', $defect->state);
            $this->assertSame('duplicate', $defect->disposition);
            $this->assertNotSame($request, $defect->current_event_id);
        });
    }

    public function test_newest_fix_scope_and_reopen_boundaries_reject_an_old_request_without_writes(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $request = $this->executionFixRequest($body);
        $first = $this->execution($body + ['fix_request_event_id' => $request]);
        $attempt = $this->executionRow($first['record_id'])->attempt_id;
        $retestBody = array_replace($body, ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $attempt, 'fix_request_event_id' => $request]);
        $this->appendRequestEvent($request, 'scope_revised', ['cause' => 'new_scope']);
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => $this->execution($retestBody, object: 'old-scope-request', seriesRevision: 1));
        $this->assertSame($before, $this->qaCounts());
        $newRequest = $this->appendRequestEvent($request, 'fix_requested');
        $this->appendRequestEvent($request, 'context_added');
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => $this->execution($retestBody, object: 'old-fix-request', seriesRevision: 1));
        $this->assertSame($before, $this->qaCounts());
        $next = $this->execution(array_replace($retestBody, ['fix_request_event_id' => $newRequest]), object: 'new-fix-request', seriesRevision: 1);
        $this->assertSame('passed', $this->executionRow($next['record_id'])->outcome);
        $this->appendRequestEvent($request, 'verified_closed', ['state' => 'verified_closed', 'execution_id' => $next['record_id'], 'fix_request_event_id' => $newRequest]);
        $this->appendRequestEvent($request, 'reopened', ['state' => 'reopened', 'cause' => 'regression']);
        $this->appendRequestEvent($request, 'triaged', ['state' => 'triaged']);
        $afterReopenRequest = $this->appendRequestEvent($request, 'fix_requested', ['state' => 'fixed_awaiting_retest']);
        $this->appendRequestEvent($request, 'context_added');
        $third = array_replace($body, ['attempt_ordinal' => 3, 'predecessor_attempt_id' => $this->executionRow($next['record_id'])->attempt_id, 'fix_request_event_id' => $newRequest]);
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => $this->execution($third, object: 'old-reopen-request', seriesRevision: 2));
        $this->assertSame($before, $this->qaCounts());
        $valid = $this->execution(array_replace($third, ['fix_request_event_id' => $afterReopenRequest]), object: 'current-reopen-request', seriesRevision: 2);
        $this->assertSame('passed', $this->executionRow($valid['record_id'])->outcome);
        // Later request boundaries do not rewrite the immutable request of the first attempt.
        $replay = $this->execution($body + ['fix_request_event_id' => $request]);
        $this->assertTrue($replay['replayed']);
        $corrected = $this->execution(array_replace($body, ['outcome' => 'failed', 'fix_request_event_id' => $request]), revision: 2, sourceRevision: 1, seriesRevision: 3);
        $this->assertSame('failed', $this->executionRow($corrected['record_id'])->outcome);
    }

    public function test_fix_request_task_pin_rejects_an_unrelated_same_criterion_task_and_task_correction(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $request = $this->executionFixRequest($body);
        $otherTask = $this->qaTask();
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => $this->execution(array_replace($body, ['task_version_id' => $otherTask['version_id'], 'fix_request_event_id' => $request]), object: 'wrong-task'));
        $this->assertSame($before, $this->qaCounts());
        $registered = $this->execution($body + ['fix_request_event_id' => $request]);
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => $this->execution(array_replace($body, ['task_version_id' => $otherTask['version_id'], 'fix_request_event_id' => $request]), revision: 2, sourceRevision: 1, seriesRevision: 1));
        $this->assertSame($before, $this->qaCounts());
        $this->assertSame('passed', $this->executionRow($registered['record_id'])->outcome);
    }

    private function appendRequestEvent(string $request, string $kind, array $changes = []): string
    {
        return app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.defect.review', 'qa.source.read'], false, function ($proofs) use ($request, $kind, $changes) {
            $origin = DB::table('qa_defect_events')->find($request);
            $defect = DB::table('qa_defects')->find($origin->defect_id);
            $row = (array) DB::table('qa_defect_events')->find($defect->current_event_id);
            $row = array_replace($row, ['id' => (string) Str::uuid(), 'revision' => $defect->revision + 1, 'event_type' => $kind, 'reason' => 'Synthetic request boundary fixture', 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($proofs['current']['proof']), 'effective_at' => DB::raw('clock_timestamp()')], $changes);
            DB::table('qa_defect_events')->insert($row);
            DB::table('qa_defects')->where('id', $defect->id)->update(['revision' => $row['revision'], 'current_event_id' => $row['id'], 'state' => $row['state'], 'disposition' => $row['disposition'], 'canonical_defect_id' => $row['canonical_defect_id']]);

            return $row['id'];
        });
    }

    private function executionRow(string $id): object
    {
        return app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_execution_versions')->find($id));
    }
}
