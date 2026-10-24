<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementActionService;
use App\Domain\Management\ManagementContract;
use App\Domain\Management\ManagementReviewService;
use App\Domain\Management\ManagementSupportService;
use App\Domain\Qa\QaHandoffService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\ManagementTestCase;

class ManagementSupportTest extends ManagementTestCase
{
    public function test_stale_historical_handoff_subject_does_not_poison_current_confirmed_projection(): void
    {
        $this->managementInputs();
        $qa = $this->managementQaHandoff();
        $oldEvent = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->where('id', $qa['id'])->value('current_event_id'));
        $oldRefs = [$this->lifecycleReference(), array_replace($this->lifecycleReference(), ['kind' => 'qa_handoff_event', 'qa_handoff_event_id' => $oldEvent])];
        $f = $this->workflowAssigned($oldRefs, false);
        $service = app(ManagementSupportService::class);
        $body = $this->workflowSupportBody($f);
        $body['references'] = $oldRefs;
        $firstEnvelope = $this->managementEnvelope('support.record', $body, 'historical-handoff-series');
        $first = $service->record($f['helper'], $this->p, $f['open']['action_id'], $firstEnvelope, 'historical-first', 0);
        $body['expected_revision'] = 3;
        $body['predecessor_support_id'] = $first['support_id'];
        $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.correct', $body, 'historical-handoff-series', 3), 'historical-gap', 1);
        $lateEnvelope = $this->managementEnvelope('support.correct', $body, 'historical-handoff-series', 2);
        $next = app(QaHandoffService::class)->transition($this->actor(), $this->p, $qa['id'], ['kind' => 'context_added', 'expected_revision' => 1, 'pins' => new \stdClass, 'reason' => 'Synthetic new handoff event']);
        $currentRefs = [$this->lifecycleReference(), array_replace($this->lifecycleReference(), ['kind' => 'qa_handoff_event', 'qa_handoff_event_id' => $next['id']])];
        app(ManagementActionService::class)->event($this->actor(), $this->p, $f['open']['action_id'], ['operation' => 'action.revise', 'expected_revision' => 4, 'action' => $this->actionInput($currentRefs)], 'historical-v2');
        $helperId = app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $f['helper']->id)->value('employee_id'));
        $currentFixture = $f;
        $currentFixture['assignment'] = app(ManagementActionService::class)->event($f['head'], $this->p, $f['open']['action_id'], ['operation' => 'assignment.set', 'expected_revision' => 5, 'owner_id' => $this->employee, 'helper_id' => $helperId, 'department_id' => $f['department'], 'reason' => 'Synthetic current assignment'], 'historical-a2');
        $currentBody = $this->workflowSupportBody($currentFixture, 6);
        $currentBody['references'] = $currentRefs;
        $currentSupport = $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $currentBody, 'current-handoff-series'), 'current-handoff-support', 0);
        $this->grant('management.outcome.review', $this->p);
        app(ManagementReviewService::class)->review($this->actor(), $this->p, $f['open']['action_id'], $this->workflowReviewBody($currentSupport), 'current-handoff-confirm');
        $replay = $service->record($f['helper'], $this->p, $f['open']['action_id'], $firstEnvelope, 'historical-first', 0);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['support_id'], $replay['support_id']);
        $this->assertSame('completed', $replay['current_outcome']);
        $this->assertSame('stale', $replay['subject_applicability']);
        $this->assertSame('current', $replay['current_applicability']);
        $this->assertSame($currentSupport['support_id'], $replay['current_projection']['support_id']);
        $lateEnvelope['body']['expected_revision'] = 8;
        $late = $service->record($f['helper'], $this->p, $f['open']['action_id'], $lateEnvelope, 'historical-late', 2);
        $this->assertSame('completed', $late['current_outcome']);
        $this->assertSame('stale', $late['subject_applicability']);
        $this->assertSame('current', $late['current_applicability']);
        $this->assertSame($currentSupport['support_id'], $late['current_projection']['support_id']);
        app(TenantContext::class)->run($this->tenant, function () use ($late, $currentSupport, $f) {
            $event = DB::table('management_events')->where('id', $late['event_id'])->first();
            $this->assertSame($late['support_id'], $event->subject_support_id);
            $this->assertSame($currentSupport['support_id'], $event->support_id);
            $this->assertSame('completed', $event->current_outcome);
            $this->assertSame(3, DB::table('management_source_heads')->where('object_id', 'historical-handoff-series')->value('source_revision'));
            $this->assertSame('completed', DB::table('management_actions')->where('id', $f['open']['action_id'])->value('current_outcome'));
        });
    }

    public function test_established_series_cannot_admit_a_below_head_fact_for_another_assignment(): void
    {
        $f = $this->workflowAssigned();
        $service = app(ManagementSupportService::class);
        $body = $this->workflowSupportBody($f);
        $first = $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $body, 'bound-series'), 'bound-first', 0);
        $body['expected_revision'] = 3;
        $body['predecessor_support_id'] = $first['support_id'];
        $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.correct', $body, 'bound-series', 3), 'bound-gap', 1);
        app(ManagementActionService::class)->event($this->actor(), $this->p, $f['open']['action_id'], ['operation' => 'action.revise', 'expected_revision' => 4, 'action' => $this->actionInput()], 'bound-new-version');
        $f['assignment'] = app(ManagementActionService::class)->event($f['head'], $this->p, $f['open']['action_id'], ['operation' => 'assignment.set', 'expected_revision' => 5, 'owner_id' => $this->employee, 'helper_id' => $this->employee, 'department_id' => $f['department'], 'reason' => 'Synthetic second assignment'], 'bound-new-assignment');
        $this->grant('management.support.record', $this->p);
        $envelope = $this->managementEnvelope('support.record', $this->workflowSupportBody($f, 6), 'bound-series', 2);
        $snapshot = fn () => app(TenantContext::class)->run($this->tenant, function () {
            $state = [];
            foreach (['management_support_versions', 'management_receipts', 'management_events', 'management_request_bindings', 'management_source_heads', 'management_actions', 'management_project_state'] as $table) {
                $rows = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
                $state[$table] = hash('sha256', ManagementContract::canonical($rows));
            }

            return $state;
        });
        $before = $snapshot();
        try {
            $service->record($this->actor(), $this->p, $f['open']['action_id'], $envelope, 'bound-wrong-late', 2);
            $this->fail('Established source series admitted another assignment.');
        } catch (ConflictHttpException) {
            $this->assertSame($before, $snapshot());
        }
    }

    public function test_deferred_guard_rejects_direct_below_head_series_assignment_substitution(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementBoundary();
        $ids = $this->seedManagementSourceSubject($ids, 3);
        $ids = $this->seedManagementReassignment($ids);
        $this->expectException(QueryException::class);
        $this->seedManagementSourceSubject($ids, 2, true);
    }

    public function test_same_binding_history_guard_query_measurement(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementBoundary();
        for ($revision = 2; $revision <= 32; $revision++) {
            $ids = $this->seedManagementSourceSubject($ids, $revision);
        }
        $other = $this->seedManagementBoundary(object: 'unrelated-series');
        for ($revision = 2; $revision <= 32; $revision++) {
            $other = $this->seedManagementSourceSubject($other, $revision, object: 'support.record.unrelated-series');
        }
        app(TenantContext::class)->run($this->tenant, function () use ($ids) {
            $binding = [$ids['action'], $ids['version'], $ids['assignment']];
            $prefix = [$this->tenant, $this->p, $this->managementSource, 'support.record'];
            $predicate = 'tenant_id=?::uuid AND project_id=?::uuid AND source_instance_id=?::uuid AND object_id=?';
            $plans = [];
            foreach (['mismatch' => '<>', 'lower' => '<', 'upper' => '>'] as $name => $operator) {
                $sql = 'SELECT id FROM management_support_versions WHERE '.$predicate.' AND (action_id,action_version_id,assignment_id) '.$operator.' (?::uuid,?::uuid,?::uuid) LIMIT 1';
                $plan = json_decode(DB::select('EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) '.$sql, [...$prefix, ...$binding])[0]->{'QUERY PLAN'}, true, 32, JSON_THROW_ON_ERROR)[0]['Plan'];
                $nodes = [];
                $walk = function (array $node) use (&$walk, &$nodes) {
                    $nodes[] = array_intersect_key($node, array_flip(['Node Type', 'Index Name', 'Actual Rows', 'Rows Removed by Filter', 'Shared Hit Blocks']));
                    foreach ($node['Plans'] ?? [] as $child) {
                        $walk($child);
                    }
                };
                $walk($plan);
                $plans[$name] = $nodes;
            }
            $count = DB::table('management_support_versions')->where('project_id', $this->p)->where('object_id', 'support.record')->count();
            $this->assertSame(32, $count);
            $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->where('tablename', 'management_support_versions')->get(['indexname', 'indexdef'])->map(fn ($r) => (array) $r)->all();
            file_put_contents(base_path('../.local/management-t3-fix1-series-query-profile.json'), json_encode(['same_binding_facts' => $count, 'unrelated_series_facts' => 32, 'plans' => $plans, 'indexes' => $indexes], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        });
    }

    public function test_assigned_helper_records_exact_fact_without_changing_source_acceptance(): void
    {
        $f = $this->workflowAssigned();
        $body = $this->workflowSupportBody($f);
        $envelope = $this->managementEnvelope('support.record', $body, 'support');
        $service = app(ManagementSupportService::class);
        $first = $service->record($f['helper'], $this->p, $f['open']['action_id'], $envelope, 'support', 0);
        $again = $service->record($f['helper'], $this->p, $f['open']['action_id'], $envelope, 'support', 0);
        $this->assertSame(3, $first['revision']);
        $this->assertSame($first['support_id'], $again['support_id']);
        $this->assertTrue($again['replayed']);
        $this->assertNull($first['current_outcome']);
    }

    public function test_correct_retract_decline_and_fresh_support_never_inherit_review(): void
    {
        $f = $this->workflowAssigned();
        $service = app(ManagementSupportService::class);
        $body = $this->workflowSupportBody($f);
        $support = $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $body, 'series'), 'record', 0);
        $this->grant('management.outcome.review', $this->p);
        app(ManagementReviewService::class)->review($this->actor(), $this->p, $f['open']['action_id'], $this->workflowReviewBody($support), 'confirm');
        $body['expected_revision'] = 4;
        $body['predecessor_support_id'] = $support['support_id'];
        $body['outcome'] = 'partial';
        $corrected = $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.correct', $body, 'series', 2), 'correct', 1);
        $this->assertNull($corrected['current_outcome']);
        $body['expected_revision'] = 5;
        $body['predecessor_support_id'] = $corrected['support_id'];
        unset($body['text'], $body['outcome']);
        $body['reason'] = 'Synthetic retraction reason';
        $retracted = $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.retract', $body, 'series', 3), 'retract', 2);
        $this->assertNull($retracted['current_outcome']);
        $body['expected_revision'] = 6;
        $body['predecessor_support_id'] = null;
        $decline = $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.decline', $body, 'decline'), 'decline', 0);
        app(TenantContext::class)->run($this->tenant, function () use ($f, $decline) {
            $root = DB::table('management_actions')->where('id', $f['open']['action_id'])->first();
            $fact = DB::table('management_support_versions')->where('id', $decline['support_id'])->first();
            $this->assertSame('assigned', $root->state);
            $this->assertSame('declined', $root->support_status);
            $this->assertNull($root->current_support_id);
            $this->assertNull($root->current_review_id);
            $this->assertNull($fact->text);
            $this->assertSame('Synthetic retraction reason', $fact->reason);
            $this->assertSame(4, DB::table('management_support_versions')->count());
        });
        $fresh = $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $this->workflowSupportBody($f, 7), 'fresh'), 'fresh', 0);
        $this->assertSame(8, $fresh['revision']);
    }

    public function test_late_and_gapped_support_preserve_subject_without_favorable_fallback(): void
    {
        $f = $this->workflowAssigned();
        $service = app(ManagementSupportService::class);
        $body = $this->workflowSupportBody($f);
        $firstEnvelope = $this->managementEnvelope('support.record', $body, 'series');
        $first = $service->record($f['helper'], $this->p, $f['open']['action_id'], $firstEnvelope, 'first', 0);
        $body['expected_revision'] = 3;
        $body['predecessor_support_id'] = $first['support_id'];
        $newer = $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.correct', $body, 'series', 3), 'gap', 1);
        $this->assertSame('unknown', $newer['applicability']);
        $this->assertNull($newer['current_outcome']);
        $body['expected_revision'] = 4;
        $late = $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.correct', $body, 'series', 2), 'late', 2);
        app(TenantContext::class)->run($this->tenant, function () use ($f, $late, $newer) {
            $root = DB::table('management_actions')->where('id', $f['open']['action_id'])->first();
            $event = DB::table('management_events')->where('id', $late['event_id'])->first();
            $this->assertSame($late['support_id'], $event->subject_support_id);
            $this->assertSame($newer['support_id'], $event->support_id);
            $this->assertSame($newer['support_id'], $root->current_support_id);
            $this->assertNull($root->current_outcome);
        });
        $this->grant('management.outcome.review', $this->p);
        $reviewBody = $this->workflowReviewBody($newer);
        $reviewBody['expected_revision'] = 5;
        $this->expectException(ConflictHttpException::class);
        app(ManagementReviewService::class)->review($this->actor(), $this->p, $f['open']['action_id'], $reviewBody, 'must-not-confirm-gap');
    }

    public function test_unassigned_recorder_and_wrong_source_series_predecessor_are_rejected(): void
    {
        $f = $this->workflowAssigned();
        $outsider = $this->managementActor('developer');
        $this->managementGrantActor($outsider, ['management.support.record', 'management.source.read', 'requirements.read', 'progress.read']);
        try {
            app(ManagementSupportService::class)->record($outsider, $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $this->workflowSupportBody($f), 'outsider'), 'outsider', 0);
            $this->fail('Only owner or helper may record.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
        $first = app(ManagementSupportService::class)->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $this->workflowSupportBody($f), 'first'), 'first', 0);
        $body = $this->workflowSupportBody($f, 3);
        $body['predecessor_support_id'] = $first['support_id'];
        $this->expectException(ConflictHttpException::class);
        app(ManagementSupportService::class)->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.correct', $body, 'other-series', 2), 'other', 0);
    }

    public function test_existing_same_series_wrong_current_predecessor_conflicts(): void
    {
        $f = $this->workflowAssigned();
        $service = app(ManagementSupportService::class);
        $body = $this->workflowSupportBody($f);
        $first = $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $body, 'series'), 'one', 0);
        $body['expected_revision'] = 3;
        $body['predecessor_support_id'] = $first['support_id'];
        $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.correct', $body, 'series', 2), 'two', 1);
        $body['expected_revision'] = 4;
        $this->expectException(ConflictHttpException::class);
        $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.correct', $body, 'series', 3), 'wrong-predecessor', 2);
    }

    public static function lateProjections(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('lateProjections')]
    public function test_late_former_assignment_fact_keeps_its_own_helper_and_current_assignment(bool $confirmed): void
    {
        $f = $this->workflowAssigned();
        $service = app(ManagementSupportService::class);
        $body = $this->workflowSupportBody($f);
        $first = $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $body, 'old-series'), 'old-first', 0);
        $body['expected_revision'] = 3;
        $body['predecessor_support_id'] = $first['support_id'];
        $service->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.correct', $body, 'old-series', 3), 'old-gap', 1);
        $lateEnvelope = $this->managementEnvelope('support.correct', array_replace($body, ['expected_revision' => 5]), 'old-series', 2);
        app(ManagementActionService::class)->event($this->actor(), $this->p, $f['open']['action_id'], ['operation' => 'action.revise', 'expected_revision' => 4, 'action' => $this->actionInput()], 'new-episode');
        $lateEnvelope['body']['expected_revision'] = 6;
        $assignment = app(ManagementActionService::class)->event($f['head'], $this->p, $f['open']['action_id'], ['operation' => 'assignment.set', 'expected_revision' => 5, 'owner_id' => $this->employee, 'helper_id' => $this->employee, 'department_id' => $f['department'], 'reason' => 'Synthetic replacement'], 'replacement');
        $currentSupport = null;
        if ($confirmed) {
            $this->grant('management.support.record', $this->p);
            $newFixture = array_replace($f, ['assignment' => $assignment]);
            $currentSupport = $service->record($this->actor(), $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $this->workflowSupportBody($newFixture, 6), 'new-series'), 'new-support', 0);
            $pm = $this->managementActor('pm');
            $this->managementGrantActor($pm, ['management.outcome.review', 'management.source.read', 'requirements.read', 'progress.read']);
            app(ManagementReviewService::class)->review($pm, $this->p, $f['open']['action_id'], $this->workflowReviewBody($currentSupport), 'new-review');
            $lateEnvelope['body']['expected_revision'] = 8;
        }
        $late = $service->record($f['helper'], $this->p, $f['open']['action_id'], $lateEnvelope, 'late-old', 2);
        app(TenantContext::class)->run($this->tenant, function () use ($late, $assignment, $f, $currentSupport) {
            $subject = DB::table('management_support_versions')->where('id', $late['support_id'])->first();
            $event = DB::table('management_events')->where('id', $late['event_id'])->first();
            $this->assertSame($f['assignment']['assignment_id'], $subject->assignment_id);
            $this->assertSame($f['open']['action_version_id'], $subject->action_version_id);
            $this->assertSame($assignment['assignment_id'], $event->assignment_id);
            $root = DB::table('management_actions')->where('id', $f['open']['action_id'])->first();
            $this->assertNotSame($subject->action_version_id, $root->current_action_version_id);
            $this->assertSame($root->current_action_version_id, $event->action_version_id);
            $this->assertSame(3, DB::table('management_source_heads')->where('object_id', 'old-series')->value('source_revision'));
            $this->assertSame($late['support_id'], $event->subject_support_id);
            $this->assertSame($currentSupport['support_id'] ?? null, $event->support_id);
            $this->assertSame($currentSupport ? 'completed' : null, $event->current_outcome);
        });
    }
}
