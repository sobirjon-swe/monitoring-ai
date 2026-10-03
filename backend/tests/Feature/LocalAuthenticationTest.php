<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccessTestCase;

class LocalAuthenticationTest extends AccessTestCase
{
    public function test_verified_token_binds_actor_and_ignores_forged_headers(): void
    {
        $this->fixture();
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'X-Tenant-ID' => (string) Str::uuid(), 'X-Actor-ID' => (string) Str::uuid()])
            ->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $this->user)->assertJsonPath('data.tenant_id', $this->tenant);
        $parts = explode('.', $this->token);
        $this->withToken((string) Str::uuid().'.'.$parts[1].'.'.$parts[2])->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($parts[0].'.'.$parts[1].'.'.str_repeat('0', 64))->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_expired_revoked_and_production_tokens_are_denied(): void
    {
        $this->fixture();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_tokens')->update(['expires_at' => now()->subMinute()]));
        $this->withToken($this->token)->getJson('/api/v1/me')->assertUnauthorized();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_tokens')->update(['expires_at' => now()->addHour(), 'revoked_at' => now()]));
        $this->withToken($this->token)->getJson('/api/v1/me')->assertUnauthorized();
        app()->instance('env', 'production');
        $this->withToken($this->token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_no_signup_or_grant_escalation_endpoint_exists(): void
    {
        $this->fixture();
        $this->withToken($this->token)->postJson('/api/v1/access-grants', ['purpose' => 'organization.manage'])->assertNotFound();
        $this->withToken($this->token)->postJson('/api/v1/signup', ['admin' => true])->assertNotFound();
    }

    public function test_subject_and_user_revocation_deny_next_request(): void
    {
        $this->fixture();
        $this->withToken($this->token)->getJson('/api/v1/me')->assertOk();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_subjects')->update(['revoked_at' => now()]));
        $this->getJson('/api/v1/me')->assertUnauthorized();
        app(TenantContext::class)->run($this->tenant, function (): void {
            DB::table('auth_subjects')->update(['revoked_at' => null]);
            DB::table('users')->update(['status' => 'inactive']);
        });
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_bootstrap_refuses_testing_and_production_environments(): void
    {
        $this->artisan('app:bootstrap-local', ['--tenant' => $this->tenant, '--name' => 'Synthetic', '--subject' => 'synthetic'])->assertExitCode(1);
        app()->instance('env', 'production');
        $this->artisan('app:bootstrap-local', ['--tenant' => $this->tenant, '--name' => 'Synthetic', '--subject' => 'synthetic'])->assertExitCode(1);
    }

    public function test_explicit_bootstrap_creates_hashed_token_and_audit_atomically(): void
    {
        // The CLI intentionally refuses test databases; use a rolled-back fixture in the dedicated dev DB.
        DB::rollBack();
        DB::purge('pgsql');
        config(['database.connections.pgsql.database' => 'monitoring_ai_dev']);
        app()->instance('env', 'local');
        try {
            $this->assertSame('monitoring_ai_dev', DB::scalar('select current_database()'));
            $this->assertSame('monitoring_ai_app', DB::scalar('select current_user'));
            DB::beginTransaction();
            $args = ['--tenant' => $this->tenant, '--name' => 'Synthetic rollback fixture', '--subject' => 'synthetic-'.$this->user];
            $this->assertSame(0, Artisan::call('app:bootstrap-local', $args));
            preg_match('/([a-f0-9-]{36}\.[a-f0-9-]{36}\.[a-f0-9]{64})/', Artisan::output(), $match);
            $this->assertNotEmpty($match[1] ?? null);
            $this->withToken($match[1])->getJson('/api/v1/me')->assertOk();
            app(TenantContext::class)->run($this->tenant, function () use ($match): void {
                $parts = explode('.', $match[1]);
                $this->assertSame(hash('sha256', $parts[2]), DB::table('auth_tokens')->value('secret_hash'));
                $this->assertSame(10, DB::table('access_grants')->count());
                $this->assertSame(14, DB::table('audit_events')->count());
                $this->assertNull(DB::table('users')->value('employee_id'));
            });
            $this->assertSame(1, Artisan::call('app:bootstrap-local', $args));
            app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(1, DB::table('users')->count()));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('pgsql');
            config(['database.connections.pgsql.database' => 'monitoring_ai_test']);
            app()->instance('env', 'testing');
        }
    }

    public function test_bootstrap_refuses_overridden_endpoints_before_connection(): void
    {
        app()->instance('env', 'local');
        foreach ([['host' => '203.0.113.1'], ['url' => 'pgsql://remote.invalid/db'], ['read' => ['host' => 'remote.invalid']], ['write' => ['host' => 'remote.invalid']]] as $override) {
            $original = config('database.connections.pgsql');
            config(['database.connections.pgsql' => array_merge($original, ['database' => 'monitoring_ai_dev'], $override)]);
            $this->assertSame(1, Artisan::call('app:bootstrap-local', ['--tenant' => $this->tenant, '--name' => 'Synthetic', '--subject' => 'synthetic']));
            config(['database.connections.pgsql' => $original]);
        }
    }

    public function test_bootstrap_refuses_mismatched_default_before_connection_acquisition(): void
    {
        $acquired = false;
        DB::extend('t05_guard_sentinel', function () use (&$acquired): never {
            $acquired = true;
            throw new \RuntimeException('Sentinel connection must never be acquired.');
        });
        $original = config('database.connections.pgsql');
        app()->instance('env', 'local');
        config([
            'database.default' => 't05_guard_sentinel',
            'database.connections.t05_guard_sentinel' => ['driver' => 't05_guard_sentinel'],
            'database.connections.pgsql.database' => 'monitoring_ai_dev',
        ]);
        try {
            $this->assertSame(1, Artisan::call('app:bootstrap-local', ['--tenant' => $this->tenant, '--name' => 'Synthetic', '--subject' => 'synthetic']));
            $this->assertFalse($acquired, 'Bootstrap acquired the unvalidated default connection.');
        } finally {
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $original]);
            app()->instance('env', 'testing');
        }
    }
}
