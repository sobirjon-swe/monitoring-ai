<?php

namespace Tests\Feature;

use App\Domain\Ba\BaAnswerService;
use App\Domain\Ba\BaPagination;
use App\Domain\Ba\BaPins;
use App\Domain\Ba\BaReadService;
use App\Domain\Ba\BaReviewService;
use App\Domain\Lifecycle\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BaTestCase;

class BaProjectionBoundaryTest extends BaTestCase
{
    public function test_legacy_missing_subject_provenance_remains_readable_unknown_without_terminal_marker(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->seedBaRequest();
        $page = app(BaReadService::class)->queue($this->actor(), $this->p);
        $this->assertCount(1, $page['items']);
        $this->assertSame('unknown', $page['items'][0]['applicability']);
        $this->assertContains('request_subject_proof_missing', $page['items'][0]['reasons']);
        $this->assertSame(0, $this->baRun(fn () => DB::table('ba_terminal_applicability')->where('project_id', $this->p)->count()));
    }

    public function test_postgresql_mandatory_metadata_probe_withholds_malformed_interval_as_unsupported(): void
    {
        $this->baInputs();
        $this->baRole();
        $request = $this->openRequest();
        $proof = $this->baRun(fn () => json_decode(DB::table('ba_request_versions')->where('id', $request['request_version_id'])->value('authority_proof'), true));
        $service = app(BaReadService::class);
        $valid = (new \ReflectionMethod($service, 'proofValiditySql'))->invoke($service, 'p.proof');
        $supported = (new \ReflectionMethod($service, 'supportedProofSql'))->invoke($service, 'p.proof');
        $probe = fn (array $input) => $this->baRun(fn () => DB::selectOne("SELECT ($supported) AS supported, ($valid) AS valid FROM ba_clarifications c CROSS JOIN LATERAL (SELECT ?::jsonb AS proof) p WHERE c.project_id=?::uuid AND c.id=?::uuid", [json_encode($input), $this->p, $request['clarification_id']]));
        $healthy = $probe($proof);
        $this->assertTrue($healthy->supported);
        $this->assertTrue($healthy->valid);
        foreach (['interval', 'actor', 'connector_revision'] as $field) {
            $malformed = $proof;
            if ($field === 'interval') {
                $malformed['membership']['valid_from'] = 'malformed timestamp';
            }
            if ($field === 'actor') {
                $malformed['actor_id'] = 'malformed uuid';
            }
            if ($field === 'connector_revision') {
                $malformed['sources'][0]['connector_revision'] = 'malformed integer';
            }
            $unknown = $probe($malformed);
            $this->assertFalse($unknown->supported);
            $this->assertFalse($unknown->valid);
        }
        $this->assertSame(0, $this->baRun(fn () => DB::table('ba_terminal_applicability')->where('project_id', $this->p)->count()));
    }

    public function test_queue_original_task_interval_ends_at_exact_successor_microsecond(): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        $taskId = $this->baRun(fn () => DB::table('task_versions')->where('id', $body['links'][0]['task_version_id'])->value('task_id'));
        $other = $this->pmActor();
        $employee = $this->baRun(fn () => DB::table('users')->where('id', $other->id)->value('employee_id'));
        $new = app(TaskService::class)->revise($this->actor(), $this->p, $taskId, ['title' => 'Reassigned synthetic task', 'assignee_id' => $employee, 'estimate_basis' => 'Synthetic only', 'remaining_effort' => null, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => [['criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id']]]], 1, $this->generation);
        $this->generation = $new['generation'];
        $boundary = $this->baRun(fn () => DB::table('task_versions')->where('id', $new['version_id'])->value('effective_at'));
        $body['requested_at'] = CarbonImmutable::parse($boundary)->subMicrosecond()->utc()->format('Y-m-d\TH:i:s.u\Z');
        $before = $this->openRequest($body, 'before');
        $body['requested_at'] = CarbonImmutable::parse($boundary)->utc()->format('Y-m-d\TH:i:s.u\Z');
        $at = $this->openRequest($body, 'boundary');
        $items = array_column(app(BaReadService::class)->queue($this->actor(), $this->p)['items'], null, 'clarification_id');
        $this->assertSame('known', $items[$before['clarification_id']]['responsibilities'][0]['attribution']);
        $this->assertSame($this->employee, $items[$before['clarification_id']]['responsibilities'][0]['employee_id']);
        $this->assertSame('unknown', $items[$at['clarification_id']]['responsibilities'][0]['attribution']);
        $this->assertNull($items[$at['clarification_id']]['responsibilities'][0]['employee_id']);
    }

    public function test_twenty_one_exact_links_reject_without_new_ba_facts(): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        for ($i = 1; $i < 21; $i++) {
            $task = $this->baTask();
            $body['links'][] = ['task_version_id' => $task['version_id'], 'criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id'], 'relation' => 'blocks'];
        }
        $before = $this->baCounts();
        try {
            $this->openRequest($body);
            $this->fail('Expected 21-link rejection.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $this->baCounts());
    }

    public function test_one_hundred_original_actors_fit_and_one_hundred_one_is_unknown_without_terminal_writes(): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        for ($i = 0; $i < 50; $i++) {
            $request = $this->openRequest($body, 'actor-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $recorder = $this->otherBaActor();
            $answer = app(BaAnswerService::class)->record($recorder, $this->p, $request['clarification_id'], $this->baEnvelope('answer.record', $this->answerInput($request), 'answer-'.$i), 'answer-'.$i, 0);
            if ($i < 49) {
                app(BaReviewService::class)->review($this->pmActor(), $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'confirm-'.$i);
            }
        }
        $service = app(BaReadService::class);
        $page = $service->queue($this->actor(), $this->p, ['limit' => 50]);
        $this->assertSame('complete', $page['coverage_status']);
        $this->assertCount(50, $page['items']);
        app(BaReviewService::class)->review($this->pmActor(), $this->p, $request['clarification_id'], $this->reviewInput($request, $answer), 'confirm-49');
        $page = $service->queue($this->actor(), $this->p, ['limit' => 50]);
        $this->assertSame('unknown', $page['coverage_status']);
        $this->assertSame([], $page['items']);
        $this->assertSame(['coverage_limit'], $page['reason_codes']);
        $this->assertNull($page['next_cursor']);
        $this->assertNull($page['visible_record_count']);
        $this->assertSame(0, $this->baRun(fn () => DB::table('ba_terminal_applicability')->where('project_id', $this->p)->count()));
        $this->assertCount(1, $service->queue($this->actor(), $this->p, ['limit' => 1])['items']);
    }

    public static function finalClockKinds(): array
    {
        return [['named'], ['source']];
    }

    #[DataProvider('finalClockKinds')]
    public function test_named_membership_and_source_grant_survive_actual_delivery_final_clock(string $kind): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        $other = $this->pmActor();
        $employee = $this->baRun(fn () => DB::table('users')->where('id', $other->id)->value('employee_id'));
        $body['requester_id'] = $employee;
        $this->openRequest($body, 'expiry-'.$kind);
        $expiry = DB::scalar("select clock_timestamp()+interval '3 seconds'");
        $this->baRun(function () use ($kind, $employee, $expiry) {
            if ($kind === 'named') {
                DB::table('project_memberships')->where('project_id', $this->p)->where('employee_id', $employee)->update(['valid_to' => $expiry]);
            } else {
                DB::table('access_grants')->where('user_id', $this->user)->where('project_id', $this->p)->where('purpose', 'ba.source.read')->update(['valid_to' => $expiry]);
            }
        });
        $captured = false;
        DB::listen(function ($query) use (&$captured, $expiry) {
            if ($captured || ! str_contains($query->sql, "set_config('app.ba_required_keys',?,true)")) {
                return;
            }
            $captured = true;
            while (DB::scalar('select clock_timestamp() < ?::timestamptz', [$expiry])) {
                DB::select('select pg_sleep(0.02)');
            }
        });
        $this->assertBaDenied(fn () => app(BaReadService::class)->queue($this->actor(), $this->p));
        $this->assertTrue($captured);
    }

    public function test_future_time_is_unknown_and_cursors_are_bound_to_scope(): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        $body['requested_at'] = '2099-01-01T00:00:00.000000Z';
        $this->openRequest($body);
        $item = app(BaReadService::class)->queue($this->actor(), $this->p)['items'][0];
        $this->assertNull($item['elapsed_age']);
        $this->assertSame('unknown', $item['applicability']);
        $pagination = app(BaPagination::class);
        $cursor = $pagination->encode(['actor' => 'one'], ['after' => 1]);
        $this->assertSame(['after' => 1], $pagination->decode($cursor, ['actor' => 'one']));
        $this->expectException(\InvalidArgumentException::class);
        $pagination->decode($cursor, ['actor' => 'two']);
    }

    public function test_limit_51_rejects(): void
    {
        $this->baInputs();
        $this->expectException(\InvalidArgumentException::class);
        app(BaReadService::class)->queue($this->actor(), $this->p, ['limit' => 51]);
    }

    public function test_same_actor_fifty_twenty_link_page_exceeds_one_hundred_proofs_without_n_plus_one(): void
    {
        $this->baInputs();
        $this->baRole();
        $body = $this->requestInput();
        $first = $this->openRequest($body, 'small');
        $other = $this->pmActor();
        $employee = $this->baRun(fn () => DB::table('users')->where('id', $other->id)->value('employee_id'));
        $invalidBody = $body;
        $invalidBody['requester_id'] = $employee;
        $invalidBody['question'] = 'Withheld invalid authority question';
        $invalid = $this->openRequest($invalidBody, 'aaa-invalid');
        $this->baRun(function () use ($employee) {
            DB::table('project_memberships')->where('project_id', $this->p)->where('employee_id', $employee)->update(['valid_to' => now()->subSecond()]);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'valid_from' => now()]);
        });
        $observerGraph = app(BaPins::class)->collect($this->actor(), $this->p, [$invalid['clarification_id']]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $small = app(BaReadService::class)->queue($this->actor(), $this->p, ['limit' => 1]);
        $smallLog = DB::getQueryLog();
        DB::disableQueryLog();
        $links = $body['links'];
        for ($i = 1; $i < 20; $i++) {
            $task = $this->baTask();
            $links[] = ['task_version_id' => $task['version_id'], 'criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id'], 'relation' => 'blocks'];
        }
        $body['links'] = $links;
        $roots = [];
        for ($i = 0; $i < 50; $i++) {
            $row = $this->openRequest($body, 'max-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $roots[] = $row['clarification_id'];
        }
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, $roots);
        $this->assertSame('complete', $graph['coverage']);
        $this->assertSame(1000, $graph['categories']['links']);
        $this->assertGreaterThan(100, $graph['categories']['proofs']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $large = app(BaReadService::class)->queue($this->actor(), $this->p, ['limit' => 50]);
        $largeLog = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(50, $large['items']);
        $this->assertSame('complete', $large['coverage_status']);
        $this->assertStringNotContainsString('Withheld invalid authority question', json_encode($large));
        foreach ($large['items'] as $item) {
            $this->assertCount(20, $item['links']);
            $this->assertCount(20, $item['responsibilities']);
            $this->assertArrayNotHasKey('required_proof_keys', $item);
        }
        $this->assertLessThanOrEqual(count($smallLog) + 12, count($largeLog));
        $this->assertCount(20, app(BaReadService::class)->queue($this->actor(), $this->p)['items']);
        $categories = function (array $log): array {
            $counts = [];
            foreach ($log as $query) {
                preg_match('/\b(?:from|into|update)\s+"?([a-z_]+)/i', $query['query'], $match);
                $category = $match[1] ?? 'coordination_control';
                $counts[$category] = ($counts[$category] ?? 0) + 1;
            }
            ksort($counts);

            return $counts;
        };
        file_put_contents(base_path('../.superpowers/sdd/2026-10-07-e05-ba-clarification-plan/task-3-measurement.json'), json_encode(['small_queries' => count($smallLog), 'large_queries' => count($largeLog), 'small_categories' => $categories($smallLog), 'large_categories' => $categories($largeLog), 'graph_categories' => $graph['categories'], 'observer_graph_categories' => $observerGraph['categories'], 'dto_bytes' => strlen(json_encode($large)), 'small_sql' => array_column($smallLog, 'query'), 'large_sql' => array_column($largeLog, 'query')], JSON_PRETTY_PRINT));
    }
}
