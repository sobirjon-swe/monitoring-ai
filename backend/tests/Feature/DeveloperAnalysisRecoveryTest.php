<?php

namespace Tests\Feature;

use App\Domain\DeveloperAnalysis\AnalysisProcessor;
use App\Domain\DeveloperAnalysis\AnalysisRequestService;
use App\Domain\DeveloperAnalysis\DeveloperContract;
use App\Domain\DeveloperAnalysis\InvalidParserOutput;
use App\Domain\DeveloperAnalysis\RunnerAvailability;
use App\Domain\DeveloperAnalysis\SyntheticMrRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\DeveloperAnalysisTestCase;
use Tests\Support\DeveloperProcessorFixtures;

class DeveloperAnalysisRecoveryTest extends DeveloperAnalysisTestCase
{
    use DeveloperProcessorFixtures;

    public function test_success_publishes_one_result_three_findings_and_leaves_source_outbox_and_e03_unchanged(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $before = $this->scoped(fn () => DB::table('lifecycle_project_state')->where('project_id', $project)->first());
        $this->runner(fn ($input) => $this->fixtureOutput($input));
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 20);
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 20);
        $this->scoped(function () use ($run, $project, $before): void {
            $row = DB::table('developer_runs')->where('id', $run['id'])->first();
            $this->assertSame('succeeded', $row->status);
            $this->assertSame(1, $row->attempt_count);
            $this->assertNull($row->lease_token);
            $this->assertSame(1, DB::table('developer_results')->where('run_id', $run['id'])->count());
            $this->assertSame(3, DB::table('developer_findings')->where('run_id', $run['id'])->count());
            $this->assertSame('completed', DB::table('developer_outbox')->where('run_id', $run['id'])->value('status'));
            $outbox = DB::table('developer_outbox')->where('run_id', $run['id'])->first();
            $proof = json_decode($outbox->processing_authority, true);
            $this->assertSame($this->user, $proof['actor_id']);
            $this->assertContains('analysis.process', $proof['purposes']);
            $this->assertSame($outbox->processing_authority_hash, hash('sha256', DeveloperContract::canonical($proof)));
            $this->assertSame($outbox->processing_authority_hash, DB::table('developer_results')->where('run_id', $run['id'])->value('processing_authority_hash'));
            $this->assertSame('pending', DB::table('developer_outbox')->whereNotNull('source_event_id')->value('status'));
            $this->assertEquals($before, DB::table('lifecycle_project_state')->where('project_id', $project)->first());
            $this->assertSame(0, DB::table('human_acceptance_decisions')->where('project_id', $project)->count());
        });
    }

    public function test_unavailable_runner_retries_five_attempts_then_dead_letters_without_results(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $this->runner(fn () => throw new HttpException(503, 'Sensitive stderr must never persist'));
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $before = $this->scoped(fn () => DB::scalar('select clock_timestamp()'));
            app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
            $this->scoped(function () use ($run, $attempt, $before): void {
                $outbox = DB::table('developer_outbox')->where('run_id', $run['id'])->first();
                $this->assertSame($attempt, $outbox->attempt_count);
                $this->assertSame($attempt === 5 ? 'dead_letter' : 'retry', $outbox->status);
                if ($attempt < 5) {
                    $delay = (float) DB::scalar('select extract(epoch from (?::timestamptz - ?::timestamptz))', [$outbox->available_at, $before]);
                    $this->assertGreaterThanOrEqual([5, 30, 120, 600][$attempt - 1], $delay);
                    DB::table('developer_outbox')->where('id', $outbox->id)->update(['available_at' => DB::raw("clock_timestamp() - interval '1 second'")]);
                }
            });
        }
        $this->scoped(function () use ($run): void {
            $this->assertSame('error', DB::table('developer_runs')->where('id', $run['id'])->value('status'));
            $this->assertSame('attempts_exhausted', DB::table('developer_runs')->where('id', $run['id'])->value('reason_code'));
            $this->assertSame(0, DB::table('developer_results')->where('run_id', $run['id'])->count());
        });
    }

    public function test_invalid_output_gets_one_rerun_and_then_safe_terminal_error(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $calls = 0;
        $this->runner(function () use (&$calls) {
            $calls++;
            throw new InvalidParserOutput;
        });
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->assertSame(2, $calls);
        $this->scoped(function () use ($run): void {
            $row = DB::table('developer_runs')->where('id', $run['id'])->first();
            $this->assertSame('error', $row->status);
            $this->assertSame('invalid_output', $row->reason_code);
            $this->assertSame(1, $row->attempt_count);
            $this->assertSame('dead_letter', DB::table('developer_outbox')->where('run_id', $run['id'])->value('status'));
        });
    }

    public function test_expired_compute_is_fenced_and_next_worker_reclaims_once(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $this->runner(function ($input) use ($run) {
            $this->scoped(function () use ($run): void {
                foreach (['developer_runs' => 'id', 'developer_outbox' => 'run_id'] as $table => $key) {
                    DB::table($table)->where($key, $run['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
                }
            });

            return $this->fixtureOutput($input);
        });
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->scoped(fn () => $this->assertSame(0, DB::table('developer_results')->count()));
        $this->runner(fn ($input) => $this->fixtureOutput($input));
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->scoped(function () use ($run): void {
            $this->assertSame(2, DB::table('developer_runs')->where('id', $run['id'])->value('attempt_count'));
            $this->assertSame(1, DB::table('developer_results')->where('run_id', $run['id'])->count());
        });
    }

    public function test_revoked_original_requester_during_compute_cannot_publish_even_after_replacement(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $this->runner(function ($input) use ($project) {
            $this->scoped(fn () => DB::table('access_grants')->where('purpose', 'analysis.request')->update(['revoked_at' => now()]));
            $this->grant('analysis.request', $project);

            return $this->fixtureOutput($input);
        });
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->scoped(function () use ($run): void {
            $this->assertSame('stale', DB::table('developer_runs')->where('id', $run['id'])->value('applicability'));
            $this->assertSame('cancelled', DB::table('developer_runs')->where('id', $run['id'])->value('status'));
            $this->assertSame(1, DB::table('developer_invalidations')->where('run_id', $run['id'])->count());
            $this->assertSame(0, DB::table('developer_results')->count());
        });
    }

    public function test_operator_without_source_permission_cannot_claim(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $this->scoped(fn () => DB::table('access_grants')->where('purpose', 'analysis.source.read')->update(['revoked_at' => now()]));
        try {
            app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
            $this->fail('Unauthorized operator claimed.');
        } catch (AccessDeniedHttpException) {
            $this->scoped(fn () => $this->assertSame(0, DB::table('developer_runs')->where('id', $run['id'])->value('attempt_count')));
        }
    }

    public function test_local_drain_command_requires_existing_bearer_subject_and_bounded_batch(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $path = tempnam(sys_get_temp_dir(), 'e04-token-');
        file_put_contents($path, $this->token);
        $this->runner(fn ($input) => $this->fixtureOutput($input));
        try {
            $this->artisan('app:drain-developer-analysis', ['project' => $project, '--token-file' => $path, '--limit' => '21'])->assertExitCode(1);
            $this->artisan('app:drain-developer-analysis', ['project' => $project, '--token-file' => $path, '--limit' => '1'])->assertExitCode(0);
        } finally {
            unlink($path);
        }
        $this->scoped(fn () => $this->assertSame('succeeded', DB::table('developer_runs')->where('id', $run['id'])->value('status')));
    }

    public function test_real_disabled_runner_retains_durable_retry_without_unsafe_fallback(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->scoped(function () use ($run): void {
            $this->assertSame('queued', DB::table('developer_runs')->where('id', $run['id'])->value('status'));
            $this->assertSame('runner_unavailable', DB::table('developer_runs')->where('id', $run['id'])->value('reason_code'));
            $this->assertSame(0, DB::table('developer_results')->count());
        });
    }

    public function test_forged_schema_valid_result_is_rerun_then_rejected_without_raw_payload(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $this->runner(function ($input) {
            $output = $this->fixtureOutput($input);
            $output['locators'][0]['path'] = 'app/injected.php';

            return $output;
        });
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->scoped(function () use ($run): void {
            $this->assertSame('invalid_output', DB::table('developer_runs')->where('id', $run['id'])->value('reason_code'));
            $this->assertSame(0, DB::table('developer_results')->count());
            $this->assertStringNotContainsString('injected', json_encode(DB::table('audit_events')->get()->all()));
        });
    }

    public function test_exact_patch_group_is_scoped_and_keeps_distinct_source_events_and_runs(): void
    {
        $project = $this->developerProject();
        $first = $this->queuedRun($project);
        $this->runner(fn ($input) => $this->fixtureOutput($input));
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $fixture = $this->mr();
        $fixture['mr_id'] = '43';
        $receipt = app(SyntheticMrRegistry::class)->register($this->actor(), $project, $fixture, 0);
        $this->app->instance(RunnerAvailability::class, new class extends RunnerAvailability
        {
            public function assertReady(): void {}
        });
        $second = app(AnalysisRequestService::class)->request($this->actor(), $project, ['snapshot_id' => $receipt['snapshot_id'], 'mode' => 'deterministic', 'criterion_version_ids' => [], 'task_version_ids' => []], 'group-second')['id'];
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $other = $this->project();
        foreach (['analysis.fixture.manage', 'analysis.request', 'analysis.read', 'analysis.source.read', 'analysis.process'] as $purpose) {
            $this->grant($purpose, $other);
        }
        $third = $this->queuedRun($other);
        app(AnalysisProcessor::class)->drain($this->actor(), $other, 1);
        $this->scoped(function () use ($first, $second, $third): void {
            $groups = [];
            foreach ([$first['id'], $second, $third['id']] as $id) {
                $facts = json_decode(DB::table('developer_findings')->where('run_id', $id)->where('kind', 'provenance_notice')->value('facts'), true);
                $groups[] = $facts['patch_group_id'];
                $this->assertSame($id, $facts['references']['run_id']);
                $this->assertSame($this->tenant, $facts['scope']['tenant_id']);
            }
            $this->assertNotEmpty($groups[0]);
            $this->assertSame($groups[0], $groups[1]);
            $this->assertNotSame($groups[0], $groups[2]);
            $this->assertSame(3, DB::table('developer_source_events')->count());
            $this->assertSame(3, DB::table('developer_results')->count());
        });
    }

    private function child(string $project, string $run, string $mode): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Support/developer-processor-child.php'), $this->tenant, $this->user, $project, $run, $mode], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'monitoring_ai_test', 'DB_URL' => ''], null, 15);
    }

    public function test_real_worker_exit_after_claim_and_after_compute_recovers_durable_proof(): void
    {
        $project = $this->developerProject();
        foreach (['crash_claim' => 41, 'crash_compute' => 42] as $mode => $exit) {
            $run = $this->queuedRun($project);
            if (DB::transactionLevel()) {
                DB::commit();
            } // Retain guarded synthetic immutable history.
            $child = $this->child($project, $run['id'], $mode);
            $child->run();
            $this->assertSame($exit, $child->getExitCode(), $child->getErrorOutput());
            $this->scoped(function () use ($run): void {
                $row = DB::table('developer_outbox')->where('run_id', $run['id'])->first();
                $this->assertSame('processing', $row->status);
                $this->assertSame($this->user, json_decode($row->processing_authority, true)['actor_id']);
                $this->assertSame(0, DB::table('developer_results')->where('run_id', $run['id'])->count());
                foreach (['developer_runs' => 'id', 'developer_outbox' => 'run_id'] as $table => $key) {
                    DB::table($table)->where($key, $run['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
                }
            });
            $this->runner(fn ($input) => $this->fixtureOutput($input));
            app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
            $this->scoped(function () use ($run): void {
                $this->assertSame(2, DB::table('developer_runs')->where('id', $run['id'])->value('attempt_count'));
                $this->assertSame(1, DB::table('developer_results')->where('run_id', $run['id'])->count());
            });
        }
    }

    public function test_overlapping_real_workers_publish_only_reclaimed_lease(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        DB::commit(); // Retain guarded synthetic fixture, never delete immutable history.
        $stream = new InputStream;
        $old = $this->child($project, $run['id'], 'hold');
        $old->setInput($stream);
        $old->start();
        try {
            $deadline = microtime(true) + 10;
            while (! str_contains($old->getOutput(), 'claimed') && $old->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString('claimed', $old->getOutput(), $old->getErrorOutput());
            $contender = $this->child($project, $run['id'], 'drain');
            $contender->run();
            $this->assertSame(0, $contender->getExitCode(), $contender->getErrorOutput());
            $this->assertStringContainsString('processed=0', $contender->getOutput());
            $this->scoped(function () use ($run): void {
                foreach (['developer_runs' => 'id', 'developer_outbox' => 'run_id'] as $table => $key) {
                    DB::table($table)->where($key, $run['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
                }
            });
            $contender = $this->child($project, $run['id'], 'drain');
            $contender->run();
            $this->assertSame(0, $contender->getExitCode(), $contender->getErrorOutput());
            $this->assertStringContainsString('processed=1', $contender->getOutput());
            $stream->write("finish\n");
            $stream->close();
            $old->wait();
            $this->assertSame(0, $old->getExitCode(), $old->getErrorOutput());
            $this->assertStringContainsString('lost_lease', $old->getOutput());
            $this->scoped(function () use ($run): void {
                $this->assertSame(1, DB::table('developer_results')->where('run_id', $run['id'])->count());
                $this->assertSame(3, DB::table('developer_findings')->where('run_id', $run['id'])->count());
                $this->assertSame(2, DB::table('developer_runs')->where('id', $run['id'])->value('attempt_count'));
            });
        } finally {
            $stream->close();
            if ($old->isRunning()) {
                $old->stop(0);
            }
        }
    }

    public function test_invalid_output_rerun_can_publish_only_validated_result_in_same_attempt(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $calls = 0;
        $this->runner(function ($input) use (&$calls) {
            if (++$calls === 1) {
                throw new InvalidParserOutput;
            }

            return $this->fixtureOutput($input);
        });
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->scoped(function () use ($run): void {
            $this->assertSame('succeeded', DB::table('developer_runs')->where('id', $run['id'])->value('status'));
            $this->assertSame(1, DB::table('developer_runs')->where('id', $run['id'])->value('attempt_count'));
            $this->assertSame(1, DB::table('developer_results')->where('run_id', $run['id'])->count());
        });
        $this->assertSame(2, $calls);
    }

    public function test_expired_fifth_claim_cannot_create_sixth_attempt(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $this->runner(function ($input) use ($run) {
            $this->scoped(function () use ($run): void {
                foreach (['developer_runs' => 'id', 'developer_outbox' => 'run_id'] as $table => $key) {
                    DB::table($table)->where($key, $run['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
                }
            });

            return $this->fixtureOutput($input);
        });
        for ($i = 0; $i < 6; $i++) {
            app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        }
        $this->scoped(function () use ($run): void {
            $this->assertSame(5, DB::table('developer_runs')->where('id', $run['id'])->value('attempt_count'));
            $this->assertSame('error', DB::table('developer_runs')->where('id', $run['id'])->value('status'));
            $this->assertSame('dead_letter', DB::table('developer_outbox')->where('run_id', $run['id'])->value('status'));
            $this->assertSame(0, DB::table('developer_results')->count());
        });
    }

    public function test_processing_grant_expiry_during_compute_uses_actual_clock(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $this->runner(function ($input) {
            $this->scoped(fn () => DB::table('access_grants')->where('purpose', 'analysis.process')->update(['valid_to' => DB::raw("clock_timestamp() + interval '0.05 second'")]));
            usleep(100000);

            return $this->fixtureOutput($input);
        });
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->scoped(function () use ($run): void {
            $this->assertSame('stale', DB::table('developer_runs')->where('id', $run['id'])->value('applicability'));
            $this->assertSame(0, DB::table('developer_results')->count());
        });
    }

    public function test_redacted_result_publishes_partial_without_patch_group_or_secret(): void
    {
        $project = $this->developerProject();
        $fixture = $this->mr();
        $fixture['redaction_policy'] = 'redact-lines-v1';
        $fixture['diff'] = str_replace('+new', '+password=synthetic-private-value', $fixture['diff']);
        $id = $this->queuedWithPins($project, ['criterion_version_ids' => [], 'task_version_ids' => []], $fixture);
        $this->runner(function ($input) {
            $output = $this->fixtureOutput($input);
            $output['coverage'] = $output['files'][0]['coverage'] = ['status' => 'partial', 'notices' => ['redacted']];
            $output['files'][0]['flags'] = ['redacted'];
            $output['patch'] = ['status' => 'unresolved', 'hash' => null];

            return $output;
        });
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->scoped(function () use ($id): void {
            $this->assertSame('partial', DB::table('developer_runs')->where('id', $id)->value('status'));
            $facts = json_decode(DB::table('developer_findings')->where('run_id', $id)->where('kind', 'provenance_notice')->value('facts'), true);
            $this->assertNull($facts['patch_group_id']);
            $this->assertStringNotContainsString('synthetic-private-value', json_encode(DB::table('developer_results')->get()->all()));
            $this->assertSame('completed', DB::table('developer_outbox')->where('run_id', $id)->value('status'));
        });
    }

    public function test_database_rejects_processing_proof_replacement_inside_same_lease(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $rejected = false;
        $this->runner(function ($input) use ($run, &$rejected) {
            try {
                $this->scoped(fn () => DB::table('developer_outbox')->where('run_id', $run['id'])->update(['processing_authority' => '{"forged":true}', 'processing_authority_hash' => str_repeat('a', 64)]));
                $this->fail('Claim authority was replaced.');
            } catch (QueryException $exception) {
                $this->assertSame('42501', $exception->errorInfo[0]);
                $rejected = true;
            }

            return $this->fixtureOutput($input);
        });
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->assertTrue($rejected);
        $this->scoped(fn () => $this->assertSame('succeeded', DB::table('developer_runs')->where('id', $run['id'])->value('status')));
    }

    public function test_distinct_operator_proof_is_pinned_and_replacement_cannot_publish(): void
    {
        $project = $this->developerProject();
        $requester = $this->user;
        $first = $this->queuedRun($project);
        $operator = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $this->scoped(function () use ($project, $operator, $employee): void {
            DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic operator']);
            DB::table('users')->insert(['id' => $operator, 'tenant_id' => $this->tenant, 'employee_id' => $employee, 'display_name' => 'Synthetic operator']);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $project, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
        });
        $this->user = $operator;
        $this->grant('analysis.process', $project);
        $this->grant('analysis.source.read', $project);
        $this->runner(fn ($input) => $this->fixtureOutput($input));
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->scoped(function () use ($first, $operator): void {
            $proof = json_decode(DB::table('developer_results')->where('run_id', $first['id'])->value('processing_authority'), true);
            $this->assertSame($operator, $proof['actor_id']);
            $this->assertSame(['analysis.process', 'analysis.source.read'], $proof['purposes']);
        });
        $this->user = $requester;
        $second = $this->queuedRun($project);
        $this->user = $operator;
        $this->runner(function ($input) use ($project) {
            $this->scoped(fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'analysis.process')->update(['revoked_at' => now()]));
            $this->grant('analysis.process', $project);

            return $this->fixtureOutput($input);
        });
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->scoped(function () use ($second): void {
            $this->assertSame('stale', DB::table('developer_runs')->where('id', $second['id'])->value('applicability'));
            $this->assertSame(0, DB::table('developer_results')->where('run_id', $second['id'])->count());
        });
    }
}
