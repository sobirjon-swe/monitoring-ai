<?php

namespace Tests\Feature;

use App\Domain\Ba\BaClarificationService;
use App\Domain\Browser\BrowserAttemptCleanup;
use App\Domain\Browser\BrowserBaAuthority;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\BaTestCase;

class BrowserBaAttemptTest extends BaTestCase
{
    public function test_delayed_denial_cleanup_rechecks_restored_authority_for_same_completed_attempt(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $this->loginBrowser();
        $attempt = $this->postJson($path, ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'])->assertCreated()->json('data.attempt_id');
        $saved = app(TenantContext::class)->run($this->tenant, function () use ($attempt) {
            $ciphertext = DB::table('browser_ba_attempts')->where('id', $attempt)->value('ciphertext');

            return json_decode(Crypt::decryptString($ciphertext), true, 32, JSON_THROW_ON_ERROR);
        });
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->where('employee_id', $this->employee)->update(['valid_to' => now()]));
        try {
            app(BrowserBaAuthority::class)->check($this->actor(), $this->p, $id, $saved['key']);
            $this->fail('Revoked role was accepted.');
        } catch (AccessDeniedHttpException) {
            $this->assertTrue(true);
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->where('employee_id', $this->employee)->update(['valid_to' => null]));
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertOk();
        $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".session()->getId(), (string) config('app.key'));
        app(TenantContext::class)->run($this->tenant, fn () => app(BrowserAttemptCleanup::class)->clearDeniedSlot($attempt, $hash, $this->user, $this->p, $id));
        $slot = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->where('id', $attempt)->first());
        $this->assertSame('completed', $slot->state);
        $this->assertNotNull($slot->ciphertext);
    }

    public function test_cleanup_processes_multiple_attempts_when_project_is_closed(): void
    {
        $this->baInputs();
        $this->baRole();
        $first = $this->openRequest()['clarification_id'];
        $second = $this->openRequest(null, 'another-question')['clarification_id'];
        $this->loginBrowser();
        $attempts = [];
        foreach ([$first, $second] as $id) {
            $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
            $attempts[] = $this->postJson($path, ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'])->assertCreated()->json('data.attempt_id');
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('lifecycle_project_state')->where('project_id', $this->p)->update(['is_closed' => true]));
        config(['browser.attempt_cleanup_tenants' => [$this->tenant]]);
        $this->artisan('browser:cleanup-attempts')->assertSuccessful();
        $slots = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->whereIn('id', $attempts)->get());
        $this->assertCount(2, $slots);
        foreach ($slots as $slot) {
            $this->assertSame('tombstone', $slot->state);
            $this->assertNull($slot->ciphertext);
        }
    }

    public function test_delayed_denial_cleanup_preserves_a_replacement_attempt(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        $this->loginBrowser();
        $old = $this->postJson($path, $body)->assertCreated()->json('data.attempt_id');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->where('id', $old)->update(['state' => 'closed', 'ciphertext' => null]));
        $new = $this->postJson($path, $body)->assertCreated()->json('data.attempt_id');
        $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".session()->getId(), (string) config('app.key'));
        app(TenantContext::class)->run($this->tenant, fn () => app(BrowserAttemptCleanup::class)->clearDeniedSlot($old, $hash, $this->user, $this->p, $id));
        $slot = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->where('id', $new)->first());
        $this->assertSame('claimed', $slot->state);
        $this->assertNotNull($slot->ciphertext);
    }

    public function test_execute_validation_failure_rolls_back_and_releases_the_slot(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        $this->loginBrowser();
        $attempt = $this->postJson($path, $body)->assertCreated()->json('data.attempt_id');
        app(TenantContext::class)->run($this->tenant, function () use ($attempt): void {
            $slot = DB::table('browser_ba_attempts')->where('id', $attempt)->first();
            $saved = json_decode(Crypt::decryptString($slot->ciphertext), true, 32, JSON_THROW_ON_ERROR);
            $saved['body']['context'] = 'password=synthetic-secret-value';
            DB::table('browser_ba_attempts')->where('id', $attempt)->update(['ciphertext' => Crypt::encryptString(json_encode($saved, JSON_THROW_ON_ERROR))]);
        });
        $before = $this->baCounts();
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertUnprocessable()->assertJsonPath('error.code', 'attempt_rejected');
        $this->assertSame($before, $this->baCounts());
        $slot = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->where('id', $attempt)->first());
        $this->assertSame('closed', $slot->state);
        $this->assertNull($slot->ciphertext);
        $this->postJson($path, $body)->assertCreated();
    }

    public function test_scheduled_cleanup_detects_source_revocation_without_browser_request(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $this->loginBrowser();
        $attempt = $this->postJson($path, ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'])->assertCreated()->json('data.attempt_id');
        $ciphertext = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->where('id', $attempt)->value('ciphertext'));
        $this->assertStringNotContainsString('Synthetic context', $ciphertext);
        $this->assertStringNotContainsString($this->token, $ciphertext);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'ba.source.read')->update(['revoked_at' => now()]));
        config(['browser.attempt_cleanup_tenants' => [$this->tenant]]);
        app(BrowserAttemptCleanup::class)->run($this->tenant, 10);
        $slot = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->where('id', $attempt)->first());
        $this->assertNull($slot->ciphertext);
    }

    public function test_scheduled_cleanup_detects_token_revocation_without_browser_request(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $this->loginBrowser();
        $attempt = $this->postJson($path, ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'])->assertCreated()->json('data.attempt_id');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_tokens')->update(['revoked_at' => now()]));
        config(['browser.attempt_cleanup_tenants' => [$this->tenant]]);
        app(BrowserAttemptCleanup::class)->run($this->tenant, 10);
        $slot = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->where('id', $attempt)->first());
        $this->assertNull($slot->ciphertext);
    }

    public function test_scheduled_cleanup_detects_role_revocation_without_browser_request(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $this->loginBrowser();
        $attempt = $this->postJson($path, ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'])->assertCreated()->json('data.attempt_id');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->where('employee_id', $this->employee)->update(['valid_to' => now()]));
        config(['browser.attempt_cleanup_tenants' => [$this->tenant]]);
        app(BrowserAttemptCleanup::class)->run($this->tenant, 10);
        $slot = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->where('id', $attempt)->first());
        $this->assertNull($slot->ciphertext);
        $this->assertSame('tombstone', $slot->state);
    }

    public function test_token_revocation_denies_recovery_and_wipes_ciphertext(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $this->loginBrowser();
        $attempt = $this->postJson($path, ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'])->assertCreated()->json('data.attempt_id');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_tokens')->update(['revoked_at' => now()]));
        $this->get($path)->assertUnauthorized();
        $slot = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->where('id', $attempt)->first());
        $this->assertNull($slot->ciphertext);
        $this->assertSame('tombstone', $slot->state);
    }

    public function test_execution_conflict_releases_stale_slot(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        $this->loginBrowser();
        $attempt = $this->postJson($path, $body)->assertCreated()->json('data.attempt_id');
        app(BaClarificationService::class)->event($this->actor(), $this->p, $id, $body, 'other-request');
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertConflict()->assertJsonPath('error.code', 'attempt_conflict');
        $this->postJson($path, array_replace($body, ['expected_revision' => 2]))->assertCreated();
    }

    public function test_stale_revision_cannot_reserve_a_slot(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $this->loginBrowser();
        $body = ['clarification_id' => $id, 'expected_revision' => 2, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        $this->postJson($path, $body)->assertConflict();
        $this->postJson($path, array_replace($body, ['expected_revision' => 1]))->assertCreated();
    }

    public function test_revoked_role_blocks_recovery_and_execution(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $this->loginBrowser();
        $attempt = $this->postJson($path, ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'])->assertCreated()->json('data.attempt_id');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->where('employee_id', $this->employee)->update(['valid_to' => now()]));
        $this->get($path)->assertForbidden();
        $slot = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->where('id', $attempt)->first());
        $this->assertNull($slot->ciphertext);
        $this->assertSame('tombstone', $slot->state);
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertNotFound();
    }

    public function test_attempt_mutations_require_csrf(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $this->loginBrowser();
        app()->instance(PreventRequestForgery::class, new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson($path, ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'])->assertStatus(419);
        $this->postJson($path.'/execute', ['attempt_id' => (string) Str::uuid()])->assertStatus(419);
        $this->postJson($path.'/ack', ['attempt_id' => (string) Str::uuid()])->assertStatus(419);
    }

    public function test_ack_does_not_hide_errors_or_close_an_unfinished_attempt(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $this->loginBrowser();
        $attempt = $this->postJson($path, ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'])->assertCreated()->json('data.attempt_id');
        $this->postJson($path.'/ack', ['attempt_id' => $attempt])->assertConflict();
        $this->postJson($path.'/ack', ['attempt_id' => (string) Str::uuid()])->assertNotFound();
        $this->postJson($path.'/ack', ['bad' => $attempt])->assertUnprocessable();
        $this->get($path)->assertOk()->assertJsonPath('data.state', 'claimed');
    }

    public function test_raw_context_event_alias_is_unavailable(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $this->loginBrowser();
        $this->postJson('/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/events', [
            'clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context',
        ], ['Idempotency-Key' => 'raw'])->assertNotFound();
    }

    public function test_execute_replays_then_acknowledgement_releases_the_slot(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->grant('project.read', $this->p);
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        $this->loginBrowser();
        $attempt = $this->postJson($path, $body)->assertCreated()->json('data.attempt_id');
        $first = $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertOk()->json('data');
        $this->assertNotEmpty($first['event_id']);
        $this->get($path)->assertOk()->assertJsonPath('data.state', 'completed');
        $again = $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertOk()->json('data');
        $this->assertSame($first['event_id'], $again['event_id']);
        $this->postJson($path.'/ack', ['attempt_id' => $attempt])->assertNoContent();
        $this->postJson($path, array_replace($body, ['expected_revision' => 2]))->assertCreated();
        $this->postJson($path.'/ack', ['attempt_id' => $attempt])->assertNotFound();
    }

    public function test_revoked_credential_clears_pending_attempt_secrets(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $this->loginBrowser();
        $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".session()->getId(), (string) config('app.key'));
        app(TenantContext::class)->run($this->tenant, function () use ($id, $hash): void {
            DB::table('browser_ba_attempts')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p,
                'clarification_id' => $id, 'actor_id' => $this->user, 'session_hash' => $hash,
                'state' => 'claimed', 'ciphertext' => 'synthetic-ciphertext', 'expires_at' => now()->addHour(),
            ]);
            DB::table('auth_tokens')->update(['revoked_at' => now()]);
        });
        $this->getJson('/browser/v1/session')->assertUnauthorized();
        $attempt = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->first());
        $this->assertNull($attempt->ciphertext);
        $this->assertSame('tombstone', $attempt->state);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'array', 'session.secure' => false]);
        app('url')->forceRootUrl('http://127.0.0.1:18080');
        $this->withServerVariables(['HTTP_HOST' => '127.0.0.1:18080', 'SERVER_PORT' => 18080]);
    }

    public function test_attempt_slots_are_not_visible_without_tenant_context(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('browser_ba_attempts'));
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $this->loginBrowser();
        $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".session()->getId(), (string) config('app.key'));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p,
            'clarification_id' => $id, 'actor_id' => $this->user, 'session_hash' => $hash,
            'state' => 'claimed', 'ciphertext' => 'synthetic-ciphertext', 'expires_at' => now()->addHour(),
        ]));
        $this->assertSame(1, app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->count()));
        $this->assertSame(0, DB::table('browser_ba_attempts')->count());
    }

    public function test_claim_recovers_one_session_attempt_and_blocks_a_different_body(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->grant('project.read', $this->p);
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        $this->loginBrowser();
        $claim = $this->postJson($path, $body)->assertCreated()->json('data');
        $this->assertNotEmpty($claim['attempt_id']);
        $this->get($path)->assertOk()->assertJsonPath('data.attempt_id', $claim['attempt_id']);
        $this->postJson($path, array_replace($body, ['context' => 'Different']))->assertConflict();
    }

    public function test_attempt_actor_must_match_the_session_actor(): void
    {
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $other = $this->otherBaActor();
        $this->loginBrowser();
        $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".session()->getId(), (string) config('app.key'));
        $this->expectException(QueryException::class);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p,
            'clarification_id' => $id, 'actor_id' => $other->id, 'session_hash' => $hash,
            'state' => 'claimed', 'ciphertext' => 'synthetic-ciphertext', 'expires_at' => now()->addHour(),
        ]));
    }
}
