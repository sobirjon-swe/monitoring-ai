<?php

namespace Tests\Feature;

use App\Domain\Browser\BrowserAttemptCleanup;
use App\Domain\Browser\BrowserSessionService;
use App\Support\TenantContext;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BaTestCase;

class BrowserAttemptCleanupTest extends BaTestCase
{
    public function test_sweep_high_water_excludes_new_higher_ids_until_next_sweep(): void
    {
        $this->baInputs();
        $this->baRole();
        $clarification = $this->openRequest()['clarification_id'];
        $ids = ['40000000-0000-4000-8000-000000000001', '80000000-0000-4000-8000-000000000001', 'c0000000-0000-4000-8000-000000000001', '10000000-0000-4000-8000-000000000001'];
        $insert = function (string $id) use ($clarification): void {
            $session = new Store('browser', new ArraySessionHandler(120));
            $session->start();
            $sessions = app(BrowserSessionService::class);
            $this->assertTrue($sessions->initialize($session));
            $this->assertNotNull($sessions->start($session, $this->token));
            $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".$session->getId(), (string) config('app.key'));
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->insert([
                'id' => $id, 'tenant_id' => $this->tenant, 'project_id' => $this->p,
                'clarification_id' => $clarification, 'actor_id' => $this->user, 'session_hash' => $hash,
                'state' => 'claimed', 'ciphertext' => 'synthetic-ciphertext', 'expires_at' => now()->addHour(),
            ]));
        };
        $insert($ids[0]);
        $insert($ids[1]);
        config(['browser.attempt_cleanup_tenants' => [$this->tenant]]);
        $cleanup = app(BrowserAttemptCleanup::class);
        $cursor = $cleanup->run($this->tenant, 1, null, $ids[1]);
        $this->assertSame($ids[0], $cursor);
        $insert($ids[2]);
        $insert($ids[3]);
        $cursor = $cleanup->run($this->tenant, 1, $cursor, $ids[1]);
        $this->assertSame($ids[1], $cursor);
        $this->assertNull($cleanup->run($this->tenant, 1, $cursor, $ids[1]));
        $this->assertSame($ids[3], $cleanup->run($this->tenant, 1, null, $ids[2]));
    }

    public function test_periodic_command_processes_allowed_tenant_and_records_cursor(): void
    {
        $this->baInputs();
        config(['browser.attempt_cleanup_tenants' => [$this->tenant]]);
        $this->artisan('browser:cleanup-attempts')->assertSuccessful();
        $cursor = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_attempt_cleanup_cursors')->where('tenant_id', $this->tenant)->first());
        $this->assertNotNull($cursor);
        $this->assertSame(0, DB::table('browser_attempt_cleanup_cursors')->count());
        $this->assertSame(0, app(TenantContext::class)->run((string) Str::uuid(), fn () => DB::table('browser_attempt_cleanup_cursors')->count()));
    }

    public function test_cleanup_refuses_a_tenant_outside_the_allowlist(): void
    {
        config(['browser.attempt_cleanup_tenants' => []]);
        $this->expectException(\InvalidArgumentException::class);
        app(BrowserAttemptCleanup::class)->run($this->tenant, 10);
    }

    public function test_cleanup_erases_attempt_when_tenant_fence_is_revoked(): void
    {
        $this->baInputs();
        $this->baRole();
        $clarification = $this->openRequest()['clarification_id'];
        $sessions = app(BrowserSessionService::class);
        $session = new Store('browser', new ArraySessionHandler(120));
        $session->start();
        $this->assertTrue($sessions->initialize($session));
        $this->assertNotNull($sessions->start($session, $this->token));
        $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".$session->getId(), (string) config('app.key'));
        app(TenantContext::class)->run($this->tenant, function () use ($clarification, $hash): void {
            DB::table('browser_ba_attempts')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p,
                'clarification_id' => $clarification, 'actor_id' => $this->user, 'session_hash' => $hash,
                'state' => 'claimed', 'ciphertext' => 'synthetic-ciphertext', 'expires_at' => now()->addHour(),
            ]);
            DB::table('browser_session_fences')->where('session_hash', $hash)->update(['revoked_at' => now()]);
        });
        config(['browser.attempt_cleanup_tenants' => [$this->tenant]]);
        app(BrowserAttemptCleanup::class)->run($this->tenant, 10);
        $attempt = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->first());
        $this->assertNull($attempt->ciphertext);
        $this->assertSame('tombstone', $attempt->state);
    }

    public function test_cleanup_erases_attempt_when_actor_is_inactive(): void
    {
        $this->baInputs();
        $this->baRole();
        $clarification = $this->openRequest()['clarification_id'];
        $sessions = app(BrowserSessionService::class);
        $session = new Store('browser', new ArraySessionHandler(120));
        $session->start();
        $this->assertTrue($sessions->initialize($session));
        $this->assertNotNull($sessions->start($session, $this->token));
        $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".$session->getId(), (string) config('app.key'));
        app(TenantContext::class)->run($this->tenant, function () use ($clarification, $hash): void {
            DB::table('browser_ba_attempts')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p,
                'clarification_id' => $clarification, 'actor_id' => $this->user, 'session_hash' => $hash,
                'state' => 'claimed', 'ciphertext' => 'synthetic-ciphertext', 'expires_at' => now()->addHour(),
            ]);
            DB::table('users')->where('id', $this->user)->update(['status' => 'inactive']);
        });
        config(['browser.attempt_cleanup_tenants' => [$this->tenant]]);
        app(BrowserAttemptCleanup::class)->run($this->tenant, 10);
        $attempt = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->first());
        $this->assertNull($attempt->ciphertext);
    }

    public function test_tenant_cleanup_erases_attempt_after_anonymous_ancestor_logout(): void
    {
        $this->baInputs();
        $this->baRole();
        $clarification = $this->openRequest()['clarification_id'];
        $sessions = app(BrowserSessionService::class);
        $session = new Store('browser', new ArraySessionHandler(120));
        $session->start();
        $this->assertTrue($sessions->initialize($session));
        $stale = new Store('stale', new ArraySessionHandler(120), $session->getId());
        foreach ($session->all() as $key => $value) {
            $stale->put($key, $value);
        }
        $this->assertNotNull($sessions->start($session, $this->token));
        $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".$session->getId(), (string) config('app.key'));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p,
            'clarification_id' => $clarification, 'actor_id' => $this->user, 'session_hash' => $hash,
            'state' => 'claimed', 'ciphertext' => 'synthetic-ciphertext', 'expires_at' => now()->addHour(),
        ]));
        $sessions->end($stale);
        config(['browser.attempt_cleanup_tenants' => [$this->tenant]]);
        $this->artisan('browser:cleanup-attempts')->assertSuccessful();
        $attempt = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->first());
        $this->assertSame('tombstone', $attempt->state);
        $this->assertNull($attempt->ciphertext);
    }
}
