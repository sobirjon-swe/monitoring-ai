<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditWriter;
use App\Domain\DeveloperAnalysis\AnalysisProcessor;
use App\Domain\DeveloperAnalysis\FindingReviewService;
use App\Domain\DeveloperAnalysis\SyntheticMrRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\DeveloperAnalysisTestCase;
use Tests\Support\DeveloperProcessorFixtures;

class DeveloperFindingReviewTest extends DeveloperAnalysisTestCase
{
    use DeveloperProcessorFixtures;

    private function findingFixture(): array
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $this->runner(fn ($input) => $this->fixtureOutput($input));
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $finding = $this->scoped(fn () => DB::table('developer_findings')->where('run_id', $run['id'])->where('kind', 'composition_fact')->value('id'));
        $this->withToken($this->token);

        return [$project, $run['id'], $finding];
    }

    private function review(string $finding, int $revision, string $decision = 'context-added', string $reason = 'Synthetic context'): mixed
    {
        return $this->postJson('/api/v1/findings/'.$finding.'/reviews', ['expected_revision' => $revision, 'decision' => $decision, 'reason' => $reason]);
    }

    public function test_first_observed_stale_read_returns_cancelled_status_and_actual_reason(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $this->withToken($this->token);
        $this->scoped(fn () => DB::table('lifecycle_project_state')->insert(['tenant_id' => $this->tenant, 'project_id' => $project, 'is_closed' => true]));
        $this->getJson('/api/v1/analysis-runs/'.$run['id'])->assertOk()->assertJsonPath('data.applicability', 'stale')
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.reason_code', 'project_closed');
    }

    public function test_action_time_uses_actual_postgresql_clock_and_authority_expiry_rolls_back_history(): void
    {
        [, , $finding] = $this->findingFixture();
        $before = DB::scalar('select clock_timestamp()');
        Carbon::setTestNow(Carbon::now()->addDay());
        try {
            $time = app(FindingReviewService::class)->review($this->actor(), $finding, 0, 'context-added', 'Synthetic actual time')['effective_at'];
        } finally {
            Carbon::setTestNow();
        }
        $this->assertGreaterThanOrEqual($before, $time);
        $this->assertLessThanOrEqual(DB::scalar('select clock_timestamp()'), $time);
        $this->scoped(fn () => DB::table('access_grants')->where('purpose', 'analysis.review')->update(['valid_to' => DB::raw("clock_timestamp() + interval '2 seconds'")]));
        $this->app->instance(AuditWriter::class, new class extends AuditWriter
        {
            public function record(string $actor, array $scope, string $action, array $resource, int $revision, string $reason): void
            {
                parent::record($actor, $scope, $action, $resource, $revision, $reason);
                if ($action === 'developer.finding_reviewed') {
                    usleep(2200000); // The real captured authority expires during work, before commit.
                }
            }
        });
        $this->review($finding, 1, 'verified')->assertForbidden();
        $this->scoped(function () use ($finding): void {
            $this->assertSame(1, DB::table('developer_reviews')->where('finding_id', $finding)->count());
            $this->assertSame(1, DB::table('developer_findings')->where('id', $finding)->value('review_revision'));
            $this->assertSame(1, DB::table('audit_events')->where('action', 'developer.finding_reviewed')->count());
        });
    }

    public function test_review_transport_rejects_unknown_fields_ambiguous_types_and_untrusted_authority(): void
    {
        [, , $finding] = $this->findingFixture();
        $base = ['expected_revision' => 0, 'decision' => 'verified', 'reason' => 'Synthetic reason'];
        foreach ([['actor_id' => $this->user], ['expected_revision' => '0'], ['expected_revision' => -1], ['expected_revision' => 2147483647],
            ['reason' => []], ['decision' => 'approved'], ['decision' => []]] as $change) {
            $this->postJson('/api/v1/findings/'.$finding.'/reviews', array_replace($base, $change))->assertStatus(422);
        }
        $this->postJson('/api/v1/findings/'.$finding.'/reviews?extra=1', $base)->assertStatus(422);
        $this->call('POST', '/api/v1/findings/'.$finding.'/reviews', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], '{"expected_revision":0,"expected_revision":1,"decision":"verified","reason":"Synthetic reason"}')->assertStatus(422);
        $this->scoped(fn () => $this->assertSame(0, DB::table('developer_reviews')->count()));
    }

    public function test_context_preserves_pending_rejected_and_verified_without_mutating_facts_or_acceptance(): void
    {
        [$project, $run, $finding] = $this->findingFixture();
        $before = $this->scoped(fn () => DB::table('developer_findings')->where('id', $finding)->first());
        $state = $this->scoped(fn () => DB::table('lifecycle_project_state')->where('project_id', $project)->first());
        $this->review($finding, 0)->assertOk()->assertJsonPath('data.disposition', 'pending')->assertJsonPath('data.review_revision', 1);
        $this->review($finding, 1, 'rejected')->assertOk()->assertJsonPath('data.disposition', 'rejected');
        $this->review($finding, 2)->assertOk()->assertJsonPath('data.disposition', 'rejected');
        $this->getJson('/api/v1/analysis-runs/'.$run)->assertOk()->assertJsonCount(0, 'data.verified_findings');
        $this->review($finding, 3, 'verified')->assertOk()->assertJsonPath('data.disposition', 'verified');
        $this->review($finding, 4)->assertOk()->assertJsonPath('data.disposition', 'verified');
        $this->review($finding, 4)->assertStatus(409);
        $this->getJson('/api/v1/analysis-runs/'.$run)->assertOk()->assertJsonCount(1, 'data.verified_findings')->assertJsonPath('data.verified_findings.0.id', $finding);
        $this->scoped(function () use ($finding, $before, $state, $project): void {
            $after = DB::table('developer_findings')->where('id', $finding)->first();
            foreach (['facts', 'summary', 'kind', 'run_id', 'result_id', 'snapshot_id'] as $key) {
                $this->assertSame($before->$key, $after->$key);
            }
            $this->assertSame(5, DB::table('developer_reviews')->where('finding_id', $finding)->count());
            $this->assertSame(0, DB::table('human_acceptance_decisions')->where('project_id', $project)->count());
            $this->assertEquals($state, DB::table('lifecycle_project_state')->where('project_id', $project)->first());
        });
    }

    public function test_reason_is_bounded_in_utf8_bytes_and_secret_blocked_before_any_write(): void
    {
        [, , $finding] = $this->findingFixture();
        $before = $this->scoped(fn () => DB::table('audit_events')->count());
        foreach (['', " \t\n ", "\u{2003}", str_repeat('é', 1000).'x', 'password=synthetic', str_repeat('x', 2001)] as $reason) {
            $this->review($finding, 0, 'verified', $reason)->assertStatus(422)->assertDontSee('password=synthetic');
        }
        $this->scoped(function () use ($before): void {
            $this->assertSame(0, DB::table('developer_reviews')->count());
            $this->assertSame($before, DB::table('audit_events')->count());
        });
        $this->review($finding, 0, 'verified', str_repeat('é', 1000))->assertOk();
        $this->scoped(fn () => $this->assertSame(2000, strlen(DB::table('developer_reviews')->value('reason'))));
    }

    public function test_stale_review_never_rehabilitates_facts_or_accepted_summary_and_history_remains_available(): void
    {
        [$project, $run, $finding] = $this->findingFixture();
        $this->review($finding, 0, 'verified')->assertOk();
        $fixture = $this->mr();
        $fixture['revision_ordinal'] = 2;
        $fixture['head_sha'] = str_repeat('c', 40);
        app(SyntheticMrRegistry::class)->register($this->actor(), $project, $fixture, 1);
        $this->review($finding, 1, 'verified')->assertOk()->assertJsonPath('data.applicability', 'stale');
        $this->getJson('/api/v1/findings/'.$finding)->assertOk()->assertJsonPath('data.facts', null)->assertJsonPath('data.summary', null)->assertJsonCount(2, 'data.review_history.items');
        $this->getJson('/api/v1/analysis-runs/'.$run)->assertOk()->assertJsonCount(0, 'data.verified_findings');
    }

    public function test_closed_project_rejects_new_review_but_allows_authorized_history(): void
    {
        [$project, , $finding] = $this->findingFixture();
        $this->review($finding, 0)->assertOk();
        $this->scoped(fn () => DB::table('lifecycle_project_state')->insert(['tenant_id' => $this->tenant, 'project_id' => $project, 'is_closed' => true]));
        $this->review($finding, 1)->assertStatus(409);
        $this->getJson('/api/v1/findings/'.$finding)->assertOk()->assertJsonCount(1, 'data.review_history.items')->assertJsonPath('data.applicability', 'stale');
    }

    public function test_history_keyset_over_100_rows_is_stable_and_cursor_is_scoped_reauthorized_and_bounded(): void
    {
        [, $run, $finding] = $this->findingFixture();
        for ($revision = 0; $revision < 105; $revision++) {
            app(FindingReviewService::class)->review($this->actor(), $finding, $revision, 'context-added', 'Synthetic context '.$revision);
        }
        $first = $this->getJson('/api/v1/findings/'.$finding)->assertOk()->assertJsonCount(50, 'data.review_history.items');
        $cursor = $first->json('data.review_history.next_cursor');
        $this->assertNotEmpty($cursor);
        app(FindingReviewService::class)->review($this->actor(), $finding, 105, 'context-added', 'Concurrent append');
        $second = $this->getJson('/api/v1/findings/'.$finding.'?limit=50&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(50, 'data.review_history.items');
        $third = $this->getJson('/api/v1/findings/'.$finding.'?limit=100&cursor='.urlencode($second->json('data.review_history.next_cursor')))->assertOk()->assertJsonCount(6, 'data.review_history.items')->assertJsonPath('data.review_history.next_cursor', null);
        $revisions = array_merge(array_column($first->json('data.review_history.items'), 'revision'), array_column($second->json('data.review_history.items'), 'revision'), array_column($third->json('data.review_history.items'), 'revision'));
        $this->assertSame(range(1, 106), $revisions);
        $other = $this->scoped(fn () => DB::table('developer_findings')->where('run_id', $run)->where('id', '<>', $finding)->value('id'));
        $this->getJson('/api/v1/findings/'.$other.'?cursor='.urlencode($cursor))->assertStatus(422)->assertDontSee('Synthetic context');
        $unknown = rtrim(strtr(base64_encode($finding.':999'), '+/', '-_'), '=');
        $this->getJson('/api/v1/findings/'.$finding.'?cursor='.$unknown)->assertStatus(422);
        foreach (['limit=101', 'limit=0', 'limit=1.5', 'limit[]=1', 'cursor[]=x', 'extra=1', 'cursor=invalid'] as $query) {
            $this->getJson('/api/v1/findings/'.$finding.'?'.$query)->assertStatus(422);
        }
        $this->getJson('/api/v1/findings/'.$finding.'?limit=100')->assertOk()->assertJsonCount(100, 'data.review_history.items');
        $this->scoped(fn () => DB::table('access_grants')->where('purpose', 'analysis.read')->update(['revoked_at' => now()]));
        $this->getJson('/api/v1/findings/'.$finding.'?cursor='.urlencode($cursor))->assertForbidden()->assertDontSee('Synthetic context');
        $this->scoped(fn () => $this->assertSame('Synthetic context 0', DB::table('developer_reviews')->where('finding_id', $finding)->where('revision', 1)->value('reason')));
    }

    public function test_current_review_source_and_exact_original_requester_authority_are_mandatory(): void
    {
        [, , $finding] = $this->findingFixture();
        foreach (['analysis.review', 'analysis.source.read', 'analysis.read', 'analysis.request'] as $purpose) {
            $this->scoped(fn () => DB::table('access_grants')->where('purpose', $purpose)->update(['revoked_at' => now()]));
            $this->review($finding, 0)->assertForbidden();
            $this->scoped(fn () => DB::table('access_grants')->where('purpose', $purpose)->update(['revoked_at' => null]));
        }
        $this->scoped(fn () => $this->assertSame(0, DB::table('developer_reviews')->count()));
    }

    public function test_history_rewrite_is_denied_by_nonowner_postgresql_role(): void
    {
        [, , $finding] = $this->findingFixture();
        $this->review($finding, 0)->assertOk();
        try {
            $this->scoped(fn () => DB::table('developer_reviews')->where('finding_id', $finding)->update(['reason' => 'Changed history']));
            $this->fail('Immutable review was rewritten.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->errorInfo[0]);
        }
        $this->getJson('/api/v1/findings/'.$finding)->assertOk()->assertJsonPath('data.review_history.items.0.reason', 'Synthetic context');
    }

    public function test_overlapping_real_reviewers_with_same_revision_commit_exactly_one_review(): void
    {
        [$project, , $finding] = $this->findingFixture();
        DB::commit(); // Retain committed synthetic fixture for independent connections.
        DB::beginTransaction();
        $this->scoped(fn () => DB::table('projects')->where('id', $project)->lockForUpdate()->first());
        $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $stream = new InputStream;
                $child = new Process([PHP_BINARY, base_path('tests/Support/developer-review-child.php'), $this->tenant, $this->user, $finding], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'monitoring_ai_test', 'DB_URL' => ''], null, 15);
                $child->setInput($stream);
                $child->start();
                $children[] = [$child, $stream];
            }
            foreach ($children as [$child, $stream]) {
                $deadline = microtime(true) + 10;
                while (! str_contains($child->getOutput(), 'ready') && $child->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready', $child->getOutput(), $child->getErrorOutput());
                $stream->write("go\n");
                $stream->close();
            }
            $deadline = microtime(true) + 5;
            do {
                foreach ($children as [$child]) {
                    $child->isRunning(); // Pump each independent child's pending stdin before sampling locks.
                }
                DB::select('select pg_stat_clear_snapshot()');
                $blocked = (int) DB::scalar("select count(*) from pg_stat_activity where datname=current_database() and usename=current_user and pid<>pg_backend_pid() and wait_event_type='Lock'");
                if ($blocked >= 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertGreaterThanOrEqual(2, $blocked, 'Both independent review transactions must contend before releasing the project lock. '.implode(' | ', array_map(fn ($pair) => $pair[0]->getOutput().' '.$pair[0]->getErrorOutput(), $children)));
            DB::commit();
            $outcomes = [];
            foreach ($children as [$child]) {
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                $outcomes[] = str_contains($child->getOutput(), 'revision=1') ? 'ok' : trim(substr($child->getOutput(), strpos($child->getOutput(), 'status=')));
            }
            sort($outcomes);
            $this->assertSame(['ok', 'status=409'], $outcomes);
            $this->scoped(fn () => $this->assertSame(1, DB::table('developer_reviews')->where('finding_id', $finding)->count()));
        } finally {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($children as [$child, $stream]) {
                $stream->close();
                if ($child->isRunning()) {
                    $child->stop(0);
                }
            }
        }
    }
}
