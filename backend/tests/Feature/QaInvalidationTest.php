<?php

namespace Tests\Feature;

use App\Domain\Access\TrustedActor;
use App\Domain\Lifecycle\RequirementService;
use App\Domain\Lifecycle\TaskService;
use App\Domain\Qa\QaDefectService;
use App\Domain\Qa\QaDefinitionService;
use App\Domain\Qa\QaExecutionService;
use App\Domain\Qa\QaFixtureService;
use App\Domain\Qa\QaHandoffService;
use App\Domain\Qa\QaInvalidation;
use App\Domain\Qa\QaMaintenanceProcessor;
use App\Domain\Qa\QaPlanService;
use App\Domain\Qa\QaReadService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Process\Process;
use Tests\Support\QaTestCase;

class QaInvalidationTest extends QaTestCase
{
    public static function naturalReplayKinds(): array
    {
        return array_map(fn ($kind) => [$kind], ['build', 'environment', 'definition', 'execution', 'defect', 'handoff']);
    }

    public static function retainedReplayKinds(): array
    {
        return [...self::naturalReplayKinds(), ['entry'], ['plan']];
    }

    public static function mutableReplayKinds(): array
    {
        return [['defect'], ['handoff']];
    }

    #[DataProvider('mutableReplayKinds')]
    public function test_replay_checks_the_delivered_current_event_after_original_receipt(string $kind): void
    {
        $this->qaInputs();
        $case = $this->replayCase($kind);
        $row = app(TenantContext::class)->run($this->tenant, function () use ($kind, $case) {
            $receipt = DB::table('qa_request_keys')->where('project_id', $this->p)->where('key', $case['key'])->value('receipt_id');

            return DB::table($kind === 'defect' ? 'qa_defects' : 'qa_handoffs')->where('receipt_id', $receipt)->first();
        });
        $other = $this->distinctQaReviewer();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $other->id, 'policy_version_id' => $this->policy, 'purpose' => $case['purpose'], 'scope_type' => 'project', 'valid_from' => now()->subDay()]));
        $service = app($kind === 'defect' ? QaDefectService::class : QaHandoffService::class);
        $service->transition($other, $this->p, $row->id, ['expected_revision' => 1, 'kind' => 'context_added', 'reason' => 'Synthetic separate current event actor', 'pins' => new \stdClass]);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $other->id)->where('purpose', $case['purpose'])->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => DB::raw('revision+1')]));
        try {
            $case['invoke']($case['key']);
            $this->fail('A revoked current event proof must reject replay.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
        $this->assertGreaterThan(0, $this->invalidations());
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $other->id)->where('purpose', $case['purpose'])->update(['revoked_at' => null, 'revision' => DB::raw('revision-1')]));
        $before = $this->defectWriteCounts();
        $this->denied(fn () => $case['invoke']('terminal-current-event-alias'));
        $this->assertSame($before, $this->defectWriteCounts());
    }

    #[DataProvider('naturalReplayKinds')]
    public function test_terminal_natural_source_replay_cannot_bind_fresh_request_alias(string $kind): void
    {
        $this->qaInputs();
        $case = $this->replayCase($kind);
        $this->revokeQaGrant($case['purpose']);
        $this->denied(fn () => $case['invoke']($case['key']));
        $this->assertGreaterThan(0, $this->invalidations());
        $this->restoreQaPurpose($case['purpose']);
        $before = $this->defectWriteCounts();
        $this->denied(fn () => $case['invoke']('forbidden-fresh-alias'));
        $this->assertSame($before, $this->defectWriteCounts());
    }

    #[DataProvider('retainedReplayKinds')]
    public function test_own_denied_refresh_requires_no_current_read_or_operation_grant(string $kind): void
    {
        $this->qaInputs();
        $case = $this->replayCase($kind);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('project_id', $this->p)->where('purpose', 'like', 'qa.%')->whereNotIn('purpose', [$case['purpose'], 'qa.source.read'])->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => DB::raw('revision+1')]));
        $this->revokeQaGrant($case['purpose']);
        $this->denied(fn () => $case['invoke']($case['key']));
        $count = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_invalidations')->where('project_id', $this->p)->count());
        $this->assertGreaterThan(0, $count, 'Bounded own retained-output observation needs neither qa.read nor the revoked operation purpose.');
    }

    #[DataProvider('retainedReplayKinds')]
    public function test_observation_committed_between_unlocked_guard_and_replay_is_atomic(string $kind): void
    {
        $this->qaInputs();
        $case = $this->replayCase($kind);
        DB::commit();
        $barrier = true;
        $observed = false;
        $before = null;
        DB::listen(function ($query) use (&$barrier, &$observed, &$before, $case) {
            if (! $barrier || ! str_contains($query->sql, 'select exists') || ! str_contains($query->sql, 'qa_applicability')) {
                return;
            }
            $barrier = false;
            $child = new Process([PHP_BINARY, base_path('tests/Support/qa-maintenance-child.php'), $this->tenant, $this->user, $this->p, 'observe-restore', $case['operation'], $case['key'], $case['purpose']], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'monitoring_ai_test', 'DB_URL' => ''], null, 20);
            $child->run();
            $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
            $this->assertStringContainsString('observed=1', $child->getOutput());
            $observed = true;
            $before = $this->defectWriteCounts();
        });
        try {
            $this->denied(fn () => $case['invoke']($case['key']));
            $this->assertTrue($observed, 'A separate guarded child must commit terminal observation and exact grant restoration after the guard query.');
            $this->assertSame($before, $this->defectWriteCounts());
            file_put_contents(base_path('../.superpowers/sdd/2026-10-06-e05-qa-handoff-plan/task-5-retained-fixtures.jsonl'), json_encode(['project_id' => $this->p, 'tenant_id' => $this->tenant, 'barrier_replay_kind' => $kind], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        } finally {
            $barrier = false;
            while (DB::transactionLevel()) {
                DB::rollBack();
            } DB::beginTransaction();
        }
    }

    private function restoreQaPurpose(string $purpose): void
    {
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('project_id', $this->p)->where('purpose', $purpose)->update(['revoked_at' => null, 'revision' => DB::raw('revision-1')]));
    }

    private function replayCase(string $kind): array
    {
        if ($kind === 'entry') {
            $this->planEntry(0);
            $data = $this->entryInput(0);

            return ['purpose' => 'qa.plan.manage', 'operation' => 'plan.entry', 'key' => 'entry-0', 'invoke' => fn ($key) => app(QaPlanService::class)->createEntry($this->actor(), $this->p, $data, $key)];
        }
        if ($kind === 'plan') {
            $entries = [$this->planEntry(0), $this->planEntry(1), $this->planEntry(2)];
            $this->composeEntries($entries);
            $data = ['baseline_id' => $this->baselineId, 'entry_snapshot_ids' => array_column($entries, 'id')];

            return ['purpose' => 'qa.plan.manage', 'operation' => 'plan.compose', 'key' => 'compose', 'invoke' => fn ($key) => app(QaPlanService::class)->compose($this->actor(), $this->p, $data, $key)];
        }
        if (in_array($kind, ['build', 'environment', 'definition'], true)) {
            $record = match ($kind) {
                'build' => $this->qaBuild(),'environment' => $this->qaEnvironment(),'definition' => $this->reviewedDefinition()
            };
            $key = $kind === 'definition' ? 'test-a' : $kind.'-1';
            $receipt = $record['receipt_id'] ?? app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_definition_versions')->where('id', $record['id'])->value('receipt_id'));
            $data = app(TenantContext::class)->run($this->tenant, fn () => json_decode(DB::table('qa_receipts')->where('id', $receipt)->value('envelope'), true));

            return ['purpose' => 'qa.fixture.manage', 'operation' => $kind, 'key' => $key, 'invoke' => fn ($key) => app(QaFixtureService::class)->register($this->actor(), $this->p, $data, $key, 0)];
        }
        [$body] = $this->executionInputs();
        if ($kind === 'execution') {
            $record = $this->execution($body);
            $data = app(TenantContext::class)->run($this->tenant, fn () => json_decode(DB::table('qa_receipts')->where('id', $record['receipt_id'])->value('envelope'), true));

            return ['purpose' => 'qa.execution.record', 'operation' => 'execution', 'key' => 'execution-1-1', 'invoke' => fn ($key) => app(QaExecutionService::class)->register($this->actor(), $this->p, $data, $key, 0, 0)];
        }
        if ($kind === 'defect') {
            $data = $this->defectReport($body);
            app(QaDefectService::class)->report($this->actor(), $this->p, $data, 'bug-a');

            return ['purpose' => 'qa.defect.review', 'operation' => 'defect.report', 'key' => 'bug-a', 'invoke' => fn ($key) => app(QaDefectService::class)->report($this->actor(), $this->p, $data, $key)];
        }
        $data = $this->handoffData($body);
        app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'replay-handoff');

        return ['purpose' => 'qa.handoff.manage', 'operation' => 'handoff.open', 'key' => 'replay-handoff', 'invoke' => fn ($key) => app(QaHandoffService::class)->open($this->actor(), $this->p, $data, $key)];
    }

    public function test_old_selection_terminal_overlay_does_not_poison_fresh_selection(): void
    {
        $this->qaInputs();
        [$body,$plan] = $this->selectedExecutionInputs();
        $this->execution($body);
        $old = $this->currentQaSelection();
        $fresh = $this->selectPlan($plan['id'], $body['build_id'], $body['environment_version_id'], 1);
        app(QaMaintenanceProcessor::class)->drain($this->actor(), $this->p);
        $this->assertSame('stale', $this->qaRun(fn () => DB::table('qa_changes as c')->join('qa_applicability as a', 'a.change_id', '=', 'c.id')->where('c.selection_id', $old)->value('a.status')));
        $facts = app(QaReadService::class)->verification($this->actor(), $this->p);
        $this->assertSame($fresh['id'], $facts['selection']['id']);
        $this->assertSame(1, $facts['counts']['numerator']);
        $this->assertNotContains('selection_changed', $facts['reason_codes']);
    }

    public function test_review_selectively_invalidates_retained_entry_without_reviving_after_new_review(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body);
        app(QaDefinitionService::class)->review($this->actor(), $this->p, $body['definition_version_id'], ['expected_revision' => 1, 'decision' => 'rejected', 'reason' => 'Synthetic rejected review']);
        $facts = app(QaReadService::class)->verification($this->actor(), $this->p);
        $this->assertSame(0, $facts['counts']['numerator']);
        $this->assertContains('definition_review_changed', $facts['reason_codes']);
        app(QaMaintenanceProcessor::class)->drain($this->actor(), $this->p);
        $states = $this->qaRun(fn () => DB::table('qa_changes as c')->join('qa_applicability as a', 'a.change_id', '=', 'c.id')->join('qa_plan_entry_snapshots as e', 'e.id', '=', 'c.entry_id')->where('c.project_id', $this->p)->pluck('a.status', 'e.criterion_id'));
        $this->assertSame('stale', $states[$this->criteria[0]['id']]);
        $this->assertSame('current', $states[$this->criteria[1]['id']]);
        app(QaDefinitionService::class)->review($this->actor(), $this->p, $body['definition_version_id'], ['expected_revision' => 2, 'decision' => 'reviewed', 'reason' => 'Synthetic later review']);
        $this->denied(fn () => $this->planEntry(0, [['definition_version_id' => $body['definition_version_id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]));
        $this->assertNotEmpty($this->planEntry(0, [['definition_version_id' => $body['definition_version_id'], 'source_instance_id' => $this->sourceId(), 'required' => true]], key: 'fresh-review-entry')['id']);
    }

    public function test_changed_criterion_is_selective_and_current_delivery_does_not_wait_for_worker(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body);
        $old = $this->criteria[0]['version_id'];
        $data = $this->requirement();
        $data['criteria'][] = ['key' => 'C', 'text' => 'Synthetic C', 'required_test_keys' => ['test-c']];
        $data['criteria'][0]['text'] = 'Merge';
        app(RequirementService::class)->updateDraft($this->actor(), $this->p, $this->draft['id'], $data, 1);
        app(RequirementService::class)->approve($this->actor(), $this->p, $this->draft['id'], 2, $this->generation, 'Synthetic selective scope');
        $facts = app(QaReadService::class)->verification($this->actor(), $this->p);
        $this->assertSame(0, $facts['counts']['numerator']);
        $this->assertContains('criterion_version_changed', $facts['counts']['criteria'][$this->criteria[0]['id']]['reason_codes']);
        $this->assertNotContains('criterion_version_changed', $facts['counts']['criteria'][$this->criteria[1]['id']]['reason_codes']);
        app(QaMaintenanceProcessor::class)->drain($this->actor(), $this->p);
        $this->assertGreaterThan(0, $this->qaRun(fn () => DB::table('qa_changes as c')->join('qa_applicability as a', 'a.change_id', '=', 'c.id')->join('qa_plan_entry_snapshots as e', 'e.id', '=', 'c.entry_id')->where('e.criterion_version_id', $old)->where('a.status', 'stale')->count()));
    }

    public function test_task_reassignment_baseline_and_source_change_never_supply_old_current_pass(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body);
        $task = app(TenantContext::class)->run($this->tenant, fn () => DB::table('task_versions')->find($body['task_version_id']));
        $other = $this->distinctQaReviewer();
        $employee = app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $other->id)->value('employee_id'));
        $data = ['title' => 'Synthetic reassigned task', 'assignee_id' => $employee, 'estimate_basis' => 'Synthetic only', 'remaining_effort' => null, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => [['criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id']]]];
        $revised = app(TaskService::class)->revise($this->actor(), $this->p, $task->task_id, $data, 1, $this->generation);
        $this->generation = $revised['generation'];
        $facts = app(QaReadService::class)->verification($this->actor(), $this->p);
        $this->assertSame(0, $facts['counts']['numerator']);
        $this->assertContains('task_version_changed', $facts['reason_codes']);
        $this->qaBuild(2, 1);
        $facts = app(QaReadService::class)->verification($this->actor(), $this->p);
        $this->assertSame(0, $facts['counts']['numerator']);
        $this->assertContains('source_version_changed', $facts['reason_codes']);
        $this->baseline($this->scope());
        $facts = app(QaReadService::class)->verification($this->actor(), $this->p);
        $this->assertContains('baseline_changed', $facts['reason_codes']);
    }

    public function test_retained_handoff_receipt_revocation_persists_after_a_distinct_current_head(): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $data = $this->handoffData($body);
        $handoff = app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'sticky-handoff');
        $reviewer = $this->distinctQaReviewer();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $reviewer->id, 'policy_version_id' => $this->policy, 'purpose' => 'qa.handoff.manage', 'scope_type' => 'project', 'valid_from' => now()->subDay()]));
        app(QaHandoffService::class)->transition($reviewer, $this->p, $handoff['id'], ['expected_revision' => 1, 'kind' => 'context_added', 'reason' => 'Synthetic new actor head', 'pins' => new \stdClass]);
        $this->revokeQaGrant('qa.handoff.manage');
        $this->denied(fn () => app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'sticky-handoff'));
        $this->assertGreaterThan(0, $this->invalidations(), 'Original receipt is retained through the canonical opened event.');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'qa.handoff.manage')->update(['revoked_at' => null, 'revision' => DB::raw('revision-1')]));
        $this->denied(fn () => app(QaHandoffService::class)->open($this->actor(), $this->p, $data, 'sticky-handoff'));
    }

    public function test_denied_retry_observed_revocation_sticky(): void
    {
        $this->qaInputs();
        $build = $this->qaBuild();
        $this->revokeQaGrant('qa.fixture.manage');
        $this->denied(fn () => $this->qaBuild());
        $this->assertSame(1, $this->invalidations(), 'Denied exact replay must durably observe original revocation after rollback.');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'qa.fixture.manage')->update(['revoked_at' => null, 'revision' => DB::raw('revision-1')]));
        $this->denied(fn () => $this->qaBuild());
        $this->assertSame(1, $this->invalidations());
        $this->assertNotNull($build['receipt_id']);
    }

    public function test_viewer_deny_does_not_invalidate(): void
    {
        $this->qaInputs();
        $this->qaBuild();
        $other = $this->distinctQaReviewer();
        $this->assertTrue(class_exists(QaInvalidation::class), 'Bounded denied refresh is required.');
        app(QaInvalidation::class)->refreshDenied($other, $this->p, 'build', 'build-1');
        app(QaInvalidation::class)->refreshDenied($this->actor(), $this->p, 'environment', 'build-1');
        app(QaInvalidation::class)->refreshDenied($this->actor(), $this->p, 'build', 'wrong-key');
        app(QaInvalidation::class)->refreshDenied($this->actor(), (string) Str::uuid(), 'build', 'build-1');
        app(QaInvalidation::class)->refreshDenied(new TrustedActor($this->user, (string) Str::uuid()), $this->p, 'build', 'build-1');
        $this->assertSame(0, $this->invalidations());
    }

    public function test_first_observed_read_and_restored_proof_never_return_favourable_current_result(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body);
        $this->assertTrue(class_exists(QaReadService::class), 'Current delivery must persist observed invalidity.');
        $this->assertSame(1, app(QaReadService::class)->verification($this->actor(), $this->p)['counts']['numerator']);
        $this->revokeQaGrant('qa.execution.record');
        $facts = app(QaReadService::class)->verification($this->actor(), $this->p);
        $this->assertSame(0, $facts['counts']['numerator']);
        $this->assertContains('access_revoked', $facts['reason_codes']);
        $this->assertGreaterThan(0, $this->invalidations());
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'qa.execution.record')->update(['revoked_at' => null, 'revision' => DB::raw('revision-1')]));
        $this->assertSame(0, app(QaReadService::class)->verification($this->actor(), $this->p)['counts']['numerator']);
    }

    private function invalidations(): int
    {
        return app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_invalidations')->where('project_id', $this->p)->count());
    }

    private function denied(\Closure $work): void
    {
        try {
            $work();
            $this->fail('Expected original permission denial.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
    }
}
