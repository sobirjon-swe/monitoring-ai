<?php

namespace Tests\Feature;

use App\Domain\Qa\QaDefectService;
use App\Domain\Qa\QaHandoffService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\QaTestCase;

final class QaHandoffTest extends QaTestCase
{
    public static function changedHeads(): array
    {
        return [['handoff_history'], ['handoff_replay'], ['handoff_decision'], ['defect_replay'], ['defect_decision']];
    }

    #[DataProvider('changedHeads')]
    public function test_prefetched_parent_head_change_cannot_deliver_or_decide_with_uncaptured_original_proof(string $operation): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $execution = $this->execution($body);
        $reviewer = $this->distinctQaReviewer();
        $this->qaRun(function () use ($reviewer) {
            foreach (['qa.handoff.manage', 'qa.defect.review'] as $purpose) {
                DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $reviewer->id, 'policy_version_id' => $this->policy, 'purpose' => $purpose, 'scope_type' => 'project', 'valid_from' => now()->subDay()]);
            }
        });
        $data = $this->handoffData($body);
        $report = $this->defectReport($body, key: 'race-defect');
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $report, 'race-defect');
        if ($operation === 'defect_decision') {
            $canonical = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body, key: 'canonical'), 'canonical');
            $this->defectTransition($canonical['id'], 1, 'triaged', []);
            $childMode = 'defect';
            $childPins = ['defect_id' => $canonical['id'], 'event' => ['expected_revision' => 2, 'kind' => 'context_added', 'reason' => 'Synthetic concurrent canonical event', 'pins' => new \stdClass]];
            $call = fn () => $this->defectTransition($defect['id'], 1, 'duplicate', ['canonical_defect_id' => $canonical['id']]);
        } elseif ($operation === 'defect_replay' || $operation === 'handoff_decision') {
            $childMode = 'defect';
            $childPins = ['defect_id' => $defect['id'], 'event' => ['expected_revision' => 1, 'kind' => 'context_added', 'reason' => 'Synthetic concurrent defect event', 'pins' => new \stdClass]];
            if ($operation === 'defect_replay') {
                $call = fn () => app(QaDefectService::class)->report($this->actor(), $this->p, $report, 'race-defect-alias');
            } else {
                $data['body']['defect_id'] = $defect['id'];
                $this->handoffId = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'race-handoff')['id'];
                $call = fn () => $this->handoffTransition(1, 'context_added');
            }
        } else {
            $this->handoffId = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'race-handoff')['id'];
            $this->handoffTransition(1, 'acknowledged');
            $this->handoffTransition(2, 'context_added');
            $childMode = 'handoff';
            $childPins = ['handoff_id' => $this->handoffId, 'event' => ['expected_revision' => 3, 'kind' => 'context_added', 'reason' => 'Synthetic concurrent linked execution', 'pins' => ['execution_id' => $execution['record_id']]]];
            $call = $operation === 'handoff_history' ? fn () => app(QaHandoffService::class)->history($this->actor(), $this->p, $this->handoffId, 1) : fn () => app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'race-handoff-alias');
        }
        DB::commit(); // The guarded child needs committed synthetic fixtures; preserve them after the regression.
        DB::beginTransaction();
        $changed = false;
        $afterAppend = [];
        DB::listen(function ($query) use (&$changed, &$afterAppend, $reviewer, $operation, $childMode, $childPins) {
            if ($changed || $query->sql !== "select nullif(current_setting('app.qa_authority_closed',true),'')") {
                return;
            }
            // QaCoordination's entry check runs after graph capture, before the parent project lock.
            $changed = true;
            $input = new InputStream;
            $child = new Process([PHP_BINARY, base_path('tests/Support/qa-cas-child.php'), $this->tenant, $reviewer->id, $this->p, $childMode, json_encode($childPins, JSON_THROW_ON_ERROR)], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'monitoring_ai_test', 'DB_URL' => ''], null, 20);
            $child->setInput($input);
            try {
                $child->start();
                $deadline = microtime(true) + 10;
                while (! str_contains($child->getOutput(), 'ready') && $child->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertSame(1, preg_match('/ready pid=([0-9]+)/', $child->getOutput(), $pid), $child->getErrorOutput());
                $input->write("go\n");
                $input->close();
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                $this->assertStringContainsString('revision=', $child->getOutput());
                file_put_contents(base_path('../.superpowers/sdd/2026-10-06-e05-qa-handoff-plan/task-4-fix-1-retained-fixtures.jsonl'), json_encode(['tenant_id' => $this->tenant, 'project_id' => $this->p, 'actor_id' => $reviewer->id, 'operation' => $operation, 'pg_pid' => (int) $pid[1]], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
            } finally {
                $input->close();
                if ($child->isRunning()) {
                    $child->stop(0);
                }
            }
            $this->qaRun(fn () => DB::table('access_grants')->where('user_id', $reviewer->id)->where('purpose', $childMode === 'handoff' ? 'qa.handoff.manage' : 'qa.defect.review')->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => DB::raw('revision+1')]));
            if ($childMode === 'handoff') {
                $this->revokeQaGrant('qa.execution.record');
            }
            $afterAppend = $this->defectWriteCounts();
        });
        try {
            $this->assertQaConflict($call);
            $this->assertTrue($changed, 'The child must append after prefetch, before parent coordination.');
            $this->assertSame($afterAppend, $this->defectWriteCounts());
            $this->assertQaConflict($call); // A fresh graph sees the revoked new original proof and also denies.
            $this->assertSame($afterAppend, $this->defectWriteCounts());
        } finally {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            DB::beginTransaction();
        }
    }

    public function test_handoff_event_time_history_and_oldest_age_survive_owner_and_role_change(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $role = (string) Str::uuid();
        $this->qaRun(fn () => DB::table('role_assignments')->insert(['id' => $role, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]));
        $identity = (string) Str::uuid();
        $mapping = (string) Str::uuid();
        $this->qaRun(function () use ($identity, $mapping) {
            DB::table('identities')->insert(['id' => $identity, 'tenant_id' => $this->tenant, 'source_instance_id' => $this->envelopeSource(), 'external_user_id' => 'synthetic-source-actor']);
            DB::table('identity_mappings')->insert(['id' => $mapping, 'tenant_id' => $this->tenant, 'identity_id' => $identity, 'employee_id' => $this->employee, 'status' => 'approved', 'changed_by' => $this->user, 'reviewer_id' => $this->user, 'reviewed_at' => now(), 'reason' => 'Synthetic approved mapping', 'valid_from' => now()->subDay()]);
        });
        $data = $this->handoffData($body);
        $data['body']['actor_identity_id'] = $identity;
        $opened = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'handoff-a');
        $this->handoffId = $opened['id'];
        $before = $this->handoffHistory();
        $this->assertSame($this->employee, $before[0]['responsibility']['employee_id']);
        $this->assertSame($role, $before[0]['responsibility']['roles'][0]['id']);
        $this->assertSame($mapping, $before[0]['responsibility']['mapping']['id']);
        $owner = (string) Str::uuid();
        $this->qaRun(function () use ($role, $owner, $mapping) {
            DB::table('role_assignments')->where('id', $role)->update(['valid_to' => DB::raw('clock_timestamp()'), 'revision' => 2]);
            DB::table('employees')->insert(['id' => $owner, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic next owner']);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $owner, 'valid_from' => now()->subDay()]);
            DB::table('identity_mappings')->where('id', $mapping)->update(['valid_to' => DB::raw('clock_timestamp()'), 'revision' => 2]);
        });
        $this->handoffTransition(1, 'owner_changed', ['owner_id' => $owner]);
        $this->handoffTransition(2, 'acknowledged');
        $history = $this->handoffHistory();
        $this->assertSame($before[0]['responsibility'], $history[0]['responsibility']);
        $this->assertSame($owner, $history[1]['owner_id']);
        $this->assertSame('elapsed_utc', $this->handoffAge()['age_basis']);
        $this->assertNull($this->handoffAge()['business_age']);
        $this->assertSame($before[0]['opened_at'], $history[2]['opened_at']);
        $this->handoffTransition(3, 'context_added');
        $this->assertSame('acknowledged', $this->handoffHistory()[3]['state']);
        $execution = $this->execution($body);
        $this->assertSame('resolved', $this->handoffTransition(4, 'resolved', ['execution_id' => $execution['record_id']])['state']);
        $firstPage = app(QaHandoffService::class)->history($this->actor(), $this->p, $this->handoffId, 1);
        $this->assertSame('1', $firstPage['next_cursor']);
        $secondPage = app(QaHandoffService::class)->history($this->actor(), $this->p, $this->handoffId, 1, $firstPage['next_cursor']);
        $this->assertSame(2, $secondPage['events'][0]['revision']);
    }

    public static function linkedEvidence(): array
    {
        return [['page_execution'], ['defect']];
    }

    #[DataProvider('linkedEvidence')]
    public function test_original_linked_evidence_authority_is_checked_before_history_or_decision(string $mode): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $data = $this->handoffData($body);
        if ($mode === 'defect') {
            $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'linked');
            $data['body']['defect_id'] = $defect['id'];
        }
        $this->handoffId = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'linked')['id'];
        if ($mode === 'page_execution') {
            $execution = $this->execution($body);
            $this->handoffTransition(1, 'resolved', ['execution_id' => $execution['record_id']]);
            $this->revokeQaGrant('qa.execution.record');
            $this->grant('qa.execution.record', $this->p);
            $this->assertQaConflict(fn () => $this->handoffHistory());
        } else {
            $this->revokeQaGrant('qa.defect.review');
            $this->grant('qa.defect.review', $this->p);
            $before = $this->defectWriteCounts();
            $this->assertQaConflict(fn () => $this->handoffTransition(1, 'acknowledged'));
            $this->assertSame($before, $this->defectWriteCounts());
        }
    }

    public function test_missing_source_and_unmapped_actor_remain_explicit(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $data = $this->handoffData($body);
        try {
            app(QaHandoffService::class)->open($this->actor(), $this->p, $data['body'], 'missing');
            $this->fail('No implicit source selection is allowed.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $role = (string) Str::uuid();
        $identity = (string) Str::uuid();
        $this->qaRun(function () use ($role, $identity) {
            DB::table('role_assignments')->insert(['id' => $role, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]);
            DB::table('identities')->insert(['id' => $identity, 'tenant_id' => $this->tenant, 'source_instance_id' => $this->envelopeSource(), 'external_user_id' => 'unmapped']);
        });
        $data['body']['actor_identity_id'] = $identity;
        $this->handoffId = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'unknown')['id'];
        $this->assertSame('unknown', $this->handoffHistory()[0]['responsibility']['attribution']);
        $this->assertNull($this->handoffHistory()[0]['responsibility']['employee_id']);
        $this->assertTrue(app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'alias')['replayed']);
    }

    public function test_owner_eligibility_is_precollected_before_closed_authority_phase(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->handoffId = app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'precollected')['id'];
        $this->handoffTransition(1, 'owner_changed', ['owner_id' => $this->employee]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $closed = false;
        foreach ($queries as $query) {
            if (str_contains($query['query'], "set_config('app.qa_authority_closed','1'")) {
                $closed = true;
            }
            if ($closed) {
                $this->assertFalse((bool) preg_match('/from "(?:employees|project_memberships|access_grants|users)"/', $query['query']), 'Owner and actor authority must already be loaded before the callback.');
            }
            if (str_contains($query['query'], "set_config('app.qa_authority_closed',''")) {
                $closed = false;
            }
        }
        $this->assertSame('open', $this->handoffHistory()[1]['state']);
    }

    public function test_initial_execution_link_requires_the_exact_handoff_task_version(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $execution = $this->execution($body);
        $otherTask = $this->qaTask();
        $data = $this->handoffData(array_replace($body, ['task_version_id' => $otherTask['version_id']]));
        $data['body']['execution_id'] = $execution['record_id'];
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'bad-task'));
        $this->assertSame($before, $this->defectWriteCounts());
        $data = $this->handoffData($body);
        $data['body']['execution_id'] = $execution['record_id'];
        $this->handoffId = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'exact-task')['id'];
        $this->assertSame($execution['record_id'], $this->handoffHistory()[0]['execution_id']);
    }

    public static function defectSubjects(): array
    {
        return [['task'], ['criterion']];
    }

    #[DataProvider('defectSubjects')]
    public function test_initial_defect_link_requires_exact_affected_task_and_criterion(string $subject): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'subject');
        $otherTask = $this->qaTask($subject === 'criterion' ? 1 : 0);
        $data = $this->handoffData(array_replace($body, ['task_version_id' => $otherTask['version_id']]));
        $data['body']['defect_id'] = $defect['id'];
        if ($subject === 'criterion') {
            $data['body']['criterion_version_id'] = $this->criteria[1]['version_id'];
        }
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'wrong-subject'));
        $this->assertSame($before, $this->defectWriteCounts());
        $data = $this->handoffData($body);
        $data['body']['defect_id'] = $defect['id'];
        $this->handoffId = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'exact-subject')['id'];
        $event = $this->qaDefectRow($defect['id'])->current_event_id;
        $this->assertSame('resolved', $this->handoffTransition(1, 'resolved', ['defect_event_id' => $event])['state']);
    }

    public function test_resolved_is_terminal_except_context_and_direct_resolve_requires_evidence(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $execution = $this->execution($body);
        $opened = app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'handoff-a');
        $this->handoffId = $opened['id'];
        $this->assertQaConflict(fn () => $this->handoffTransition(1, 'resolved'));
        $this->assertSame('resolved', $this->handoffTransition(1, 'resolved', ['execution_id' => $execution['record_id']])['state']);
        $before = $this->defectWriteCounts();
        $this->assertQaConflict(fn () => $this->handoffTransition(2, 'acknowledged'));
        $this->assertQaConflict(fn () => $this->handoffTransition(2, 'owner_changed', ['owner_id' => $this->employee]));
        $this->assertSame($before, $this->defectWriteCounts());
        $this->assertSame('resolved', $this->handoffTransition(2, 'context_added')['state']);
    }

    public function test_handoff_unknown_attribution_replay_and_revoked_original_proof_are_explicit(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $data = $this->handoffData($body);
        $data['occurred_at'] = $data['source_updated_at'] = $data['body']['occurred_at'] = '2026-10-06T00:00:00.000000Z';
        $opened = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'unknown');
        $this->handoffId = $opened['id'];
        $this->assertSame('unknown', $this->handoffHistory()[0]['responsibility']['attribution']);
        $before = $this->defectWriteCounts();
        $this->assertTrue(app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'unknown')['replayed']);
        $this->assertSame($before, $this->defectWriteCounts());
        $this->revokeQaGrant('qa.handoff.manage');
        $this->grant('qa.handoff.manage', $this->p);
        $this->assertQaConflict(fn () => $this->handoffTransition(1, 'acknowledged'));
    }
}
