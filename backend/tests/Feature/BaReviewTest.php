<?php

namespace Tests\Feature;

use App\Domain\Ba\BaAnswerService;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaReviewService;
use App\Domain\Lifecycle\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BaTestCase;

final class BaReviewTest extends BaTestCase
{
    public static function requestSubjectKinds(): array
    {
        return [['requester_id'], ['decision_owner_id']];
    }

    private function distinctRequestSubject(string $field, ?string $expiry = null): array
    {
        $body = $this->requestInput();
        $employee = (string) Str::uuid();
        $membership = (string) Str::uuid();
        $this->baRun(function () use ($employee, $membership, $expiry) {
            DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic request subject']);
            DB::table('project_memberships')->insert(['id' => $membership, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'valid_from' => now()->subDay(), 'valid_to' => $expiry]);
        });
        $body[$field] = $employee;
        if ($field === 'decision_owner_id') {
            $body['decision_party'] = 'internal';
            $body['decision_owner_party_version_id'] = null;
        }

        return [$body, $employee, $membership];
    }

    private function recordSubjectAnswer(array $request, int $revision = 1, string $object = 'response'): array
    {
        $body = $this->answerInput($request, $revision);
        $body['author_party_version_id'] ??= $this->party()['party_version_id'];

        return app(BaAnswerService::class)->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.record', $body, $object), $object, 0);
    }

    #[DataProvider('requestSubjectKinds')]
    public function test_active_request_subject_revocation_denies_confirmation_and_all_actions(string $field): void
    {
        $this->baInputs();
        $this->baRole();
        [$body, $employee, $membership] = $this->distinctRequestSubject($field);
        $request = $this->openRequest($body);
        $answer = $this->recordSubjectAnswer($request);
        $pm = $this->pmActor();
        $this->baRun(fn () => DB::table('project_memberships')->where('id', $membership)->update(['valid_to' => now()->subSecond(), 'revision' => 2]));
        $before = $this->baCounts();
        $head = $this->baRun(fn () => (array) DB::table('ba_clarifications')->where('id', $request['clarification_id'])->first());
        $this->assertBaDenied(fn () => app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'revoked-subject'));
        $this->assertBaDenied(fn () => $this->recordSubjectAnswer($request, 2, 'revoked-subject'));
        foreach (['context.add', 'request.withdraw'] as $kind) {
            $event = ['clarification_id' => $request['clarification_id'], 'expected_revision' => 2, 'kind' => $kind];
            $event[$kind === 'context.add' ? 'context' : 'reason'] = 'Synthetic revoked subject';
            $this->assertBaDenied(fn () => app(BaClarificationService::class)->event($this->actor(), $this->p, $request['clarification_id'], $event, $kind));
        }
        $this->assertBaDenied(fn () => app(BaClarificationService::class)->open($this->actor(), $this->p, $this->baEnvelope('request.open', $body, 'question'), 'revoked-alias', 99));
        $this->assertSame($before, $this->baCounts());
        $this->assertSame($head, $this->baRun(fn () => (array) DB::table('ba_clarifications')->where('id', $request['clarification_id'])->first()));
        $this->assertNotSame('current', $this->evaluateBa($request)['applicability']);
    }

    public function test_original_requester_membership_replacement_cannot_repair_request_or_replay(): void
    {
        $this->baInputs();
        $this->baRole();
        [$body, $employee, $membership] = $this->distinctRequestSubject('requester_id');
        $request = $this->openRequest($body);
        $answer = $this->recordSubjectAnswer($request);
        $pm = $this->pmActor();
        $this->baRun(function () use ($membership, $employee) {
            DB::table('project_memberships')->where('id', $membership)->update(['valid_to' => now()->subSecond(), 'revision' => 2]);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'valid_from' => now()]);
        });
        $before = $this->baCounts();
        $this->assertBaDenied(fn () => app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'replacement'));
        $this->assertBaDenied(fn () => app(BaClarificationService::class)->open($this->actor(), $this->p, $this->baEnvelope('request.open', $body, 'question'), 'replacement-alias', 99));
        $this->assertSame($before, $this->baCounts());
        $this->assertNotSame('current', $this->evaluateBa($request)['applicability']);
    }

    #[DataProvider('requestSubjectKinds')]
    public function test_request_subject_membership_expiry_after_confirm_writes_rolls_back(string $field): void
    {
        $this->baInputs();
        $this->baRole();
        $expiry = DB::scalar("select clock_timestamp()+interval '4 seconds'");
        [$body] = $this->distinctRequestSubject($field, $expiry);
        $request = $this->openRequest($body);
        $answer = $this->recordSubjectAnswer($request);
        $pm = $this->pmActor();
        $before = $this->baCounts();
        $head = $this->baRun(fn () => (array) DB::table('ba_clarifications')->where('id', $request['clarification_id'])->first());
        $written = false;
        DB::listen(function ($query) use ($expiry, &$written) {
            if ($written || ! str_starts_with($query->sql, 'insert into "audit_events"')) {
                return;
            }
            $written = true;
            while (DB::scalar('select clock_timestamp() < ?::timestamptz', [$expiry])) {
                DB::select('select pg_sleep(0.02)');
            }
        });
        $this->assertBaDenied(fn () => app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'subject-expiry'));
        $this->assertTrue($written);
        $this->assertSame($before, $this->baCounts());
        $this->assertSame($head, $this->baRun(fn () => (array) DB::table('ba_clarifications')->where('id', $request['clarification_id'])->first()));
    }

    public function test_valid_request_subjects_pin_exact_membership_without_login_or_role(): void
    {
        $this->baInputs();
        $this->baRole();
        [$body, $employee, $membership] = $this->distinctRequestSubject('requester_id');
        $request = $this->openRequest($body);
        $proof = $this->baRun(fn () => json_decode(DB::table('ba_request_versions')->where('id', $request['request_version_id'])->value('authority_proof'), true));
        $pin = collect($proof['request_subjects'] ?? [])->firstWhere('employee_id', $employee);
        $this->assertNotNull($pin);
        $this->assertSame($membership, $pin['membership']['id']);
        $this->baRun(function () use ($employee) {
            $this->assertSame(0, DB::table('users')->where('employee_id', $employee)->count());
            $this->assertSame(0, DB::table('role_assignments')->where('employee_id', $employee)->count());
        });
        $answer = $this->recordSubjectAnswer($request);
        $pm = $this->pmActor();
        app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'valid-subject');
        $this->assertSame('current', $this->evaluateBa($request)['applicability']);
    }

    #[DataProvider('requestSubjectKinds')]
    public function test_request_subject_interval_must_cover_known_request_time(string $field): void
    {
        $this->baInputs();
        $this->baRole();
        [$body] = $this->distinctRequestSubject($field);
        $body['links'][0]['relation'] = 'context';
        $body['requested_at'] = CarbonImmutable::parse(DB::scalar("select clock_timestamp()-interval '2 days'"))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $request = $this->openRequest($body, 'before-membership');
        $answer = $this->recordSubjectAnswer($request);
        $state = $this->evaluateBa($request);
        $this->assertSame('unknown', $state['applicability']);
        $this->assertContains('request_subject_time_unknown', $state['reasons']);
        $pm = $this->pmActor();
        $before = $this->baCounts();
        $this->assertBaConflict(fn () => app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'before-membership'));
        $this->assertSame($before, $this->baCounts());
        $body['requested_at'] = CarbonImmutable::parse(DB::scalar("select clock_timestamp()-interval '1 hour'"))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $valid = $this->openRequest($body, 'within-membership');
        $validAnswer = $this->recordSubjectAnswer($valid, 1, 'within-membership');
        app(BaReviewService::class)->review($pm, $this->p, $valid['clarification_id'], $this->reviewInput($valid, $validAnswer), 'within-membership');
        $this->assertSame('decision_resolved', $this->evaluateBa($valid)['dependency_status']);
    }

    public function test_legacy_request_without_subject_provenance_remains_unknown(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->seedBaRequest();
        $answer = $this->recordAnswer($request);
        $state = $this->evaluateBa($request);
        $this->assertSame('unknown', $state['applicability']);
        $this->assertContains('request_subject_proof_missing', $state['reasons']);
        $pm = $this->pmActor();
        $before = $this->baCounts();
        $this->assertBaConflict(fn () => app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'legacy'));
        $this->assertSame($before, $this->baCounts());
    }

    public static function coordinatorIntervalTables(): array
    {
        return [['project_memberships'], ['role_assignments']];
    }

    #[DataProvider('coordinatorIntervalTables')]
    public function test_pinned_coordinator_interval_must_cover_known_request_time(string $table): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        $coordinator = $this->otherBaActor();
        $body['coordinator_id'] = $this->baRun(fn () => DB::table('users')->where('id', $coordinator->id)->value('employee_id'));
        $this->baRun(function () use ($table, $body) {
            $query = DB::table($table)->where('employee_id', $body['coordinator_id']);
            if ($table === 'role_assignments') {
                $query->where('role', 'ba');
            }
            $query->update(['valid_from' => DB::raw("clock_timestamp()-interval '1 minute'")]);
        });
        $body['links'][0]['relation'] = 'context';
        $body['requested_at'] = CarbonImmutable::parse(DB::scalar("select clock_timestamp()-interval '1 hour'"))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $request = $this->openRequest($body, 'before-coordinator');
        $answer = $this->recordAnswer($request);
        $state = $this->evaluateBa($request);
        $this->assertSame('unknown', $state['applicability']);
        $this->assertContains('coordinator_time_unknown', $state['reasons']);
        $pm = $this->pmActor();
        $before = $this->baCounts();
        $this->assertBaConflict(fn () => app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'before-coordinator'));
        $this->assertSame($before, $this->baCounts());
        $body['requested_at'] = CarbonImmutable::parse(DB::scalar("select clock_timestamp()-interval '30 seconds'"))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $valid = $this->openRequest($body, 'within-coordinator');
        $validAnswer = $this->recordAnswer($valid, 1, 'within-coordinator');
        app(BaReviewService::class)->review($pm, $this->p, $valid['clarification_id'], $this->reviewInput($valid, $validAnswer), 'within-coordinator');
        $this->assertSame('decision_resolved', $this->evaluateBa($valid)['dependency_status']);
    }

    public function test_distinct_employee_confirmation_and_no_e03_acceptance_write(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $answer = $this->recordAnswer($request);
        $body = $this->reviewInput($request, $answer);
        $before = $this->progress();
        $counts = $this->baCounts();
        $e03 = $this->e03Counts();
        $same = $this->pmActor($this->employee);
        $this->assertBaDenied(fn () => app(BaReviewService::class)->review($same, $this->p, $request['clarification_id'], $body, 'same'));
        $this->assertSame($counts, $this->baCounts());
        $pm = $this->pmActor();
        app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $body, 'confirm');
        $state = $this->evaluateBa($request);
        $this->assertSame('resolved', $state['clarification_state']);
        $this->assertSame('decision_resolved', $state['dependency_status']);
        $this->assertSame('not_requested', $state['scope_status']);
        $this->assertSame($before, $this->progress());
        $this->assertSame($e03, $this->e03Counts());
    }

    public function test_scope_obligation_survives_revoke_reopen_correction_and_later_no_scope_change(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $answer = $this->recordAnswer($request);
        $pm = $this->pmActor();
        $service = app(BaReviewService::class);
        $body = $this->reviewInput($request, $answer, 2, 'scope_change_required');
        $review = $service->review($pm, $this->p, $request['clarification_id'], $body, 'scope');
        $this->assertSame('scope_approval_pending', $this->evaluateBa($request)['scope_status']);
        unset($body['disposition']);
        $body['kind'] = 'review.revoke';
        $body['predecessor_review_id'] = $review['review_id'];
        $body['expected_revision'] = 3;
        $service->review($pm, $this->p, $request['clarification_id'], $body, 'revoke');
        $fresh = $service->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer, 4), 'new-confirm');
        $reopened = app(BaClarificationService::class)->event($this->actor(), $this->p, $request['clarification_id'], ['clarification_id' => $request['clarification_id'], 'expected_revision' => 5, 'kind' => 'request.reopen', 'reason' => 'Synthetic reopen', 'request' => $request['body']], 'reopen');
        $freshAnswer = $this->recordAnswer($reopened, 6, 'fresh-response');
        $service->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($reopened, $freshAnswer, 7), 'later');
        $state = $this->evaluateBa($request);
        $this->assertSame('unknown', $state['scope_status']);
        $this->assertNotSame('decision_resolved', $state['dependency_status']);
        $this->assertSame(1, $this->baCounts()['ba_scope_obligations']);
    }

    public function test_review_reject_keeps_latest_answer_and_requires_fresh_record(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $answer = $this->recordAnswer($request);
        $pm = $this->pmActor();
        $body = $this->reviewInput($request, $answer);
        unset($body['disposition']);
        $body['kind'] = 'review.reject';
        app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $body, 'reject');
        $state = $this->evaluateBa($request);
        $this->assertSame('rejected', $state['answer_review_status']);
        $this->assertSame('open', $state['clarification_state']);
        $this->assertBaConflict(fn () => app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer, 3), 'old'));
        $fresh = $this->recordAnswer($request, 3, 'fresh');
        app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $fresh, 4), 'fresh');
        $this->assertSame('decision_resolved', $this->evaluateBa($request)['dependency_status']);
    }

    public function test_gap_and_future_responsibility_cannot_confirm(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $body = $this->answerInput($request);
        $body['response_at'] = '2099-01-01T00:00:00.000000Z';
        $answer = app(BaAnswerService::class)->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.record', $body, 'future', 3), 'future', 0);
        $pm = $this->pmActor();
        $before = $this->baCounts();
        $this->assertBaConflict(fn () => app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'future'));
        $this->assertSame($before, $this->baCounts());
    }

    public function test_pm_replay_checks_original_role_before_obsolete_cas_and_alias(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $answer = $this->recordAnswer($request);
        $pm = $this->pmActor();
        $body = $this->reviewInput($request, $answer);
        $service = app(BaReviewService::class);
        $first = $service->review($pm, $this->p, $request['clarification_id'], $body, 'confirm');
        $replay = $service->review($pm, $this->p, $request['clarification_id'], $body, 'confirm');
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['review_id'], $replay['review_id']);
        $this->baRun(function () use ($pm) {
            $employee = DB::table('users')->where('id', $pm->id)->value('employee_id');
            DB::table('role_assignments')->where('employee_id', $employee)->where('role', 'pm')->update(['valid_to' => now()->subSecond()]);
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'role' => 'pm', 'valid_from' => now()]);
        });
        $before = $this->baCounts();
        $this->assertBaDenied(fn () => $service->review($pm, $this->p, $request['clarification_id'], $body, 'confirm'));
        $this->assertSame($before, $this->baCounts());
    }

    public function test_e03_task_change_and_relink_cannot_clear_inherited_scope_obligation(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $answer = $this->recordAnswer($request);
        $pm = $this->pmActor();
        $service = app(BaReviewService::class);
        $service->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer, 2, 'scope_change_required'), 'scope');
        $body = $request['body'];
        $oldVersion = $body['links'][0]['task_version_id'];
        $task = $this->baRun(fn () => DB::table('task_versions')->where('id', $oldVersion)->first());
        $new = app(TaskService::class)->revise($this->actor(), $this->p, $task->task_id, ['title' => 'Synthetic revised task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic only', 'remaining_effort' => null, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => [['criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id']]]], 1, $this->generation);
        $this->generation = $new['generation'];
        $this->assertSame('stale', $this->evaluateBa($request)['applicability']);
        $body['links'][0]['task_version_id'] = $new['version_id'];
        $body['requested_at'] = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $relinked = app(BaClarificationService::class)->event($this->actor(), $this->p, $request['clarification_id'], ['clarification_id' => $request['clarification_id'], 'expected_revision' => 3, 'kind' => 'request.revise', 'reason' => 'Synthetic scope relink', 'request' => $body], 'relink');
        $fresh = $this->recordAnswer($relinked, 4, 'relinked');
        $service->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($relinked, $fresh, 5), 'later');
        $state = $this->evaluateBa($request);
        $this->assertSame('current', $state['applicability']);
        $this->assertSame('unknown', $state['scope_status']);
        $this->assertSame('unresolved', $state['dependency_status']);
        $unrelated = $this->openRequest($body, 'unrelated');
        $otherAnswer = $this->recordAnswer($unrelated, 1, 'unrelated-answer');
        $service->review($pm, $this->p, $unrelated['clarification_id'], $this->reviewInput($unrelated, $otherAnswer), 'unrelated');
        $this->assertSame('decision_resolved', $this->evaluateBa($unrelated)['dependency_status']);
    }

    public function test_original_ba_role_expiry_after_service_writes_rolls_back_review_atomically(): void
    {
        $this->baInputs();
        $role = $this->baRole();
        $expiry = $this->baRun(function () use ($role) {
            $expiry = DB::scalar("select clock_timestamp()+interval '4 seconds'");
            DB::table('role_assignments')->where('id', $role)->update(['valid_to' => $expiry]);

            return $expiry;
        });
        $request = $this->openRequest();
        $answer = $this->recordAnswer($request);
        $pm = $this->pmActor();
        $body = $this->reviewInput($request, $answer);
        $before = $this->baCounts();
        $written = false;
        DB::listen(function ($query) use ($expiry, &$written) {
            if ($written || ! str_starts_with($query->sql, 'insert into "audit_events"')) {
                return;
            }
            $written = true;
            while (DB::scalar('select clock_timestamp() < ?::timestamptz', [$expiry])) {
                DB::select('select pg_sleep(0.02)');
            }
        });
        $this->assertBaDenied(fn () => app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $body, 'expiry'));
        $this->assertTrue($written);
        $this->assertSame($before, $this->baCounts());
    }

    public function test_context_only_links_do_not_make_future_business_time_favourable(): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        $body['links'][0]['relation'] = 'context';
        $body['requested_at'] = '2099-01-01T00:00:00.000000Z';
        $request = $this->openRequest($body);
        $answerBody = $this->answerInput($request);
        $answerBody['response_at'] = '2099-01-02T00:00:00.000000Z';
        $answer = app(BaAnswerService::class)->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.record', $answerBody, 'future'), 'future', 0);
        $this->assertSame('unknown', $this->evaluateBa($request)['applicability']);
        $pm = $this->pmActor();
        $this->assertBaConflict(fn () => app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'future'));
    }

    public function test_invalid_old_scope_origin_is_unknown_and_fresh_confirmation_cannot_clear_it(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $answer = $this->recordAnswer($request);
        $oldPm = $this->pmActor();
        app(BaReviewService::class)->review($oldPm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer, 2, 'scope_change_required'), 'origin');
        $this->baRun(fn () => DB::table('access_grants')->where('user_id', $oldPm->id)->where('purpose', 'ba.decision.confirm')->update(['revoked_at' => now(), 'revision' => 2]));
        $reopened = app(BaClarificationService::class)->event($this->actor(), $this->p, $request['clarification_id'], ['clarification_id' => $request['clarification_id'], 'expected_revision' => 3, 'kind' => 'request.reopen', 'reason' => 'Synthetic new episode', 'request' => $request['body']], 'reopen');
        $fresh = $this->recordAnswer($reopened, 4, 'fresh');
        $freshPm = $this->pmActor();
        app(BaReviewService::class)->review($freshPm, $this->p, $request['clarification_id'], $this->reviewInput($reopened, $fresh, 5), 'fresh');
        $state = $this->evaluateBa($request);
        $this->assertSame('current', $state['applicability']);
        $this->assertSame('unknown', $state['scope_status']);
        $this->assertSame('unresolved', $state['dependency_status']);
        $this->assertSame(1, $this->baCounts()['ba_scope_obligations']);
    }
}
