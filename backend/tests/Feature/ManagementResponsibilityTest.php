<?php

namespace Tests\Feature;

use App\Domain\Ba\BaAnswerService;
use App\Domain\Lifecycle\TaskService;
use App\Domain\Management\ManagementActionService;
use App\Domain\Management\ManagementCoordination;
use App\Domain\Management\ManagementPins;
use App\Domain\Management\ManagementReadService;
use App\Domain\Management\ManagementResponsibility;
use App\Domain\Management\ManagementSourceProofs;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\ManagementTestCase;

final class ManagementResponsibilityTest extends ManagementTestCase
{
    public static function exactBaHistoricalSubjects(): array
    {
        return [['ba_answer_version'], ['ba_review']];
    }

    #[DataProvider('exactBaHistoricalSubjects')]
    public function test_exact_ba_subject_retains_original_time_and_employee_across_correction_and_retraction(string $kind): void
    {
        $this->managementInputs();
        $fixture = $this->managementBaReviewedFixture();
        $a1 = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ba_answer_versions')->where('id', $fixture['answer']['answer_version_id'])->first());
        $a1Time = CarbonImmutable::parse($a1->response_at)->utc()->format('Y-m-d\TH:i:s.u\Z');
        $subjectId = $kind === 'ba_answer_version' ? $a1->id : $fixture['review']['review_id'];
        $reference = array_replace($this->lifecycleReference(), ['kind' => $kind, $kind.'_id' => $subjectId]);
        $action = app(ManagementActionService::class)->open($this->actor(), $this->p, $this->managementEnvelope('action.open', $this->actionInput([$this->lifecycleReference(), $reference]), 'historical-'.$kind), 'historical-'.$kind, 0);
        $measure = function (bool $stale) use ($action, $a1Time): void {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$action['action_id']]);
                $this->assertSame('complete', $graph['coverage']);
                $evaluation = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, function ($proofs) use ($graph) {
                    $loaded = app(ManagementPins::class)->loadLocked($this->p, $graph);

                    return app(ManagementSourceProofs::class)->evaluateLocked($this->p, $graph, $loaded['rows'], $proofs);
                });
                $source = array_values(array_filter($evaluation['responsibility'], fn ($slot) => $slot['occurred_at'] === $a1Time));
                $this->assertNotEmpty($source, 'Exact BA historical answer time disappeared or followed the current head.');
                foreach ($source as $slot) {
                    $this->assertSame('known', $slot['attribution']);
                    $this->assertSame($this->employee, $slot['employee_id']);
                    $this->assertSame($this->managementTask['version_id'], $slot['task_version_id']);
                }
                $page = app(ManagementReadService::class)->history($this->actor(), $this->p, $action['action_id'], ['limit' => 1]);
                $this->assertSame('complete', $page['coverage_status']);
                $this->assertCount(1, $page['items']);
                $dto = $page['items'][0];
                $historical = array_values(array_filter($dto['responsibility'], fn ($slot) => $slot['occurred_at'] === $a1Time));
                $this->assertNotEmpty($historical);
                $this->assertSame('known', $historical[0]['attribution']);
                $this->assertSame($this->employee, $historical[0]['employee_id']);
                $this->assertNull($dto['current_outcome']);
                if ($stale) {
                    $this->assertSame('stale', $dto['applicability']);
                    $this->assertContains(false, array_column($graph['references'], 'source_current'));
                }
                $writes = array_filter(DB::getQueryLog(), fn ($query) => preg_match('/\A\s*(?:insert\s+into|update|delete\s+from|truncate)\s+"?(?:qa_|ba_|lifecycle_|task_|requirements?|criterion|baseline|human_acceptance)/i', $query['query']));
                $this->assertSame([], array_values($writes), 'Management source collection/history wrote a source store.');
            } finally {
                DB::disableQueryLog();
            }
        };
        $measure(false);
        $successor = app(TaskService::class)->revise($this->actor(), $this->p, $this->managementTask['id'], ['title' => 'Synthetic successor task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic estimate', 'remaining_effort' => null, 'status' => 'unstarted', 'expected_test_keys' => ['test-a'], 'criterion_links' => [['criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id']]]], 1, $this->generation);
        $response2 = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $successorAt = app(TenantContext::class)->run($this->tenant, fn () => DB::table('task_versions')->where('id', $successor['version_id'])->value('effective_at'));
        $this->assertTrue(CarbonImmutable::parse($a1Time)->lessThan(CarbonImmutable::parse($successorAt)));
        $this->assertTrue(CarbonImmutable::parse($response2)->greaterThan(CarbonImmutable::parse($successorAt)));
        $body = ['clarification_id' => $a1->clarification_id, 'expected_revision' => 3, 'request_version_id' => $a1->request_version_id, 'request_hash' => $a1->request_hash, 'links_hash' => $a1->links_hash, 'author_party_version_id' => $a1->author_party_version_id, 'response_at' => $response2, 'answer' => 'Synthetic newer answer after task successor', 'predecessor_answer_version_id' => $a1->id];
        $envelope = $this->managementSourceEnvelope('ba-evidence-v1', 'answer.correct', $body, $a1->object_id);
        $envelope['source_revision'] = 2;
        $a2 = app(BaAnswerService::class)->record($this->actor(), $this->p, $a1->clarification_id, $envelope, 'historical-correction', 1);
        $measure(true);
        $retract = array_intersect_key($body, array_flip(['clarification_id', 'expected_revision', 'request_version_id', 'request_hash', 'links_hash', 'predecessor_answer_version_id'])) + ['reason' => 'Synthetic current retraction without response time'];
        $retract['expected_revision'] = 4;
        $retract['predecessor_answer_version_id'] = $a2['answer_version_id'];
        $envelope = $this->managementSourceEnvelope('ba-evidence-v1', 'answer.retract', $retract, $a1->object_id);
        $envelope['source_revision'] = 3;
        app(BaAnswerService::class)->record($this->actor(), $this->p, $a1->clarification_id, $envelope, 'historical-retraction', 2);
        $measure(true);
    }

    public static function formerAssignmentPrivacyCases(): array
    {
        return [['helper'], ['actor']];
    }

    public function test_exact_retracted_answer_has_unknown_time_instead_of_borrowing_a_newer_response(): void
    {
        $this->managementInputs();
        $fixture = $this->managementBaReviewedFixture();
        $a1 = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ba_answer_versions')->where('id', $fixture['answer']['answer_version_id'])->first());
        $base = ['clarification_id' => $a1->clarification_id, 'request_version_id' => $a1->request_version_id, 'request_hash' => $a1->request_hash, 'links_hash' => $a1->links_hash];
        $retract = $base + ['expected_revision' => 3, 'predecessor_answer_version_id' => $a1->id, 'reason' => 'Synthetic exact retraction without response'];
        $envelope = $this->managementSourceEnvelope('ba-evidence-v1', 'answer.retract', $retract, $a1->object_id);
        $envelope['source_revision'] = 2;
        $retracted = app(BaAnswerService::class)->record($this->actor(), $this->p, $a1->clarification_id, $envelope, 'exact-retraction', 1);
        $fresh = $base + ['expected_revision' => 4, 'predecessor_answer_version_id' => null, 'author_party_version_id' => $a1->author_party_version_id, 'response_at' => CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z'), 'answer' => 'Synthetic newer response'];
        $envelope = $this->managementSourceEnvelope('ba-evidence-v1', 'answer.record', $fresh, $a1->object_id);
        $envelope['source_revision'] = 3;
        app(BaAnswerService::class)->record($this->actor(), $this->p, $a1->clarification_id, $envelope, 'newer-response', 2);
        $reference = array_replace($this->lifecycleReference(), ['kind' => 'ba_answer_version', 'ba_answer_version_id' => $retracted['answer_version_id']]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$this->lifecycleReference(), $reference]);
            $this->assertSame('complete', $graph['coverage']);
            $this->assertSame([], $graph['responsibility_slots'], 'A retracted answer cannot borrow a later head response time.');
            $source = collect($graph['references'])->firstWhere('kind', 'ba_answer_version');
            $this->assertFalse($source['source_time_known']);
            $this->assertFalse($source['source_current']);
            $evaluation = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, function ($proofs) use ($graph) {
                $loaded = app(ManagementPins::class)->loadLocked($this->p, $graph);

                return app(ManagementSourceProofs::class)->evaluateLocked($this->p, $graph, $loaded['rows'], $proofs);
            });
            $this->assertNull($evaluation['current_outcome']);
            $writes = array_filter(DB::getQueryLog(), fn ($query) => preg_match('/\A\s*(?:insert\s+into|update|delete\s+from|truncate)\s+"?(?:qa_|ba_|lifecycle_|task_|requirements?|criterion|baseline|human_acceptance)/i', $query['query']));
            $this->assertSame([], array_values($writes));
        } finally {
            DB::disableQueryLog();
        }
    }

    #[DataProvider('formerAssignmentPrivacyCases')]
    public function test_late_support_uses_its_own_former_assignment_and_original_privacy(string $hidden): void
    {
        $this->managementInputs();
        $actor = $this->managementActor('pm');
        $helperActor = $this->managementActor('developer');
        $helper = app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $helperActor->id)->value('employee_id'));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $actor->id, 'policy_version_id' => $this->policy, 'purpose' => 'management.action.manage', 'scope_type' => 'project', 'valid_from' => now()->subDay()]));
        $a1 = $this->seedManagementBoundary(exactSource: true, assignmentActor: $actor, helper: $helper);
        $a1 = $this->seedManagementSourceSubject($a1, 3);
        $occurred = app(TenantContext::class)->run($this->tenant, fn () => DB::table('management_support_versions')->where('id', $a1['support'])->value('occurred_at'));
        $a2 = $this->seedManagementReassignment($a1);
        $a2 = $this->seedManagementSourceSubject($a2, 1, object: 'a2-complete');
        $this->seedManagementSourceSubject($a1, 2, late: true, occurredAt: CarbonImmutable::parse($occurred)->utc()->format('Y-m-d\TH:i:s.u\Z'));
        $current = app(ManagementPins::class)->collect($this->actor(), $this->p, [$a1['action']]);
        $this->assertSame('unknown', $current['coverage']);
        $this->assertSame(11, $current['budget']['management_proofs']);
        $open = app(TenantContext::class)->run($this->tenant, fn () => DB::table('management_events')->where('action_id', $a1['action'])->where('revision', 1)->value('id'));
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [['action_id' => $a1['action'], 'event_id' => $open], $a1['action']]);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertContains($a1['assignment'], $graph['refs']['management_assignments']);
        $this->assertNotEmpty(array_filter($graph['proof_slots'], fn ($s) => $s['subject_kind'] === 'management_assignments' && $s['subject_id'] === $a1['assignment']));
        $this->assertNotEmpty(array_filter($graph['proof_slots'], fn ($s) => $s['subject_kind'] === 'management_receipts' && $s['actor_id'] === $actor->id));
        $slot = collect($graph['responsibility_slots'])->firstWhere('assignment_id', $a1['assignment']);
        $this->assertNotNull($slot);
        $known = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, function ($proofs) use ($graph, $slot, $a1) {
            $loaded = app(ManagementPins::class)->loadLocked($this->p, $graph);

            return app(ManagementResponsibility::class)->captureLocked($this->p, $this->lifecycleReference(), $slot['occurred_at'], $loaded['rows']['management_assignments'][$a1['assignment']], $proofs);
        });
        $this->assertSame('known', $known['attribution']);
        $this->assertSame($helper, $known['helper_id']);
        $this->assertNotSame($this->employee, $known['helper_id']);
        app(TenantContext::class)->run($this->tenant, function () use ($hidden, $helper, $actor) {
            if ($hidden === 'helper') {
                DB::table('project_memberships')->where('project_id', $this->p)->where('employee_id', $helper)->update(['valid_to' => now(), 'revision' => DB::raw('revision+1')]);
            } else {
                DB::table('access_grants')->where('project_id', $this->p)->where('user_id', $actor->id)->where('purpose', 'management.action.manage')->update(['revoked_at' => now(), 'revision' => DB::raw('revision+1')]);
            }
        });
        $this->expectException(AccessDeniedHttpException::class);
        app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, fn () => $this->fail('Former assignment privacy must stop delivery.'));
    }

    public function test_action_time_does_not_borrow_its_later_support_assignment(): void
    {
        $this->managementInputs();
        $ids = $this->seedManagementConfirmation($this->seedManagementBoundary(exactSource: true));
        $times = app(TenantContext::class)->run($this->tenant, fn () => [DB::table('management_action_versions')->where('id', $ids['version'])->value('occurred_at'), DB::table('management_assignments')->where('id', $ids['assignment'])->value('assigned_at'), DB::table('management_support_versions')->where('id', $ids['support'])->value('occurred_at')]);
        $this->assertTrue(CarbonImmutable::parse($times[0])->lessThan(CarbonImmutable::parse($times[1])));
        $this->assertTrue(CarbonImmutable::parse($times[1])->lessThan(CarbonImmutable::parse($times[2])));
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$ids['action']]);
        $result = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, function ($proofs) use ($graph) {
            $rows = app(ManagementPins::class)->loadLocked($this->p, $graph);

            return app(ManagementSourceProofs::class)->evaluateLocked($this->p, $graph, $rows['rows'], $proofs);
        });
        $this->assertNotContains('responsibility_unknown', $result['reason_codes']);
        $this->assertSame('completed', $result['current_outcome']);
    }

    public function test_missing_preallocated_assignment_remains_unknown(): void
    {
        $this->managementInputs();
        $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [['reference' => $this->lifecycleReference(), 'occurred_at' => $at, 'assignment_id' => (string) Str::uuid()]]);
        $result = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, function ($proofs) use ($graph) {
            $rows = app(ManagementPins::class)->loadLocked($this->p, $graph);

            return app(ManagementSourceProofs::class)->evaluateLocked($this->p, $graph, $rows['rows'], $proofs);
        });
        $this->assertSame('unknown', $result['responsibility']['responsibility:prospective:0']['attribution']);
        $this->assertSame('assignment_missing', $result['responsibility']['responsibility:prospective:0']['reason_code']);
        $this->assertNull($result['current_outcome']);
    }

    public function test_elapsed_age_is_utc_process_time_and_invalid_time_is_unknown(): void
    {
        $age = app(ManagementResponsibility::class)->age('2026-10-09T00:00:00.000000Z', '2026-10-09T00:30:00.000000Z', '2026-10-09T01:00:00.000000Z');
        $this->assertSame(3600, $age['elapsed_seconds']);
        $this->assertSame(1800, $age['episode_elapsed_seconds']);
        $this->assertNull($age['business_age']);
        $this->assertNull(app(ManagementResponsibility::class)->age('2026-10-10T00:00:00.000000Z', null, '2026-10-09T01:00:00.000000Z')['elapsed_seconds']);
    }

    public function test_prospective_time_wrapper_pins_original_task_and_assignment(): void
    {
        $this->managementInputs();
        $boundary = $this->seedManagementBoundary();
        $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$boundary['action']], [['reference' => $this->lifecycleReference(), 'occurred_at' => $at, 'assignment_id' => $boundary['assignment']]]);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertCount(3, $graph['responsibility_slots']);
        $captured = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, function ($proofs) use ($graph, $boundary, $at) {
            $rows = app(ManagementPins::class)->loadLocked($this->p, $graph);

            return app(ManagementResponsibility::class)->captureLocked($this->p, $this->lifecycleReference(), $at, $rows['rows']['management_assignments'][$boundary['assignment']], $proofs);
        });
        $this->assertSame('known', $captured['attribution']);
        $this->assertSame($this->employee, $captured['helper_id']);
    }

    public function test_before_assignment_future_and_exact_successor_times_are_unknown(): void
    {
        $this->managementInputs();
        $boundary = $this->seedManagementBoundary();
        $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$boundary['action']], [['reference' => $this->lifecycleReference(), 'occurred_at' => $at, 'assignment_id' => $boundary['assignment']]]);
        app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, function ($proofs) use ($graph, $boundary, $at) {
            $rows = app(ManagementPins::class)->loadLocked($this->p, $graph);
            $assignment = $rows['rows']['management_assignments'][$boundary['assignment']];
            $assignment['assigned_at'] = CarbonImmutable::parse($at)->addSecond()->format('Y-m-d\TH:i:s.u\Z');
            $this->assertSame('unknown', app(ManagementResponsibility::class)->captureLocked($this->p, $this->lifecycleReference(), $at, $assignment, $proofs)['attribution']);
            $assignment['assigned_at'] = CarbonImmutable::parse($at)->subSecond()->format('Y-m-d\TH:i:s.u\Z');
            $assignment['next_assignment_at'] = $at;
            $this->assertSame('unknown', app(ManagementResponsibility::class)->captureLocked($this->p, $this->lifecycleReference(), $at, $assignment, $proofs)['attribution']);
            $future = CarbonImmutable::parse($at)->addDay()->format('Y-m-d\TH:i:s.u\Z');
            $this->assertSame('unknown', app(ManagementResponsibility::class)->captureLocked($this->p, $this->lifecycleReference(), $future, null, $proofs)['attribution']);
        });
        $age = app(ManagementResponsibility::class)->age('2026-10-09T01:62:00.000000Z', null, '2026-10-09T03:00:00.000000Z');
        $this->assertNull($age['elapsed_seconds']);
    }
}
