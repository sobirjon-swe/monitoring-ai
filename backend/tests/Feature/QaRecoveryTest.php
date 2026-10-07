<?php

namespace Tests\Feature;

use App\Domain\Qa\QaContract;
use App\Domain\Qa\QaInvalidation;
use App\Domain\Qa\QaMaintenanceProcessor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\QaTestCase;

class QaRecoveryTest extends QaTestCase
{
    public function test_empty_scoped_sequence_range_advances_to_reserved_target(): void
    {
        $this->qaInputs();
        $this->completeSyntheticQaJobs();
        $target = $this->qaRun(fn () => (int) DB::table('qa_changes')->max('change_seq') + 1);
        $this->qaRun(fn () => app(QaInvalidation::class)->reserveLocked(['tenant_id' => $this->tenant, 'project_id' => $this->p], $target));
        $processor = app(QaMaintenanceProcessor::class);
        for ($n = 0; $n < 3; $n++) {
            $claim = $processor->claim($this->actor(), $this->p);
            if ($claim === null) {
                break;
            } $processor->processClaim($this->actor(), $this->p, $claim);
        }
        $this->assertSame($target, $this->qaRun(fn () => (int) DB::table('qa_project_state')->where('project_id', $this->p)->value('maintenance_watermark')));
        $this->assertNull($processor->claim($this->actor(), $this->p));
    }

    public function test_older_observation_target_never_regresses_processed_cursor(): void
    {
        $this->qaInputs();
        $this->qaBuild();
        $this->completeSyntheticQaJobs();
        $target = $this->qaRun(fn () => (int) DB::table('qa_changes')->where('project_id', $this->p)->max('change_seq'));
        $this->qaRun(function () use ($target) {
            DB::table('qa_project_state')->where('project_id', $this->p)->update(['maintenance_watermark' => $target]);
            app(QaInvalidation::class)->reserveLocked(['tenant_id' => $this->tenant, 'project_id' => $this->p], $target - 1);
        });
        $processor = app(QaMaintenanceProcessor::class);
        $claim = $processor->claim($this->actor(), $this->p);
        $this->assertSame('completed', $processor->processClaim($this->actor(), $this->p, $claim)['status']);
        $this->assertSame($target, $this->qaRun(fn () => (int) DB::table('qa_project_state')->where('project_id', $this->p)->value('maintenance_watermark')));
        $this->assertNull($processor->claim($this->actor(), $this->p));
    }

    public function test_more_than_five_successful_pages_and_arriving_target_are_durable(): void
    {
        $this->qaInputs();
        foreach (range(1, 102) as $n) {
            if ($n % 40 === 0) {
                $this->completeSyntheticQaJobs();
            } $this->qaBuild($n, $n - 1);
        }
        $this->completeSyntheticQaJobs();
        $target = $this->qaRun(fn () => (int) DB::table('qa_changes')->where('project_id', $this->p)->max('change_seq'));
        $this->qaRun(function () use ($target) {
            DB::table('qa_project_state')->where('project_id', $this->p)->update(['maintenance_watermark' => $target - 1]);
            app(QaInvalidation::class)->reserveLocked(['tenant_id' => $this->tenant, 'project_id' => $this->p], $target);
        });
        $processor = app(QaMaintenanceProcessor::class);
        $claim = $processor->claim($this->actor(), $this->p);
        $this->qaEnvironment();
        $newTarget = $this->qaRun(fn () => (int) DB::table('qa_changes')->where('project_id', $this->p)->max('change_seq'));
        $this->qaRun(fn () => app(QaInvalidation::class)->reserveLocked(['tenant_id' => $this->tenant, 'project_id' => $this->p], $newTarget));
        $pages = 0;
        do {
            $result = $processor->processClaim($this->actor(), $this->p, $claim);
            $this->assertSame('completed', $result['status']);
            $this->assertLessThanOrEqual(20, $result['processed']);
            $pages++;
            $claim = $processor->claim($this->actor(), $this->p);
        } while ($claim !== null && $pages < 15);
        $this->assertNull($claim);
        $this->assertGreaterThan(5, $pages);
        $this->assertSame($newTarget, $this->qaRun(fn () => (int) DB::table('qa_project_state')->where('project_id', $this->p)->value('maintenance_watermark')));
        $this->assertSame(101, $this->qaRun(fn () => DB::table('qa_applicability')->where('project_id', $this->p)->where('reason_code', 'source_version_changed')->count()));
        $this->assertSame(0, $this->qaRun(fn () => DB::table('qa_outbox')->where('project_id', $this->p)->where('status', 'dead_letter')->count()));
    }

    public function test_child_crash_reclaim_fences_old_worker(): void
    {
        $this->qaInputs();
        $this->assertTrue(is_file(base_path('tests/Support/qa-maintenance-child.php')), 'Recovery requires a real guarded child process.');
        DB::commit();
        $children = [];
        try {
            [$old,$input,$claim,$oldPid] = $this->maintenanceChild('pause');
            $children[] = [$old, $input];
            $this->assertSame('processing', $this->job($claim['id'])->status);
            $this->expire($claim['id']);
            $expired = $this->childResult('claim');
            $this->assertNull($expired['claim']);
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_outbox')->where('id', $claim['id'])->update(['available_at' => DB::raw("clock_timestamp()-interval '1 second'")]));
            $fresh = $this->childResult('publish');
            $this->assertSame($claim['id'], $fresh['claim']['id']);
            $this->assertNotSame($claim['lease_token'], $fresh['claim']['lease_token']);
            $this->assertNotSame($oldPid, $fresh['pid']);
            $this->assertSame('completed', $fresh['result']['status']);
            $before = $this->job($claim['id']);
            $input->write("publish\n");
            $input->close();
            $old->wait();
            $this->assertSame(0, $old->getExitCode(), $old->getErrorOutput());
            $oldResult = $this->childJson($old->getOutput(), 'result=');
            $this->assertSame('fenced', $oldResult['status']);
            $this->assertEquals($before, $this->job($claim['id']));
            // A separate child exits after committed claim, with no publication.
            $this->qaBuild();
            $crash = $this->childResult('crash', 44);
            $this->assertSame('processing', $this->job($crash['claim']['id'])->status);
            $this->expire($crash['claim']['id']);
            $this->childResult('claim');
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_outbox')->where('id', $crash['claim']['id'])->update(['available_at' => DB::raw("clock_timestamp()-interval '1 second'")]));
            $recovered = $this->childResult('publish');
            $this->assertSame($crash['claim']['id'], $recovered['claim']['id']);
            $this->assertSame('completed', $recovered['result']['status']);
            file_put_contents(base_path('../.superpowers/sdd/2026-10-06-e05-qa-handoff-plan/task-5-retained-fixtures.jsonl'), json_encode(['project_id' => $this->p, 'tenant_id' => $this->tenant, 'old_pid' => $oldPid, 'new_pid' => $fresh['pid'], 'crash_pid' => $crash['pid'], 'claim_id' => $claim['id']], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        } finally {
            foreach ($children as [$child,$input]) {
                $input->close();
                if ($child->isRunning()) {
                    $child->stop(0);
                }
            }
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            DB::beginTransaction();
        }
    }

    public function test_durable_claim_and_exact_retry_delays(): void
    {
        $this->qaInputs();
        $this->qaBuild();
        $this->assertTrue(class_exists(QaMaintenanceProcessor::class), 'Durable fenced maintenance is required.');
        $processor = app(QaMaintenanceProcessor::class);
        $claim = $processor->claim($this->actor(), $this->p);
        $this->assertNotNull($claim);
        $row = $this->job($claim['id']);
        $this->assertSame('processing', $row->status);
        $this->assertSame(1, $row->attempt_count);
        $this->assertSame($claim['lease_token'], $row->lease_token);
        $this->assertSame(hash('sha256', QaContract::canonical(json_decode($row->processing_authority, true))), $row->processing_authority_hash);
        foreach ([5, 30, 120, 600] as $number => $delay) {
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_outbox')->where('id', $claim['id'])->update(['lease_until' => DB::raw("clock_timestamp()-interval '1 second'")]));
            $processor->claim($this->actor(), $this->p);
            $row = $this->job($claim['id']);
            $actual = app(TenantContext::class)->run($this->tenant, fn () => (float) DB::scalar('select extract(epoch from available_at-clock_timestamp()) from qa_outbox where id=?', [$claim['id']]));
            $this->assertSame('retry', $row->status);
            $this->assertGreaterThan($delay - 2, $actual);
            $this->assertLessThanOrEqual($delay, $actual);
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_outbox')->where('id', $claim['id'])->update(['available_at' => DB::raw("clock_timestamp()-interval '1 second'")]));
            $claim = $processor->claim($this->actor(), $this->p);
            $this->assertSame($number + 2, $claim['attempt_count']);
        }
    }

    public function test_fifth_expired_claim_no_sixth(): void
    {
        $this->qaInputs();
        $this->assertTrue(class_exists(QaMaintenanceProcessor::class), 'Five-claim durable recovery is required.');
        $processor = app(QaMaintenanceProcessor::class);
        $claim = $processor->claim($this->actor(), $this->p);
        foreach (range(1, 4) as $number) {
            $this->expire($claim['id']);
            $processor->claim($this->actor(), $this->p);
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_outbox')->where('id', $claim['id'])->update(['available_at' => DB::raw("clock_timestamp()-interval '1 second'")]));
            $claim = $processor->claim($this->actor(), $this->p);
        }
        $this->expire($claim['id']);
        $this->assertNull($processor->claim($this->actor(), $this->p));
        $this->assertSame(5, $this->job($claim['id'])->attempt_count);
        $this->assertSame('dead_letter', $this->job($claim['id'])->status);
        $this->assertSame('fenced', $processor->processClaim($this->actor(), $this->p, $claim)['status']);
    }

    private function expire(string $id): void
    {
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_outbox')->where('id', $id)->update(['lease_until' => DB::raw("clock_timestamp()-interval '1 second'")]));
    }

    private function job(string $id): object
    {
        return app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_outbox')->find($id));
    }

    private function maintenanceChild(string $mode): array
    {
        $input = new InputStream;
        $child = new Process([PHP_BINARY, base_path('tests/Support/qa-maintenance-child.php'), $this->tenant, $this->user, $this->p, $mode], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'monitoring_ai_test', 'DB_URL' => ''], null, 20);
        $child->setInput($input);
        $child->start();
        $deadline = microtime(true) + 10;
        while (! str_contains($child->getOutput(), 'claim=') && $child->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertStringContainsString('claim=', $child->getOutput(), $child->getErrorOutput());
        preg_match('/pid=([0-9]+)/', $child->getOutput(), $match);

        return [$child, $input, $this->childJson($child->getOutput(), 'claim='), (int) $match[1]];
    }

    private function childResult(string $mode, int $exit = 0): array
    {
        [$child,$input,$claim,$pid] = $this->maintenanceChild($mode);
        $input->close();
        $child->wait();
        $this->assertSame($exit, $child->getExitCode(), $child->getErrorOutput());

        return ['pid' => $pid, 'claim' => $claim, 'result' => str_contains($child->getOutput(), 'result=') ? $this->childJson($child->getOutput(), 'result=') : null];
    }

    private function childJson(string $output, string $prefix): mixed
    {
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, $prefix)) {
                return json_decode(substr($line, strlen($prefix)), true, 16, JSON_THROW_ON_ERROR);
            }
        }
        throw new \LogicException('Missing fixed child protocol.');
    }
}
