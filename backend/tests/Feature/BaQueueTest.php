<?php

namespace Tests\Feature;

use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaReadService;
use App\Domain\Ba\BaReviewService;
use App\Domain\Lifecycle\BaselineService;
use Illuminate\Support\Facades\DB;
use Tests\Support\BaTestCase;

class BaQueueTest extends BaTestCase
{
    public function test_project_closed_after_hint_never_delivers_current_dependency_evaluation(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->openRequest();
        $closed = false;
        DB::listen(function ($query) use (&$closed) {
            if ($closed || ! str_contains($query->sql, '"c"."current_request_id"')) {
                return;
            }
            $closed = true;
            app(BaselineService::class)->close($this->actor(), $this->p, $this->generation, 'Synthetic closure between hint and delivery');
        });
        $page = app(BaReadService::class)->queue($this->actor(), $this->p);
        $this->assertTrue($closed);
        $this->assertSame('unavailable', $page['coverage_status']);
        $this->assertSame([], $page['items']);
        $this->assertSame(['project_closed'], $page['reason_codes']);
    }

    public function test_customer_wait_answer_review_and_exact_resolution_are_separate(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $service = app(BaReadService::class);
        $page = $service->queue($this->actor(), $this->p);
        $this->assertSame('complete', $page['coverage_status']);
        $this->assertSame(1, $page['visible_record_count']);
        $item = $page['items'][0];
        $this->assertSame('customer', $item['waiting_on']);
        $this->assertSame('unresolved', $item['dependency_status']);
        $this->assertNull($item['business_age']);
        $this->assertSame('calendar_unavailable', $item['business_age_reason']);
        $this->assertTrue($item['synthetic']);
        $this->assertSame('unavailable', $item['ai_interpretation']);
        $this->assertSame('not_verified', $item['customer_authenticity']);
        $answer = $this->recordAnswer($request);
        $this->assertSame('pm', $service->queue($this->actor(), $this->p)['items'][0]['waiting_on']);
        app(BaReviewService::class)->review($this->pmActor(), $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'confirm');
        $resolved = $service->queue($this->actor(), $this->p)['items'][0];
        $this->assertSame('decision_resolved', $resolved['dependency_status']);
        $this->assertNull($resolved['waiting_on']);
        $this->assertArrayNotHasKey('task_ready', $resolved);
    }

    public function test_scope_origin_survives_reopen_and_no_scope_change_confirmation(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $answer = $this->recordAnswer($request);
        $pm = $this->pmActor();
        app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer, 2, 'scope_change_required'), 'scope');
        $service = app(BaReadService::class);
        $this->assertSame('scope', $service->queue($this->actor(), $this->p)['items'][0]['waiting_on']);
        $fresh = app(BaClarificationService::class)->event($this->actor(), $this->p, $request['clarification_id'], ['clarification_id' => $request['clarification_id'], 'expected_revision' => 3, 'kind' => 'request.reopen', 'reason' => 'Synthetic reopen', 'request' => $request['body']], 'reopen');
        $answer = $this->recordAnswer($fresh, 4, 'new-answer');
        app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($fresh, $answer, 5), 'new-review');
        $item = $service->queue($this->actor(), $this->p)['items'][0];
        $this->assertSame('unknown', $item['scope_status']);
        $this->assertSame('unresolved', $item['dependency_status']);
    }
}
