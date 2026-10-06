<?php

namespace Tests\Feature;

use App\Domain\Access\TrustedActor;
use App\Domain\Ingestion\IngestionService;
use App\Domain\Ingestion\OutboxProcessor;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AccessTestCase;

class OutboxRecoveryTest extends AccessTestCase
{
    private string $connector;

    private string $projectId;

    private string $acceptGrant;

    private string $processGrant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->projectId = $this->project();
        $this->connector = (string) Str::uuid();
        $this->scope(function (): void {
            $source = (string) Str::uuid();
            DB::table('source_instances')->insert(['id' => $source, 'tenant_id' => $this->tenant, 'kind' => 'manual', 'label' => 'Synthetic recovery']);
            DB::table('ingestion_connectors')->insert(['id' => $this->connector, 'tenant_id' => $this->tenant, 'project_id' => $this->projectId, 'source_instance_id' => $source, 'status' => 'active']);
        });
        $this->acceptGrant = $this->grant('ingestion.accept', $this->projectId);
        $this->processGrant = $this->grant('ingestion.process', $this->projectId);
    }

    private function scope(\Closure $work): mixed
    {
        return app(TenantContext::class)->run($this->tenant, $work);
    }

    private function actor(): TrustedActor
    {
        return new TrustedActor($this->user, $this->tenant);
    }

    private function accept(string $version = 'v1', ?string $time = '2026-10-05T07:00:00Z', string $title = 'Synthetic recovery'): string
    {
        $e = require __DIR__.'/../Fixtures/ingestion-envelope.php';
        $e['source_version'] = $version;
        $e['source_updated_at'] = $time;
        $e['payload']['title'] = $title;

        return app(IngestionService::class)->accept($this->actor(), $this->connector, $e)['receipt_id'];
    }

    private function row(): object
    {
        return $this->scope(fn () => DB::table('ingestion_outbox')->first());
    }

    private function processor(): OutboxProcessor
    {
        return app(OutboxProcessor::class);
    }

    public function test_acceptance_survives_worker_absence_and_replay_does_not_duplicate_projection(): void
    {
        $receipt = $this->accept();
        $this->assertSame('pending', $this->row()->status);
        $this->assertSame(1, $this->processor()->drain($this->actor(), $this->connector)['completed']);
        $this->assertSame('completed', $this->row()->status);
        $this->assertSame(1, $this->row()->attempt_count);
        $this->assertNull($this->row()->lease_token);
        $this->assertSame($receipt, $this->accept());
        $this->assertSame(0, $this->processor()->drain($this->actor(), $this->connector)['claimed']);
        $this->scope(fn () => $this->assertSame(1, DB::table('ingestion_projections')->count()));
    }

    public function test_expired_claim_is_recovered_and_stale_token_cannot_complete(): void
    {
        $this->accept();
        $first = $this->processor()->claimNext($this->actor(), $this->connector);
        $this->assertNull($this->processor()->claimNext($this->actor(), $this->connector));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $second = $this->processor()->claimNext($this->actor(), $this->connector);
        $this->assertNotSame($first->lease_token, $second->lease_token);
        $this->assertSame('stale', $this->processor()->deliver($this->actor(), $this->connector, $first->id, $first->lease_token));
        $this->assertSame('completed', $this->processor()->deliver($this->actor(), $this->connector, $second->id, $second->lease_token));
        $this->assertSame(2, $this->row()->attempt_count);
    }

    public function test_projection_completion_rollback_and_fifth_failure_dead_letters(): void
    {
        $this->accept();
        DB::listen(function ($q): void {
            if (str_contains($q->sql, 'insert into "ingestion_projections"')) {
                throw new \RuntimeException('SECRET synthetic failure');
            }
        });
        $clock = CarbonImmutable::now();
        foreach ([5, 30, 120, 600, null] as $index => $delay) {
            CarbonImmutable::setTestNow($clock);
            $result = $this->processor()->drain($this->actor(), $this->connector);
            $row = $this->row();
            $this->assertSame($index + 1, $row->attempt_count);
            $this->assertSame('processing_failed', $row->reason_code);
            $this->assertSame($delay === null ? 'dead_letter' : 'retry', $row->status);
            $this->assertSame(1, $result[$delay === null ? 'dead_letter' : 'retry']);
            $this->scope(fn () => $this->assertSame(0, DB::table('ingestion_projections')->count()));
            if ($delay !== null) {
                $this->assertTrue(CarbonImmutable::parse($row->available_at)->equalTo($clock->addSeconds($delay)));
                $this->assertSame(0, $this->processor()->drain($this->actor(), $this->connector)['claimed']);
                $clock = $clock->addSeconds($delay);
            }
        }
    }

    public function test_original_submitter_revocation_blocks_independent_operator(): void
    {
        $this->accept();
        $operator = (string) Str::uuid();
        $this->scope(function () use ($operator): void {
            DB::table('users')->insert(['id' => $operator, 'tenant_id' => $this->tenant, 'employee_id' => $this->employee, 'display_name' => 'Synthetic operator']);
            DB::table('access_grants')->where('id', $this->processGrant)->update(['user_id' => $operator]);
            DB::table('access_grants')->where('id', $this->acceptGrant)->update(['revoked_at' => now()]);
        });
        $this->assertSame(1, $this->processor()->drain(new TrustedActor($operator, $this->tenant), $this->connector)['blocked']);
        $this->assertSame('access_revoked', $this->row()->reason_code);
        $this->scope(fn () => $this->assertSame(0, DB::table('ingestion_projections')->count()));
    }

    public function test_operator_revocation_and_disabled_connector_after_claim_block(): void
    {
        $this->accept();
        $claim = $this->processor()->claimNext($this->actor(), $this->connector);
        $this->scope(fn () => DB::table('access_grants')->where('id', $this->processGrant)->update(['revoked_at' => now()]));
        $this->assertSame('blocked', $this->processor()->deliver($this->actor(), $this->connector, $claim->id, $claim->lease_token));
        $this->assertSame('access_revoked', $this->row()->reason_code);
        $this->scope(fn () => DB::table('access_grants')->where('id', $this->processGrant)->update(['revoked_at' => null]));
        $this->accept('v2');
        $claim = $this->processor()->claimNext($this->actor(), $this->connector);
        $this->scope(fn () => DB::table('ingestion_connectors')->where('id', $this->connector)->update(['status' => 'disabled', 'revision' => 2]));
        $this->assertSame('blocked', $this->processor()->deliver($this->actor(), $this->connector, $claim->id, $claim->lease_token));
        $this->scope(fn () => $this->assertSame(0, DB::table('ingestion_projections')->count()));
    }

    public function test_completion_rechecks_submitter_and_rolls_back_projection(): void
    {
        $this->accept();
        $injected = false;
        DB::listen(function ($q) use (&$injected): void {
            if (! $injected && str_contains($q->sql, 'insert into "ingestion_projections"')) {
                $injected = true;
                DB::table('access_grants')->where('id', $this->acceptGrant)->update(['revoked_at' => now()]);
            }
        });
        $this->assertSame(1, $this->processor()->drain($this->actor(), $this->connector)['blocked']);
        $this->scope(fn () => $this->assertSame(0, DB::table('ingestion_projections')->count()));
    }

    public function test_partial_coverage_non_enum_reasons_complete_without_retry(): void
    {
        $this->accept();
        $this->accept('v2', null, 'Unordered');
        $this->accept('v3', '2026-10-05T07:00:01Z', 'Newer');
        $this->assertSame(3, $this->processor()->drain($this->actor(), $this->connector)['completed']);
        $this->scope(function (): void {
            $this->assertSame(3, DB::table('ingestion_outbox')->where('status', 'completed')->count());
            $this->assertContains('ordering_unknown', json_decode(DB::table('ingestion_projections')->value('coverage_flags'), true));
            $this->assertSame(0, DB::table('ingestion_outbox')->where('reason_code', 'processing_failed')->count());
        });
    }

    public function test_foreign_tenant_and_operator_membership_are_denied(): void
    {
        $this->accept();
        foreach ([new TrustedActor($this->user, (string) Str::uuid()), new TrustedActor((string) Str::uuid(), $this->tenant)] as $actor) {
            try {
                $this->processor()->drain($actor, $this->connector);
                $this->fail('Unauthorized drain');
            } catch (HttpException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->scope(fn () => DB::table('project_memberships')->where('project_id', $this->projectId)->update(['valid_to' => now()]));
        $this->expectException(AccessDeniedHttpException::class);
        $this->processor()->drain($this->actor(), $this->connector);
    }

    public function test_cli_limits_environment_database_guards(): void
    {
        $this->accept();
        $args = ['tenant' => $this->tenant, 'actor' => $this->user, 'connector' => $this->connector];
        $this->artisan('app:ingestion-drain', $args + ['--limit' => '101'])->assertFailed();
        $this->artisan('app:ingestion-drain', $args + ['--limit' => '1x'])->assertFailed();
        $this->artisan('app:ingestion-drain', $args)->expectsOutputToContain('completed=1')->assertSuccessful();
        config(['database.connections.pgsql.host' => 'remote.invalid']);
        $this->artisan('app:ingestion-drain', $args)->assertFailed();
        config(['database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.url' => 'postgres://override']);
        $this->artisan('app:ingestion-drain', $args)->assertFailed();
        config(['database.connections.pgsql.url' => null]);
        $this->app->instance('env', 'production');
        $this->artisan('app:ingestion-drain', $args)->assertFailed();
    }

    public function test_two_real_sessions_share_one_committed_row_and_fence_reclaimed_lease(): void
    {
        $marker = DB::scalar("select shobj_description(oid,'pg_database') from pg_database where datname=current_database()");
        $this->assertStringStartsWith('monitoring-ai-local-v1:', $marker);
        $receipt = $this->accept();
        DB::commit(); // Guarded test-only fixture retained: one immutable receipt per run.
        $primary = DB::connection('pgsql');
        $pdo = $primary->getPdo();
        config(['database.connections.outbox_contender' => config('database.connections.pgsql')]);
        $other = DB::connection('outbox_contender');
        $this->assertNotSame($pdo, $other->getPdo());
        $this->assertSame('monitoring_ai_test', $other->scalar('select current_database()'));
        $this->assertSame('monitoring_ai_app', $other->scalar('select current_user'));
        $other->beginTransaction();
        try {
            $other->select("select set_config('app.tenant_id',?,true)", [$this->tenant]);
            $visible = $other->table('ingestion_outbox')->where('receipt_id', $receipt)->lockForUpdate()->first();
            $this->assertNotNull($visible);
            $this->assertNull($this->processor()->claimNext($this->actor(), $this->connector));
        } finally {
            $other->rollBack();
        }
        try {
            $first = $this->processor()->claimNext($this->actor(), $this->connector);
            $primary->setPdo($other->getPdo());
            $visible = $this->scope(fn () => DB::table('ingestion_outbox')->where('id', $first->id)->first());
            $this->assertSame($first->lease_token, $visible->lease_token);
            $this->assertNull($this->processor()->claimNext($this->actor(), $this->connector));
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
            $second = $this->processor()->claimNext($this->actor(), $this->connector);
            $this->assertNotSame($first->lease_token, $second->lease_token);
            $primary->setPdo($pdo);
            $this->assertSame('stale', $this->processor()->deliver($this->actor(), $this->connector, $first->id, $first->lease_token));
            $this->assertSame('completed', $this->processor()->deliver($this->actor(), $this->connector, $second->id, $second->lease_token));
            $primary->setPdo($other->getPdo());
            $this->scope(function () use ($receipt): void {
                $this->assertSame(1, DB::table('ingestion_projections')->count());
                $this->assertSame($receipt, DB::table('ingestion_projections')->value('current_receipt_id'));
                $this->assertSame('completed', DB::table('ingestion_outbox')->value('status'));
            });
            $this->assertSame($receipt, $this->accept());
            $this->assertSame(0, $this->processor()->drain($this->actor(), $this->connector)['claimed']);
        } finally {
            $primary->setPdo($pdo);
            DB::purge('outbox_contender');
        }
    }

    public function test_expired_fifth_claim_dead_letters_without_sixth_attempt(): void
    {
        $this->accept();
        $clock = CarbonImmutable::now();
        for ($i = 0; $i < 5; $i++) {
            $this->assertNotNull($this->processor()->claimNext($this->actor(), $this->connector));
            $clock = $clock->addSeconds(61);
            CarbonImmutable::setTestNow($clock);
        }
        $this->assertSame(1, $this->processor()->drain($this->actor(), $this->connector)['dead_letter']);
        $this->assertSame(5, $this->row()->attempt_count);
        $this->assertSame('dead_letter', $this->row()->status);
    }

    public function test_lease_expiry_during_apply_rolls_back_and_recovers(): void
    {
        $this->accept();
        $injected = false;
        DB::listen(function ($q) use (&$injected): void {
            if (! $injected && str_contains($q->sql, 'insert into "ingestion_projections"')) {
                $injected = true;
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
            }
        });
        $this->assertSame(1, $this->processor()->drain($this->actor(), $this->connector, 1)['stale']);
        $this->scope(fn () => $this->assertSame(0, DB::table('ingestion_projections')->count()));
        $this->assertSame(1, $this->processor()->drain($this->actor(), $this->connector, 1)['completed']);
    }

    public function test_disabled_connector_before_drain_blocks_and_grants_never_confer_process(): void
    {
        $this->accept();
        $this->scope(fn () => DB::table('ingestion_connectors')->where('id', $this->connector)->update(['status' => 'disabled', 'revision' => 2]));
        $this->assertSame(1, $this->processor()->drain($this->actor(), $this->connector)['blocked']);
        $this->assertSame('connector_disabled', $this->row()->reason_code);
        $this->scope(fn () => DB::table('access_grants')->where('id', $this->processGrant)->update(['revoked_at' => now()]));
        $this->grant('connector.manage', $this->projectId);
        $this->grant('ingestion.read', $this->projectId);
        $this->expectException(AccessDeniedHttpException::class);
        $this->processor()->drain($this->actor(), $this->connector);
    }

    public function test_original_submitter_membership_revocation_is_independent_of_operator(): void
    {
        $this->accept();
        $operator = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $this->scope(function () use ($operator, $employee): void {
            DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic operator employee']);
            DB::table('users')->insert(['id' => $operator, 'tenant_id' => $this->tenant, 'employee_id' => $employee, 'display_name' => 'Synthetic operator']);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->projectId, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
            DB::table('access_grants')->where('id', $this->processGrant)->update(['user_id' => $operator]);
            DB::table('project_memberships')->where('employee_id', $this->employee)->update(['valid_to' => now()]);
        });
        $this->assertSame(1, $this->processor()->drain(new TrustedActor($operator, $this->tenant), $this->connector)['blocked']);
        $this->assertSame('access_revoked', $this->row()->reason_code);
        $this->scope(fn () => $this->assertSame(0, DB::table('ingestion_projections')->count()));
    }

    public function test_completion_rechecks_operator_and_rolls_back_projection(): void
    {
        $this->accept();
        $injected = false;
        DB::listen(function ($query) use (&$injected): void {
            if (! $injected && str_contains($query->sql, 'insert into "ingestion_projections"')) {
                $injected = true;
                DB::table('access_grants')->where('id', $this->processGrant)->update(['revoked_at' => now()]);
            }
        });
        $this->assertSame(1, $this->processor()->drain($this->actor(), $this->connector, 1)['blocked']);
        $this->scope(fn () => $this->assertSame(0, DB::table('ingestion_projections')->count()));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
