<?php

namespace Tests\Feature;

use App\Domain\Ba\BaAnswerService;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaFixtureService;
use App\Domain\Ba\BaReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BaTestCase;

final class BaAnswerTest extends BaTestCase
{
    public function test_valid_late_source_version_is_history_without_replacing_newer_current_head(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $first = $this->recordAnswer($request);
        $body = $this->answerInput($request, 2);
        $body['predecessor_answer_version_id'] = $first['answer_version_id'];
        $body['answer'] = 'Synthetic revision three';
        $service = app(BaAnswerService::class);
        $newest = $service->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.correct', $body, 'response', 3), 'third', 1);
        $body['expected_revision'] = 3;
        $body['answer'] = 'Synthetic late revision two';
        $late = $service->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.correct', $body, 'response', 2), 'late', 2);
        $this->assertNotSame($newest['answer_version_id'], $late['answer_version_id']);
        $this->baRun(function () use ($request, $newest, $late) {
            $this->assertSame($newest['answer_version_id'], DB::table('ba_clarifications')->where('id', $request['clarification_id'])->value('current_answer_id'));
            $event = DB::table('ba_events')->where('id', $late['event_id'])->first();
            $this->assertSame($late['answer_version_id'], $event->subject_answer_version_id);
            $this->assertSame($newest['answer_version_id'], $event->answer_version_id);
        });
        $this->assertSame('unknown', $this->evaluateBa($request)['applicability']);
    }

    public function test_correction_and_retraction_keep_history_and_never_fall_back(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $answer = $this->recordAnswer($request);
        $body = $this->answerInput($request, 2);
        $body['predecessor_answer_version_id'] = $answer['answer_version_id'];
        $body['answer'] = 'Synthetic corrected response';
        $service = app(BaAnswerService::class);
        $corrected = $service->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.correct', $body, 'response', 2), 'correct', 1);
        $retract = array_intersect_key($this->answerInput($request, 3), array_flip(['clarification_id', 'expected_revision', 'request_version_id', 'request_hash', 'links_hash'])) + ['predecessor_answer_version_id' => $corrected['answer_version_id'], 'reason' => 'Synthetic retract'];
        $service->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.retract', $retract, 'response', 3), 'retract', 2);
        $state = $this->evaluateBa($request);
        $this->assertSame('open', $state['clarification_state']);
        $this->assertSame('retracted', $state['answer_review_status']);
        $this->assertNotSame('decision_resolved', $state['dependency_status']);
        $this->assertSame(3, $this->baCounts()['ba_answer_versions']);
    }

    public function test_gap_is_unknown_replay_before_cas_and_context_does_not_reset_pins(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $body = $this->answerInput($request);
        $envelope = $this->baEnvelope('answer.record', $body, 'gap', 3);
        $service = app(BaAnswerService::class);
        $first = $service->record($this->actor(), $this->p, $request['clarification_id'], $envelope, 'gap', 0);
        $this->assertSame('unknown', $this->evaluateBa($request)['applicability']);
        app(BaClarificationService::class)->event($this->actor(), $this->p, $request['clarification_id'], ['clarification_id' => $request['clarification_id'], 'expected_revision' => 2, 'kind' => 'context.add', 'context' => 'Synthetic context'], 'context');
        $before = $this->baCounts();
        $replay = $service->record($this->actor(), $this->p, $request['clarification_id'], $envelope, 'gap', 999);
        $this->assertSame($first['answer_version_id'], $replay['answer_version_id']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($before, $this->baCounts());
    }

    public function test_wrong_pins_or_aggregate_cas_leave_no_orphan_writes(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $body = $this->answerInput($request);
        $before = $this->baCounts();
        $body['request_hash'] = str_repeat('f', 64);
        $this->assertBaConflict(fn () => app(BaAnswerService::class)->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.record', $body), 'wrong', 0));
        $body = $this->answerInput($request, 99);
        $this->assertBaConflict(fn () => app(BaAnswerService::class)->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.record', $body), 'cas', 0));
        $this->assertSame($before, $this->baCounts());
    }

    public function test_original_role_revocation_is_terminal_before_replay_alias(): void
    {
        $this->baInputs();
        $role = $this->baRole();
        $request = $this->openRequest();
        $body = $this->answerInput($request);
        $envelope = $this->baEnvelope('answer.record', $body);
        app(BaAnswerService::class)->record($this->actor(), $this->p, $request['clarification_id'], $envelope, 'response', 0);
        $this->baRun(fn () => DB::table('role_assignments')->where('id', $role)->update(['valid_to' => now()->subSecond()]));
        $this->baRole(replacement: true);
        $before = $this->baCounts();
        $this->assertBaDenied(fn () => app(BaAnswerService::class)->record($this->actor(), $this->p, $request['clarification_id'], $envelope, 'alias', 100));
        $this->assertSame($before, $this->baCounts());
        $this->baRun(fn () => $this->assertGreaterThan(0, DB::table('ba_terminal_applicability')->where('project_id', $this->p)->count()));
    }

    public function test_fresh_independently_authorized_answer_does_not_need_old_recorder_or_party_registrar(): void
    {
        $this->baInputs();
        $this->baRole();
        $registrar = $this->otherBaActor();
        $party = app(BaFixtureService::class)->registerParty($registrar, $this->p, $this->baEnvelope(), 'party', 0);
        $body = $this->requestInput();
        $body['decision_owner_party_version_id'] = $party['party_version_id'];
        $request = $this->openRequest($body);
        $old = $this->otherBaActor();
        $service = app(BaAnswerService::class);
        $envelope = $this->baEnvelope('answer.record', $this->answerInput($request), 'old');
        $service->record($old, $this->p, $request['clarification_id'], $envelope, 'old', 0);
        $this->baRun(function () use ($old, $registrar) {
            DB::table('access_grants')->whereIn('user_id', [$old->id, $registrar->id])->whereIn('purpose', ['ba.answer.record', 'ba.fixture.manage'])->update(['revoked_at' => now(), 'revision' => 2]);
        });
        $fresh = $this->recordAnswer($request, 2, 'independent');
        $pm = $this->pmActor();
        app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $fresh, 3), 'independent');
        $this->assertSame('decision_resolved', $this->evaluateBa($request)['dependency_status']);
    }

    public function test_late_event_subject_and_projection_sql_guards_reject_mismatched_receipt_or_state(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $first = $this->recordAnswer($request);
        $body = $this->answerInput($request, 2);
        $body['predecessor_answer_version_id'] = $first['answer_version_id'];
        $service = app(BaAnswerService::class);
        $newest = $service->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.correct', $body, 'response', 3), 'third', 1);
        $body['expected_revision'] = 3;
        $late = $service->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.correct', $body, 'response', 2), 'late', 2);
        $this->baRun(function () use ($late, $first) {
            $event = (array) DB::table('ba_events')->where('id', $late['event_id'])->first();
            $event['id'] = (string) Str::uuid();
            foreach ([['subject_answer_version_id' => $first['answer_version_id']], ['answer_version_id' => $first['answer_version_id']], ['state' => 'resolved']] as $mutation) {
                $this->assertBaSqlState('23514', fn () => DB::table('ba_events')->insert(array_replace($event, $mutation)));
            }
        });
    }
}
