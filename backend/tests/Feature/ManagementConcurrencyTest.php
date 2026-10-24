<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementActionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ManagementTestCase;

class ManagementConcurrencyTest extends ManagementTestCase
{
    public function test_deferred_direct_write_checked_after_coordination_closes_fails_closed(): void
    {
        $f = $this->workflowAssigned();
        $envelope = $this->managementEnvelope('support.record', $this->workflowSupportBody($f), 'direct-deferred-misuse');
        $inventory = base_path('../.superpowers/sdd/2026-10-09-e05-management-support-plan/task-3-retained-fixtures.jsonl');
        file_put_contents($inventory, json_encode(['tenant_id' => $this->tenant, 'project_id' => $this->p, 'mode' => 'series-deferred-misuse', 'phase' => 'setup'], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        DB::commit();
        DB::beginTransaction();
        $child = new Process([PHP_BINARY, base_path('tests/Support/management-cas-child.php'), $this->tenant, $f['helper']->id, $this->p, $f['open']['action_id'], json_encode($envelope, JSON_THROW_ON_ERROR), 'direct-deferred-misuse', 'series-deferred-misuse'], base_path(), ['APP_ENV' => 'testing'], null, 25);
        $child->setInput("go\n");
        try {
            $child->run();
            $this->assertSame(0, $child->getExitCode(), $child->getOutput());
            $this->assertStringContainsString('status=403 reason=coordination_required sqlstate=42501', $child->getOutput());
            app(TenantContext::class)->run($this->tenant, function () {
                $this->assertSame(0, DB::table('management_support_versions')->count());
                foreach (['management_receipts', 'management_events', 'management_request_bindings'] as $table) {
                    $this->assertSame(2, DB::table($table)->count());
                }
                $this->assertSame(2, DB::table('management_project_state')->value('generation'));
                $this->assertFalse(DB::table('management_source_heads')->where('object_id', 'direct-deferred-misuse')->exists());
            });
        } finally {
            if ($child->isRunning()) {
                $child->stop(0);
            }
            file_put_contents($inventory, json_encode(['tenant_id' => $this->tenant, 'project_id' => $this->p, 'mode' => 'series-deferred-misuse', 'phase' => 'result', 'outcomes' => [403]], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    public function test_direct_two_process_series_bindings_have_one_commit_and_one_specific_guard_denial(): void
    {
        $f = $this->workflowAssigned();
        $open = app(ManagementActionService::class)->open($this->actor(), $this->p, $this->managementEnvelope('action.open', $this->actionInput(), 'direct-second-root'), 'direct-second-root', 0);
        $helperId = app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $f['helper']->id)->value('employee_id'));
        $assignment = app(ManagementActionService::class)->event($f['head'], $this->p, $open['action_id'], ['operation' => 'assignment.set', 'expected_revision' => 1, 'owner_id' => $this->employee, 'helper_id' => $helperId, 'department_id' => $f['department'], 'reason' => 'Synthetic second root assignment'], 'direct-second-assignment');
        $fixtures = [$f, array_replace($f, ['open' => $open, 'assignment' => $assignment])];
        $envelopes = [];
        foreach ($fixtures as $i => $fixture) {
            $envelopes[] = $this->managementEnvelope('support.record', $this->workflowSupportBody($fixture), 'direct-series-race', $i + 1);
        }
        DB::commit();
        DB::beginTransaction();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('projects')->where('id', $this->p)->lockForUpdate()->first());
        $children = $pids = $outcomes = [];
        $inventory = base_path('../.superpowers/sdd/2026-10-09-e05-management-support-plan/task-3-retained-fixtures.jsonl');
        file_put_contents($inventory, json_encode(['tenant_id' => $this->tenant, 'project_id' => $this->p, 'mode' => 'series-binding', 'phase' => 'setup'], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        try {
            foreach ($fixtures as $i => $fixture) {
                $input = new InputStream;
                $child = new Process([PHP_BINARY, base_path('tests/Support/management-cas-child.php'), $this->tenant, $f['helper']->id, $this->p, $fixture['open']['action_id'], json_encode($envelopes[$i], JSON_THROW_ON_ERROR), 'direct-series-'.$i, 'series-binding'], base_path(), ['APP_ENV' => 'testing'], null, 25);
                $child->setInput($input);
                $child->start();
                $children[] = [$child, $input];
            }
            foreach ($children as [$child, $input]) {
                $deadline = microtime(true) + 8;
                while (! str_contains($child->getOutput(), 'ready') && $child->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready', $child->getOutput());
                preg_match('/ready pid=([0-9]+)/', $child->getOutput(), $match);
                $pids[] = (int) $match[1];
                $input->write("go\n");
                $input->close();
            }
            $this->assertCount(2, array_unique($pids));
            $deadline = microtime(true) + 8;
            do {
                foreach ($children as [$child]) {
                    $child->isRunning();
                }
                DB::select('select pg_stat_clear_snapshot()');
                $blocked = (int) DB::scalar("select count(*) from pg_stat_activity where datname=current_database() and usename=current_user and pid in (?,?) and wait_event_type='Lock'", $pids);
                if ($blocked === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, implode(' | ', array_map(fn ($pair) => $pair[0]->getOutput(), $children)));
            DB::commit();
            $specific = 0;
            foreach ($children as [$child]) {
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getOutput());
                preg_match('/status=([0-9]+)/', $child->getOutput(), $match);
                $outcomes[] = (int) $match[1];
                $specific += (int) str_contains($child->getOutput(), 'reason=series_binding sqlstate=23503');
            }
            sort($outcomes);
            $this->assertSame([201, 409], $outcomes);
            $this->assertSame(1, $specific);
            app(TenantContext::class)->run($this->tenant, function () {
                $this->assertSame(1, DB::table('management_support_versions')->count());
                foreach (['management_receipts', 'management_events', 'management_request_bindings'] as $table) {
                    $this->assertSame(5, DB::table($table)->count());
                }
                $this->assertSame(5, DB::table('management_project_state')->value('generation'));
                $head = DB::table('management_source_heads')->where('object_id', 'direct-series-race')->first();
                $this->assertContains($head->source_revision, [1, 2]);
                $this->assertSame(1, $head->revision);
                $this->assertSame($head->receipt_id, DB::table('management_support_versions')->value('receipt_id'));
            });
        } finally {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($children as [$child, $input]) {
                $input->close();
                if ($child->isRunning()) {
                    $child->stop(0);
                }
            }
            file_put_contents($inventory, json_encode(['tenant_id' => $this->tenant, 'project_id' => $this->p, 'mode' => 'series-binding', 'phase' => 'result', 'pg_pids' => $pids, 'outcomes' => $outcomes], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
            DB::beginTransaction();
        }
    }

    public static function races(): array
    {
        return [['cas', [201, 409]], ['retry', [200, 201]], ['hash', [201, 409]], ['source-cas', [201, 409]], ['source-retry', [200, 201]], ['source-hash', [201, 409]]];
    }

    #[DataProvider('races')]
    public function test_two_process_cas_retry_and_hash_races_are_atomic(string $mode, array $expected): void
    {
        $source = str_starts_with($mode, 'source-');
        $kind = $source ? substr($mode, 7) : $mode;
        if ($source) {
            $fixture = $this->workflowAssigned();
            $open = $fixture['open'];
            $actor = $fixture['helper'];
            $sourceEnvelope = $this->managementEnvelope('support.record', $this->workflowSupportBody($fixture), 'race-support');
        } else {
            $this->managementInputs();
            $open = app(ManagementActionService::class)->open($this->actor(), $this->p, $this->managementEnvelope('action.open', $this->actionInput()), 'open', 0);
            $actor = $this->actor();
        }
        DB::commit();
        DB::beginTransaction();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('projects')->where('id', $this->p)->lockForUpdate()->first());
        $children = [];
        $pids = [];
        $outcomes = [];
        // Retain and inventory the synthetic committed scope even on child failure.
        $inventory = base_path('../.superpowers/sdd/2026-10-09-e05-management-support-plan/task-3-retained-fixtures.jsonl');
        file_put_contents($inventory, json_encode(['tenant_id' => $this->tenant, 'project_id' => $this->p, 'mode' => $mode, 'phase' => 'setup'], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        try {
            for ($i = 0; $i < 2; $i++) {
                $body = ['operation' => 'context.add', 'expected_revision' => 1, 'context' => 'Synthetic race '.($kind === 'retry' ? 0 : $i)];
                if ($source) {
                    $body = $sourceEnvelope;
                    $body['body']['text'] = 'Synthetic race '.($kind === 'retry' ? 0 : $i);
                }
                $input = new InputStream;
                $child = new Process([PHP_BINARY, base_path('tests/Support/management-cas-child.php'), $this->tenant, $actor->id, $this->p, $open['action_id'], json_encode($body, JSON_THROW_ON_ERROR), $kind === 'cas' ? 'race-'.$i : 'race'], base_path(), ['APP_ENV' => 'testing'], null, 25);
                $child->setInput($input);
                $child->start();
                $children[] = [$child, $input];
            }
            foreach ($children as [$child, $input]) {
                $deadline = microtime(true) + 8;
                while (! str_contains($child->getOutput(), 'ready') && $child->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready', $child->getOutput(), $child->getErrorOutput());
                preg_match('/ready pid=([0-9]+)/', $child->getOutput(), $match);
                $pids[] = (int) $match[1];
                $input->write("go\n");
                $input->close();
            }
            $this->assertCount(2, array_unique($pids));
            $deadline = microtime(true) + 8;
            do {
                foreach ($children as [$child]) {
                    $child->isRunning();
                }
                DB::select('select pg_stat_clear_snapshot()');
                $blocked = (int) DB::scalar("select count(*) from pg_stat_activity where datname=current_database() and usename=current_user and pid in (?,?) and wait_event_type='Lock'", $pids);
                if ($blocked === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked);
            DB::commit();
            foreach ($children as [$child]) {
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                preg_match('/status=([0-9]+)/', $child->getOutput(), $match);
                $outcomes[] = (int) ($match[1] ?? 0);
            }
            sort($outcomes);
            $this->assertSame($expected, $outcomes);
            app(TenantContext::class)->run($this->tenant, function () use ($source) {
                $count = $source ? 3 : 2;
                $this->assertSame($count, DB::table('management_receipts')->count());
                $this->assertSame($count, DB::table('management_events')->count());
                $this->assertSame($count, DB::table('management_request_bindings')->count());
                $this->assertSame($count, DB::table('management_project_state')->value('generation'));
                $this->assertSame($source ? 1 : 0, DB::table('management_support_versions')->count());
            });
        } finally {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($children as [$child, $input]) {
                $input->close();
                if ($child->isRunning()) {
                    $child->stop(0);
                }
            }
            file_put_contents($inventory, json_encode(['tenant_id' => $this->tenant, 'project_id' => $this->p, 'mode' => $mode, 'phase' => 'result', 'pg_pids' => $pids, 'outcomes' => $outcomes], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
            DB::beginTransaction();
        }
    }
}
