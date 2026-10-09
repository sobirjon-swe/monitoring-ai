<?php

namespace Tests\Feature;

use App\Domain\Ba\BaResponsibility;
use App\Domain\Ba\BaReviewService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BaTestCase;

final class BaConcurrencyTest extends BaTestCase
{
    public static function raceModes(): array
    {
        return [['answer'], ['review'], ['reopen']];
    }

    #[DataProvider('raceModes')]
    public function test_two_process_competing_actions_have_one_winner_no_orphans(string $mode): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $actor = $this->actor();
        if ($mode === 'answer') {
            $pins = ['root' => $request['clarification_id'], 'envelope' => $this->baEnvelope('answer.record', $this->answerInput($request), 'race-answer'), 'source_cas' => 0];
        } else {
            $answer = $this->recordAnswer($request);
            $pm = $this->pmActor();
            $body = $this->reviewInput($request, $answer);
            if ($mode === 'review') {
                $pins = ['root' => $request['clarification_id'], 'body' => $body];
                $actor = $pm;
            } else {
                app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $body, 'confirm');
                $pins = ['root' => $request['clarification_id'], 'body' => ['clarification_id' => $request['clarification_id'], 'expected_revision' => 3, 'kind' => 'request.reopen', 'reason' => 'Synthetic race reopen', 'request' => $request['body']]];
            }
        }
        $before = $this->baCounts();
        $this->contendBa($mode, $pins, $actor);
        $after = $this->baCounts();
        foreach (['ba_receipts', 'ba_events', 'ba_request_bindings'] as $table) {
            $this->assertSame($before[$table] + 1, $after[$table], $table);
        }
        $this->assertSame($before['ba_answer_versions'] + (int) ($mode === 'answer'), $after['ba_answer_versions']);
        $this->assertSame($before['ba_reviews'] + (int) ($mode === 'review'), $after['ba_reviews']);
    }

    public function test_responsibility_age_is_elapsed_only_and_future_is_unknown(): void
    {
        $service = app(BaResponsibility::class);
        $result = $service->age(['requested_at' => '2026-10-06T00:00:00.000000Z'], '2026-10-07T00:00:00.000000Z');
        $this->assertSame(86400, $result['elapsed_seconds']);
        $this->assertNull($result['business_age']);
        $this->assertSame('calendar_unavailable', $result['business_age_reason']);
        $this->assertNull($service->age(['requested_at' => '2099-01-01T00:00:00.000000Z'], '2026-10-07T00:00:00.000000Z')['elapsed_seconds']);
    }
}
