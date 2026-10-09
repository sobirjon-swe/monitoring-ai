<?php

namespace Tests\Feature;

use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaContract;
use App\Domain\Ba\BaPins;
use App\Domain\Ba\BaReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\BaTestCase;

final class BaClarificationTest extends BaTestCase
{
    public function test_exact_request_context_withdraw_reopen_and_revise_preserve_e03(): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        $before = $this->progress();
        $e03 = $this->e03Counts();
        $request = $this->openRequest($body);
        $service = app(BaClarificationService::class);
        $root = $request['clarification_id'];
        $context = ['clarification_id' => $root, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        $service->event($this->actor(), $this->p, $root, $context, 'context');
        $service->event($this->actor(), $this->p, $root, ['clarification_id' => $root, 'expected_revision' => 2, 'kind' => 'request.withdraw', 'reason' => 'Synthetic withdrawal'], 'withdraw');
        $reopen = $service->event($this->actor(), $this->p, $root, ['clarification_id' => $root, 'expected_revision' => 3, 'kind' => 'request.reopen', 'reason' => 'Synthetic reopen', 'request' => $body], 'reopen');
        $this->assertNotSame($request['request_version_id'], $reopen['request_version_id']);
        $body['question'] = 'Synthetic revised question';
        $service->event($this->actor(), $this->p, $root, ['clarification_id' => $root, 'expected_revision' => 4, 'kind' => 'request.revise', 'reason' => 'Synthetic revise', 'request' => $body], 'revise');
        $this->baRun(function () use ($root) {
            $head = DB::table('ba_clarifications')->where('id', $root)->first();
            $this->assertSame(5, $head->revision);
            $this->assertSame('open', $head->state);
            $this->assertNull($head->current_answer_id);
            $this->assertNull($head->current_review_id);
            $this->assertSame(3, DB::table('ba_request_versions')->where('clarification_id', $root)->count());
        });
        $this->assertSame($before, $this->progress());
        $this->assertSame($e03, $this->e03Counts());
    }

    public function test_source_replay_precedes_obsolete_cas_and_canonical_link_order(): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        $second = $this->baTask(1);
        $body['links'][] = ['task_version_id' => $second['version_id'], 'criterion_id' => $this->criteria[1]['id'], 'criterion_version_id' => $this->criteria[1]['version_id'], 'relation' => 'context'];
        $first = $this->openRequest($body);
        $body['links'] = array_reverse($body['links']);
        $replay = app(BaClarificationService::class)->open($this->actor(), $this->p, $this->baEnvelope('request.open', $body, 'question'), 'alias', 900);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['event_id'], $replay['event_id']);
        $this->assertSame(1, $this->baCounts()['ba_events']);
    }

    public static function invalidInputs(): array
    {
        return [['tuple'], ['party'], ['owner'], ['duplicate'], ['zero'], ['twenty-one'], ['source']];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_request_is_atomic(string $case): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        if ($case === 'tuple') {
            $body['links'][0]['criterion_id'] = $this->criteria[1]['id'];
            $body['links'][0]['criterion_version_id'] = $this->criteria[1]['version_id'];
        }
        if ($case === 'party') {
            $body['decision_owner_party_version_id'] = (string) Str::uuid();
        }
        if ($case === 'owner') {
            $body['decision_owner_party_version_id'] = null;
            $body['decision_owner_id'] = (string) Str::uuid();
        }
        if ($case === 'duplicate') {
            $body['links'][] = $body['links'][0];
        }
        if ($case === 'zero') {
            $body['links'] = [];
        }
        if ($case === 'twenty-one') {
            $body['links'] = array_fill(0, 21, $body['links'][0]);
        }
        $envelope = $this->baEnvelope('request.open', $body, 'bad');
        if ($case === 'source') {
            $envelope['source_instance_id'] = (string) Str::uuid();
        }
        $before = $this->baCounts();
        try {
            app(BaClarificationService::class)->open($this->actor(), $this->p, $envelope, 'bad', 0);
            $this->fail('Expected invalid request.');
        } catch (\InvalidArgumentException|NotFoundHttpException|AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $this->baCounts());
    }

    public function test_twenty_exact_links_are_admitted(): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        for ($i = 1; $i < 20; $i++) {
            $task = $this->baTask();
            $body['links'][] = array_replace($body['links'][0], ['task_version_id' => $task['version_id']]);
        }
        $request = $this->openRequest($body);
        $this->baRun(fn () => $this->assertSame(20, DB::table('ba_request_links')->where('clarification_id', $request['clarification_id'])->count()));
    }

    public function test_selected_coordinator_requires_effective_ba_role_independently_of_recorder(): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        $pm = $this->pmActor();
        $body['coordinator_id'] = $this->baRun(fn () => DB::table('users')->where('id', $pm->id)->value('employee_id'));
        $before = $this->baCounts();
        $this->assertBaDenied(fn () => $this->openRequest($body));
        $this->assertSame($before, $this->baCounts());
    }

    public function test_distinct_ba_coordinator_role_is_pinned_and_replacement_cannot_repair_request(): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        $coordinator = $this->otherBaActor();
        $body['coordinator_id'] = $this->baRun(fn () => DB::table('users')->where('id', $coordinator->id)->value('employee_id'));
        $request = $this->openRequest($body);
        $answer = $this->recordAnswer($request);
        $pm = $this->pmActor();
        $proof = $this->baRun(fn () => json_decode(DB::table('ba_request_versions')->where('id', $request['request_version_id'])->value('authority_proof'), true));
        $this->assertSame($body['coordinator_id'], $proof['coordinators'][0]['employee_id']);
        $role = $proof['coordinators'][0]['roles'][0]['id'];
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, [$request['clarification_id']]);
        $this->assertContains($body['coordinator_id'], $graph['named_refs']);
        $this->baRun(function () use ($role, $body) {
            DB::table('role_assignments')->where('id', $role)->update(['valid_to' => now()->subSecond()]);
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $body['coordinator_id'], 'role' => 'ba', 'valid_from' => now()]);
        });
        $before = $this->baCounts();
        $this->assertBaDenied(fn () => app(BaReviewService::class)->review($pm, $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'old-coordinator'));
        $this->assertSame($before, $this->baCounts());
        $this->assertBaDenied(fn () => app(BaClarificationService::class)->open($this->actor(), $this->p, $this->baEnvelope('request.open', $body, 'question'), 'alias', 99));
        $this->assertSame($before, $this->baCounts());
    }

    public function test_selected_coordinator_role_expiry_after_request_writes_rolls_back_every_fact(): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        $coordinator = $this->otherBaActor();
        $body['coordinator_id'] = $this->baRun(fn () => DB::table('users')->where('id', $coordinator->id)->value('employee_id'));
        $expiry = $this->baRun(function () use ($body) {
            $expiry = DB::scalar("select clock_timestamp()+interval '3 seconds'");
            DB::table('role_assignments')->where('employee_id', $body['coordinator_id'])->where('role', 'ba')->update(['valid_to' => $expiry]);

            return $expiry;
        });
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
        $this->assertBaDenied(fn () => $this->openRequest($body));
        $this->assertTrue($written);
        $this->assertSame($before, $this->baCounts());
    }

    public function test_source_reservation_with_coordinator_payload_cannot_exempt_original_limit(): void
    {
        $this->baInputs();
        $this->baRole();
        $requests = [];
        for ($i = 0; $i < 2300; $i++) {
            $requests[] = ['key' => 'original-'.$i, 'actor_id' => $this->user, 'purposes' => ['ba.read'], 'required' => false];
        }
        $requests[] = ['key' => 'sources', 'actor_id' => $this->user, 'purposes' => ['ba.source.read'], 'required' => true, 'source_ids' => [$this->baEnvelope()['source_instance_id']], 'coordinator_refs' => [$this->employee]];
        $this->assertBaDenied(fn () => $this->baRun(fn () => $this->fail('Malformed reservation must never run work.'), $requests));
        $this->baRun(fn () => $this->assertSame(0, DB::table('ba_terminal_applicability')->count()));
    }

    public static function maximumBoundaryModes(): array
    {
        return [['request.revise'], ['request.reopen']];
    }

    #[DataProvider('maximumBoundaryModes')]
    public function test_twenty_link_boundary_transition_replay_is_valid_and_twenty_one_rejects(string $kind): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        for ($i = 1; $i < 20; $i++) {
            $task = $this->baTask();
            $body['links'][] = array_replace($body['links'][0], ['task_version_id' => $task['version_id']]);
        }
        $body['requested_at'] = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $request = $this->openRequest($body);
        $service = app(BaClarificationService::class);
        $revision = 1;
        if ($kind === 'request.reopen') {
            $service->event($this->actor(), $this->p, $request['clarification_id'], ['clarification_id' => $request['clarification_id'], 'expected_revision' => 1, 'kind' => 'request.withdraw', 'reason' => 'Synthetic max boundary withdrawal'], 'withdraw');
            $revision = 2;
        }
        $e03 = $this->e03Counts();
        $badBody = $body;
        $badBody['links'][] = $body['links'][0];
        $event = ['clarification_id' => $request['clarification_id'], 'expected_revision' => $revision, 'kind' => $kind, 'reason' => 'Synthetic maximum boundary', 'request' => $body];
        $before = $this->baCounts();
        try {
            $service->event($this->actor(), $this->p, $request['clarification_id'], array_replace($event, ['request' => $badBody]), 'bad21');
            $this->fail('Expected twenty-one link rejection.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $this->baCounts());
        $new = $service->event($this->actor(), $this->p, $request['clarification_id'], $event, 'maximum');
        $after = $this->baCounts();
        $replay = $service->event($this->actor(), $this->p, $request['clarification_id'], $event, 'maximum');
        $this->assertTrue($replay['replayed']);
        $this->assertSame($new['request_version_id'], $replay['request_version_id']);
        $this->assertSame($after, $this->baCounts());
        $this->assertSame($e03, $this->e03Counts());
        $this->baRun(function () use ($new, $body) {
            $version = DB::table('ba_request_versions')->where('id', $new['request_version_id'])->first();
            $canonical = app(BaContract::class)->validate('request', $body);
            $this->assertSame(hash('sha256', BaContract::canonical($canonical)), $version->request_hash);
            $this->assertSame(hash('sha256', BaContract::canonical($canonical['links'])), $version->links_hash);
            $this->assertSame(20, DB::table('ba_request_links')->where('request_version_id', $version->id)->count());
        });
    }
}
