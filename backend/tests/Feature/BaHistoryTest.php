<?php

namespace Tests\Feature;

use App\Domain\Ba\BaAnswerService;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaReadService;
use App\Domain\Ba\BaReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BaTestCase;

class BaHistoryTest extends BaTestCase
{
    public function test_assignee_role_ending_after_event_preserves_original_historical_responsibility(): void
    {
        $this->baInputs();
        $this->baRole();
        $other = $this->pmActor();
        $employee = $this->baRun(fn () => DB::table('users')->where('id', $other->id)->value('employee_id'));
        $body = $this->requestInput();
        $task = $this->baTask(0, $employee);
        $body['links'][0]['task_version_id'] = $task['version_id'];
        $body['requested_at'] = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $request = $this->openRequest($body);
        $this->baRun(fn () => DB::table('role_assignments')->where('project_id', $this->p)->where('employee_id', $employee)->update(['valid_to' => DB::raw('clock_timestamp()')]));
        $page = app(BaReadService::class)->history($this->actor(), $this->p, $request['clarification_id']);
        $this->assertSame('complete', $page['coverage_status']);
        $this->assertSame('known', $page['items'][0]['responsibilities'][0]['attribution']);
        $this->assertSame($employee, $page['items'][0]['responsibilities'][0]['employee_id']);
        $this->assertSame($task['version_id'], $page['items'][0]['responsibilities'][0]['task_version_id']);
        $this->baRun(fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'ba.read')->update(['revoked_at' => now()]));
        $this->assertBaDenied(fn () => app(BaReadService::class)->history($this->actor(), $this->p, $request['clarification_id']));
    }

    public function test_revoked_optional_old_answer_is_withheld_without_poisoning_fresh_resolution(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $oldActor = $this->otherBaActor();
        $body = $this->answerInput($request);
        $body['answer'] = 'Private withdrawn old answer';
        app(BaAnswerService::class)->record($oldActor, $this->p, $request['clarification_id'], $this->baEnvelope('answer.record', $body, 'old-answer'), 'old-answer', 0);
        $this->baRun(fn () => DB::table('access_grants')->where('user_id', $oldActor->id)->where('purpose', 'ba.answer.record')->update(['revoked_at' => now()]));
        $fresh = $this->recordAnswer($request, 2, 'fresh-answer');
        app(BaReviewService::class)->review($this->pmActor(), $this->p, $request['clarification_id'], $this->reviewInput($request, $fresh, 3), 'fresh-confirm');
        $this->assertSame('decision_resolved', app(BaReadService::class)->queue($this->actor(), $this->p)['items'][0]['dependency_status']);
        $page = app(BaReadService::class)->history($this->actor(), $this->p, $request['clarification_id']);
        $this->assertSame('unknown', $page['coverage_status']);
        $this->assertStringNotContainsString('Private withdrawn old answer', json_encode($page));
        $this->assertSame(['request.open', 'answer.record', 'review.confirm'], array_column($page['items'], 'kind'));
        $this->assertSame(0, $this->baRun(fn () => DB::table('ba_terminal_applicability')->where('project_id', $this->p)->count()));
    }

    public function test_hidden_old_link_withholds_historical_question_after_safe_relink(): void
    {
        $this->baInputs();
        $this->baRole();
        $other = $this->pmActor();
        $employee = $this->baRun(fn () => DB::table('users')->where('id', $other->id)->value('employee_id'));
        $body = $this->requestInput();
        $oldTask = $this->baTask(0, $employee);
        $body['question'] = 'Private old question';
        $body['links'][0]['task_version_id'] = $oldTask['version_id'];
        $request = $this->openRequest($body);
        $freshBody = $this->requestInput();
        $freshBody['question'] = 'Public fresh question';
        app(BaClarificationService::class)->event($this->actor(), $this->p, $request['clarification_id'], ['clarification_id' => $request['clarification_id'], 'expected_revision' => 1, 'kind' => 'request.revise', 'reason' => 'Synthetic relink', 'request' => $freshBody], 'relink');
        $this->baRun(fn () => DB::table('project_memberships')->where('project_id', $this->p)->where('employee_id', $employee)->update(['valid_to' => now()->subSecond()]));
        $this->assertCount(1, app(BaReadService::class)->queue($this->actor(), $this->p)['items']);
        $page = app(BaReadService::class)->history($this->actor(), $this->p, $request['clarification_id']);
        $this->assertSame('unknown', $page['coverage_status']);
        $this->assertNull($page['visible_record_count']);
        $this->assertNull($page['next_cursor']);
        $this->assertStringNotContainsString('Private old question', json_encode($page));
        $this->assertSame(['request.revise'], array_column($page['items'], 'kind'));
    }

    public function test_late_answer_history_delivers_recorded_subject_and_preserves_projection(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $first = $this->recordAnswer($request);
        $body = $this->answerInput($request, 2);
        $body['predecessor_answer_version_id'] = $first['answer_version_id'];
        $body['answer'] = 'Newer revision three';
        $service = app(BaAnswerService::class);
        $newest = $service->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.correct', $body, 'response', 3), 'third', 1);
        $body['expected_revision'] = 3;
        $body['answer'] = 'Late revision two';
        $late = $service->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.correct', $body, 'response', 2), 'late', 2);
        $page = app(BaReadService::class)->history($this->actor(), $this->p, $request['clarification_id']);
        $event = $page['items'][3];
        $this->assertSame($late['answer_version_id'], $event['subject_answer_version_id']);
        $this->assertSame($newest['answer_version_id'], $event['answer_version_id']);
        $this->assertSame('Late revision two', $event['answer']['answer']);
        $this->assertSame('Newer revision three', app(BaReadService::class)->queue($this->actor(), $this->p)['items'][0]['answer']);
    }

    public function test_historical_event_responsibility_retains_exact_old_task_and_event_time(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $fresh = $this->requestInput();
        app(BaClarificationService::class)->event($this->actor(), $this->p, $request['clarification_id'], ['clarification_id' => $request['clarification_id'], 'expected_revision' => 1, 'kind' => 'request.revise', 'reason' => 'Synthetic relink', 'request' => $fresh], 'relink');
        $page = app(BaReadService::class)->history($this->actor(), $this->p, $request['clarification_id']);
        $this->assertArrayHasKey('responsibilities', $page['items'][0]);
        $old = $page['items'][0]['responsibilities'][0];
        $current = $page['items'][1]['responsibilities'][0];
        $this->assertSame($request['body']['links'][0]['task_version_id'], $old['task_version_id']);
        $this->assertSame($request['body']['requested_at'], $old['occurred_at']);
        $this->assertSame($this->employee, $old['employee_id']);
        $this->assertSame('known', $old['attribution']);
        $this->assertSame($fresh['links'][0]['task_version_id'], $current['task_version_id']);
    }

    public function test_immutable_events_preserve_original_answer_and_correction(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $answer = $this->recordAnswer($request);
        app(BaReviewService::class)->review($this->pmActor(), $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'review');
        $body = $this->answerInput($request, 3);
        $body['predecessor_answer_version_id'] = $answer['answer_version_id'];
        $body['answer'] = 'Synthetic corrected answer';
        $corrected = app(BaAnswerService::class)->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.correct', $body, 'response', 2), 'correction', 1);
        $page = app(BaReadService::class)->history($this->actor(), $this->p, $request['clarification_id']);
        $this->assertSame('complete', $page['coverage_status']);
        $this->assertSame(['request.open', 'answer.record', 'review.confirm', 'answer.correct'], array_column($page['items'], 'kind'));
        $this->assertSame('Synthetic merge duplicates', $page['items'][1]['answer']['answer']);
        $this->assertSame($answer['answer_version_id'], $page['items'][1]['subject_answer_version_id']);
        $this->assertSame($corrected['answer_version_id'], $page['items'][3]['subject_answer_version_id']);
        $this->assertSame('Synthetic corrected answer', $page['items'][3]['answer']['answer']);
    }
}
