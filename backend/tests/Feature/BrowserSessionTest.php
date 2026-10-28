<?php

namespace Tests\Feature;

use App\Domain\Browser\BrowserSessionService;
use App\Domain\Identity\LocalTokenAuthenticator;
use App\Http\Middleware\AuthenticateBrowserSession;
use App\Support\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\BrowserTestCase;

class BrowserSessionTest extends BrowserTestCase
{
    public function test_locked_session_rejects_credential_that_expires_during_work(): void
    {
        $this->travelTo(now()->startOfSecond());
        $f = $this->browserInputs();
        $sessions = app(BrowserSessionService::class);
        $session = new Store('browser', new ArraySessionHandler(120));
        $session->start();
        $this->assertTrue($sessions->initialize($session));
        $this->assertNotNull($sessions->start($session, $f['token']));
        $this->expectException(AuthenticationException::class);
        $sessions->runLocked($session, function (): void {
            $this->travel(1)->hours();
        });
    }

    private ?string $browserCookie = null;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        // Laravel's in-process Store retains attributes across requests; a real
        // browser forwards the latest session cookie instead of switching IDs.
        $name = config('session.cookie');
        if ($this->browserCookie === null && $method === 'POST' && $uri === '/browser/v1/login') {
            $initial = parent::call('GET', '/browser/v1/session');
            $this->browserCookie = $initial->getCookie($name, false)?->getValue();
        }
        if ($this->browserCookie !== null) {
            $cookies[$name] = $this->browserCookie;
        }
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        $cookie = $response->getCookie($name, false);
        if ($cookie !== null) {
            $this->browserCookie = $cookie->getValue();
        }

        return $response;
    }

    public function test_anonymous_fences_are_invisible_without_session_context(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('browser_anonymous_fences'));
        $this->browserInputs();
        $session = new Store('browser', new ArraySessionHandler(120));
        $session->start();
        app(BrowserSessionService::class)->end($session);
        $this->assertSame(0, DB::table('browser_anonymous_fences')->count());
    }

    public function test_anonymous_copy_cannot_login_after_logout(): void
    {
        $f = $this->browserInputs();
        $sessions = app(BrowserSessionService::class);
        $original = new Store('original', new ArraySessionHandler(120));
        $original->start();
        $stale = new Store('stale', new ArraySessionHandler(120), $original->getId());
        $sessions->end($original);
        $this->assertNull($sessions->start($stale, $f['token']));
    }

    public function test_session_marker_rejects_a_changed_server_key(): void
    {
        $f = $this->browserInputs();
        $sessions = app(BrowserSessionService::class);
        $session = new Store('browser', new ArraySessionHandler(120));
        $session->start();
        $this->assertTrue($sessions->initialize($session));
        $this->assertNotNull($sessions->start($session, $f['token']));
        $key = config('app.key');
        try {
            config(['app.key' => 'synthetic-rotated-key']);
            $this->assertNull($sessions->resolve($session));
            $this->assertNull($sessions->start($session, $f['token']));
        } finally {
            config(['app.key' => $key]);
        }
    }

    public function test_empty_server_key_cannot_issue_a_session(): void
    {
        $f = $this->browserInputs();
        $session = new Store('browser', new ArraySessionHandler(120));
        $session->start();
        $this->assertTrue(app(BrowserSessionService::class)->initialize($session));
        app('encrypter');
        $key = config('app.key');
        try {
            config(['app.key' => '']);
            $this->assertNull(app(BrowserSessionService::class)->start($session, $f['token']));
            $this->getJson('/browser/v1/session')->assertStatus(503);
        } finally {
            config(['app.key' => $key]);
        }
    }

    public function test_markerless_old_copy_cannot_login_after_logout_and_key_rotation(): void
    {
        $f = $this->browserInputs();
        $sessions = app(BrowserSessionService::class);
        $original = new Store('original', new ArraySessionHandler(120));
        $original->start();
        $stale = new Store('stale', new ArraySessionHandler(120), $original->getId());
        $sessions->end($original);
        $key = config('app.key');
        try {
            config(['app.key' => 'synthetic-rotated-key']);
            $this->assertNull($sessions->start($stale, $f['token']));
        } finally {
            config(['app.key' => $key]);
        }
    }

    public function test_old_anonymous_file_cookie_cannot_login_after_logout(): void
    {
        $f = $this->browserInputs();
        config(['session.driver' => 'file']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $name = config('session.cookie');
        $initial = parent::call('GET', '/browser/v1/session');
        $initial->assertOk();
        $oldCookie = $initial->getCookie($name, false)->getValue();
        $oldId = session()->getId();
        $logout = parent::call('POST', '/browser/v1/logout', [], [$name => $oldCookie], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $logout->assertNoContent();
        try {
            $replay = parent::call('POST', '/browser/v1/login', [], [$name => $oldCookie], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['token' => $f['token']], JSON_THROW_ON_ERROR));
            $replay->assertUnauthorized();
        } finally {
            @unlink(config('session.files').'/'.$oldId);
            @unlink(config('session.files').'/'.session()->getId());
        }
    }

    public function test_old_anonymous_copy_logout_revokes_completed_login(): void
    {
        $f = $this->browserInputs();
        $sessions = app(BrowserSessionService::class);
        $session = new Store('browser', new ArraySessionHandler(120));
        $session->start();
        $this->assertTrue($sessions->initialize($session));
        $stale = new Store('stale', new ArraySessionHandler(120), $session->getId());
        $this->assertNotNull($sessions->start($session, $f['token']));
        $sessions->end($stale);
        $this->assertNull($sessions->resolve($session));
    }

    public function test_expired_anonymous_fence_cannot_be_reused(): void
    {
        $this->travelTo(now()->startOfSecond());
        $f = $this->browserInputs();
        $sessions = app(BrowserSessionService::class);
        $session = new Store('browser', new ArraySessionHandler(120));
        $session->start();
        $this->assertTrue($sessions->initialize($session));
        $this->travel(120)->minutes();
        $this->assertNull($sessions->start($session, $f['token']));
        $this->travelBack();
    }

    public function test_logout_fences_an_in_flight_copy_of_the_old_session(): void
    {
        $this->withCredentials();
        $f = $this->browserInputs();
        $login = $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk();
        $this->withUnencryptedCookie(config('session.cookie'), $login->getCookie(config('session.cookie'), false)->getValue());
        $stale = new Store('stale', new ArraySessionHandler(120), session()->getId());
        $stale->put(BrowserSessionService::BINDING, session()->get(BrowserSessionService::BINDING));
        $this->postJson('/browser/v1/logout')->assertNoContent();
        $this->assertNull(app(BrowserSessionService::class)->resolve($stale));
    }

    public function test_malformed_relogin_revokes_the_previous_session_fence(): void
    {
        $this->withCredentials();
        $f = $this->browserInputs();
        $login = $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk();
        $this->withUnencryptedCookie(config('session.cookie'), $login->getCookie(config('session.cookie'), false)->getValue());
        $stale = new Store('stale', new ArraySessionHandler(120), session()->getId());
        $stale->put(BrowserSessionService::BINDING, session()->get(BrowserSessionService::BINDING));
        $this->call('POST', '/browser/v1/login', [], [config('session.cookie') => $login->getCookie(config('session.cookie'), false)->getValue()], [], ['CONTENT_TYPE' => 'application/json'], '{invalid')->assertUnprocessable();
        $this->assertNull(app(BrowserSessionService::class)->resolve($stale));
    }

    public function test_stale_session_cannot_reauthenticate_after_logout(): void
    {
        $this->withCredentials();
        $f = $this->browserInputs();
        $login = $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk();
        $this->withUnencryptedCookie(config('session.cookie'), $login->getCookie(config('session.cookie'), false)->getValue());
        $stale = new Store('stale', new ArraySessionHandler(120), session()->getId());
        $stale->put(BrowserSessionService::BINDING, session()->get(BrowserSessionService::BINDING));
        $this->postJson('/browser/v1/logout')->assertNoContent();
        $this->assertNull(app(BrowserSessionService::class)->start($stale, $f['token']));
    }

    public function test_old_session_logout_revokes_a_completed_replacement(): void
    {
        $f = $this->browserInputs();
        $sessions = app(BrowserSessionService::class);
        $old = new Store('old', new ArraySessionHandler(120));
        $old->start();
        $this->assertTrue($sessions->initialize($old));
        $this->assertNotNull($sessions->start($old, $f['token']));
        $stale = new Store('stale', new ArraySessionHandler(120), $old->getId());
        $stale->put(BrowserSessionService::BINDING, $old->get(BrowserSessionService::BINDING));
        $this->assertNotNull($sessions->start($old, $f['token']));
        $sessions->end($stale);
        $this->assertNull($sessions->resolve($old));
    }

    public function test_replacement_cannot_switch_tenants_without_logout(): void
    {
        $first = $this->browserInputs();
        $sessions = app(BrowserSessionService::class);
        $store = new Store('browser', new ArraySessionHandler(120));
        $store->start();
        $this->assertTrue($sessions->initialize($store));
        $this->assertNotNull($sessions->start($store, $first['token']));
        $this->tenant = (string) Str::uuid();
        $this->user = (string) Str::uuid();
        $this->employee = (string) Str::uuid();
        $this->policy = (string) Str::uuid();
        $second = $this->browserInputs();
        $this->assertNotNull(app(LocalTokenAuthenticator::class)->authenticate($second['token']));
        $this->assertNull($sessions->start($store, $second['token']));
    }

    public function test_session_fence_is_invisible_without_tenant_context(): void
    {
        $f = $this->browserInputs();
        $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk();
        $this->assertSame(0, DB::table('browser_session_fences')->count());
        $this->assertSame(1, app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_session_fences')->count()));
    }

    public function test_token_once_login_reload_logout_rotates_and_never_persists_secret(): void
    {
        $this->withCredentials();
        $f = $this->browserInputs();
        $logs = [];
        Log::listen(function ($event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });
        $this->getJson('/browser/v1/session')->assertOk()->assertJsonPath('data.authenticated', false);
        $before = session()->getId();
        $csrf = session()->token();
        $login = $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk()->assertJsonPath('data.user.id', $f['user_id']);
        $this->withUnencryptedCookie(config('session.cookie'), $login->getCookie(config('session.cookie'), false)->getValue());
        $this->assertNotSame($before, session()->getId());
        $this->assertNotSame($csrf, session()->token());
        $reload = $this->getJson('/browser/v1/session')->assertOk()->assertJsonPath('data.authenticated', true);
        $this->assertFalse(str_contains(serialize([session()->all(), $login->getContent(), $reload->getContent(), $logs]), $f['token']), 'Credential leaked.');
        $this->postJson('/browser/v1/logout')->assertNoContent();
        $this->getJson('/browser/v1/session')->assertOk()->assertJsonPath('data.authenticated', false);
        $this->assertNotNull(app(LocalTokenAuthenticator::class)->authenticate($f['token']));
        $this->assertStringContainsString('no-store', $reload->headers->get('Cache-Control'));
    }

    public function test_file_cookie_reload_and_failed_login_persist_identity_removal(): void
    {
        $f = $this->browserInputs();
        config(['session.driver' => 'file']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $this->withCredentials();
        $login = $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk();
        $cookie = $login->getCookie(config('session.cookie'), false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain());
        $this->assertFalse($cookie->isSecure());
        $id = session()->getId();
        $file = config('session.files').'/'.$id;
        try {
            $this->assertFalse(str_contains(file_get_contents($file), $f['token']), 'Credential persisted.');
            $this->withUnencryptedCookie(config('session.cookie'), $cookie->getValue());
            app('session')->forgetDrivers();
            app()->forgetInstance('session.store');
            $this->getJson('/browser/v1/session')->assertOk()->assertJsonPath('data.user.id', $f['user_id']);
            $this->postJson('/browser/v1/login', ['token' => 'invalid'])->assertUnauthorized();
            app('session')->forgetDrivers();
            app()->forgetInstance('session.store');
            $this->getJson('/browser/v1/session')->assertOk()->assertJsonPath('data.authenticated', false);
        } finally {
            @unlink($file);
            @unlink(config('session.files').'/'.session()->getId());
        }
    }

    public function test_malformed_payload_never_looks_up_a_credential(): void
    {
        $this->browserInputs();
        $lookups = 0;
        DB::listen(function ($query) use (&$lookups): void {
            if (str_contains($query->sql, 'auth_tokens')) {
                $lookups++;
            }
        });
        foreach (['{"token":false}', '{"token":"bad","to\\u006ben":"bad"}', '{"token":"x\\u007f"}', '{"token":"bad"} trailing', str_repeat(' ', 1025)] as $raw) {
            app('cache')->flush();
            $response = $this->call('POST', '/browser/v1/login', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw);
            $this->assertContains($response->getStatusCode(), [413, 422]);
        }
        $this->assertSame(0, $lookups);
    }

    public function test_forbidden_json_whitespace_removes_binding_without_credential_lookup(): void
    {
        $f = $this->browserInputs();
        $lookups = 0;
        DB::listen(function ($query) use (&$lookups): void {
            if (str_contains($query->sql, 'auth_tokens')) {
                $lookups++;
            }
        });
        $segments = ['{', '"token"', ':', json_encode($f['token'], JSON_THROW_ON_ERROR), '}'];
        foreach (["\x0b", "\x0c"] as $whitespace) {
            for ($position = 0; $position <= count($segments); $position++) {
                app('cache')->flush();
                $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk();
                $lookups = 0;
                $parts = $segments;
                array_splice($parts, $position, 0, [$whitespace]);
                $response = $this->call('POST', '/browser/v1/login', [], [], [], ['CONTENT_TYPE' => 'application/json'], implode('', $parts));
                $this->assertSame(422, $response->getStatusCode());
                $this->assertSame(0, $lookups, 'Malformed JSON looked up a credential.');
                $this->getJson('/browser/v1/session')->assertOk()->assertJsonPath('data.authenticated', false);
            }
        }
    }

    public function test_json_space_tab_cr_and_lf_remain_valid_at_all_structural_boundaries(): void
    {
        $f = $this->browserInputs();
        $cookies = [];
        foreach ([' ', "\t", "\r", "\n"] as $whitespace) {
            app('cache')->flush();
            $raw = $whitespace.implode($whitespace, ['{', '"token"', ':', json_encode($f['token'], JSON_THROW_ON_ERROR), '}']).$whitespace;
            $response = $this->call('POST', '/browser/v1/login', [], $cookies, [], ['CONTENT_TYPE' => 'application/json'], $raw);
            $response->assertOk()->assertJsonPath('data.user.id', $f['user_id']);
            $cookies = [config('session.cookie') => $response->getCookie(config('session.cookie'), false)->getValue()];
        }
    }

    public function test_invalid_login_removes_prior_identity_and_rejects_closed_json_contract(): void
    {
        $f = $this->browserInputs();
        foreach (['{"token":"bad","token":"bad"}', '{"token":"bad","extra":1}', '{"token":1}', '{"token":"x\\u0000"}', '[]'] as $raw) {
            $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk();
            $response = $this->call('POST', '/browser/v1/login', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw);
            $this->assertSame(422, $response->getStatusCode());
            $this->getJson('/browser/v1/session')->assertJsonPath('data.authenticated', false);
            app('cache')->flush();
        }
        $this->call('POST', '/browser/v1/login', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat(' ', 1025))->assertStatus(413);
        app('cache')->flush();
        $this->postJson('/browser/v1/login', ['token' => str_repeat('x', 513)])->assertStatus(413);
        app('cache')->flush();
        $this->postJson('/browser/v1/login', ['token' => 'invalid'])->assertUnauthorized();
        $this->assertFalse(str_contains(serialize(session()->all()), $f['token']), 'Credential persisted.');
    }

    public function test_browser_never_falls_back_between_credentials(): void
    {
        $f = $this->browserInputs();
        $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk();
        foreach (['Bearer '.$f['token'], 'Bearer invalid', ''] as $header) {
            $this->withHeader('Authorization', $header)->getJson('/browser/v1/session')->assertUnauthorized();
        }
        $this->flushHeaders();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($f['token'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_protected_browser_middleware_uses_only_current_cookie_actor(): void
    {
        $this->withCredentials();
        $f = $this->browserInputs();
        Route::middleware(['browser', AuthenticateBrowserSession::class])->get('/browser/v1/test-actor', fn (Request $request) => response()->json(['actor' => $request->attributes->get('trusted_actor')->id]));
        $this->getJson('/browser/v1/test-actor')->assertUnauthorized();
        $this->getJson('/browser/v1/session')->assertOk();
        $login = $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk();
        $this->withUnencryptedCookie(config('session.cookie'), $login->getCookie(config('session.cookie'), false)->getValue());
        $this->withHeaders(['X-Tenant-ID' => (string) Str::uuid(), 'X-Actor-ID' => (string) Str::uuid()])->getJson('/browser/v1/test-actor')->assertOk()->assertJsonPath('actor', $f['user_id']);
        $this->withToken($f['token'])->getJson('/browser/v1/test-actor')->assertUnauthorized();
        $this->flushHeaders();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_tokens')->update(['revoked_at' => now()]));
        $this->head('/browser/v1/test-actor')->assertUnauthorized()->assertContent('');
    }

    public function test_session_binding_rechecks_current_credential_chain(): void
    {
        $this->travelTo(now()->startOfSecond());
        $f = $this->browserInputs();
        $service = app(BrowserSessionService::class);
        $session = new Store('browser_test', new ArraySessionHandler(120));
        $session->start();
        $mutations = [
            ['auth_tokens', ['revoked_at' => now()]],
            ['auth_subjects', ['revoked_at' => now()]],
            ['auth_subjects', ['issuer' => 'other']],
            ['users', ['status' => 'inactive']],
            ['tenants', ['status' => 'inactive']],
            ['auth_tokens', ['secret_hash' => hash('sha256', 'replacement')]],
            ['auth_subjects', ['subject' => 'rebound']],
        ];
        foreach ($mutations as [$table, $values]) {
            $this->assertTrue($service->initialize($session));
            $this->assertNotNull($service->start($session, $f['token']));
            $original = app(TenantContext::class)->run($this->tenant, fn () => (array) DB::table($table)->first());
            app(TenantContext::class)->run($this->tenant, fn () => DB::table($table)->update($values));
            $this->assertNull($service->resolve($session));
            app(TenantContext::class)->run($this->tenant, fn () => DB::table($table)->update($original));
        }
        $this->assertTrue($service->initialize($session));
        $this->assertNotNull($service->start($session, $f['token']));
        $this->travel(1)->hours();
        $this->assertNull($service->resolve($session));
        $this->travelBack();
    }

    public function test_subject_user_and_token_subject_rebinding_invalidates_existing_session(): void
    {
        $f = $this->browserInputs();
        $service = app(BrowserSessionService::class);
        $session = new Store('browser_test', new ArraySessionHandler(120));
        $session->start();
        $otherUser = (string) Str::uuid();
        $otherSubject = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($otherUser, $otherSubject): void {
            DB::table('users')->insert(['id' => $otherUser, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic other user']);
            DB::table('auth_subjects')->insert(['id' => $otherSubject, 'tenant_id' => $this->tenant, 'user_id' => $otherUser, 'issuer' => 'local-development', 'subject' => $otherUser]);
        });
        $this->assertTrue($service->initialize($session));
        $this->assertNotNull($service->start($session, $f['token']));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_subjects')->where('id', $f['subject_id'])->update(['user_id' => $otherUser]));
        $this->assertNull($service->resolve($session));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_subjects')->where('id', $f['subject_id'])->update(['user_id' => $f['user_id']]));
        $this->assertTrue($service->initialize($session));
        $this->assertNotNull($service->start($session, $f['token']));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_tokens')->where('id', $f['token_id'])->update(['auth_subject_id' => $otherSubject]));
        $this->assertNull($service->resolve($session));
        $this->assertSame($otherUser, app(LocalTokenAuthenticator::class)->authenticate($f['token'])?->id);
    }

    public function test_precise_token_boundary_accepts_one_microsecond_before_and_rejects_at_expiry(): void
    {
        $this->withCredentials();
        $this->travelTo(now()->startOfSecond());
        $f = $this->browserInputs();
        $expiry = now()->addSeconds(3)->addMicroseconds(123456)->toImmutable();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_tokens')->update(['expires_at' => $expiry->format('Y-m-d H:i:s.uP')]));
        $login = $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk()->assertJsonPath('data.expires_at', $expiry->format('Y-m-d\TH:i:s.uP'));
        $this->withUnencryptedCookie(config('session.cookie'), $login->getCookie(config('session.cookie'), false)->getValue());
        $this->travelTo($expiry->subMicrosecond());
        $this->getJson('/browser/v1/session')->assertOk()->assertJsonPath('data.authenticated', true);
        $this->assertNotNull(app(LocalTokenAuthenticator::class)->authenticate($f['token']));
        $this->travelTo($expiry);
        $this->getJson('/browser/v1/session')->assertUnauthorized();
        $this->assertNull(app(LocalTokenAuthenticator::class)->authenticate($f['token']));
        $this->getJson('/browser/v1/session')->assertOk()->assertJsonPath('data.authenticated', false);
        $this->travelBack();
    }

    public function test_idle_boundary_and_precise_expiry_cap_invalidate_next_head(): void
    {
        $this->travelTo(now()->startOfSecond());
        $f = $this->browserInputs();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_tokens')->update(['expires_at' => now()->addHours(4)]));
        $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk();
        $this->travel(120)->minutes();
        $this->getJson('/browser/v1/session')->assertUnauthorized();
        app('cache')->flush();
        $this->getJson('/browser/v1/session')->assertOk();
        $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk();
        $expiry = now()->addSecond()->addMicroseconds(123456);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_tokens')->update(['expires_at' => $expiry->format('Y-m-d H:i:s.uP')]));
        $this->travelTo($expiry);
        $this->head('/browser/v1/session')->assertUnauthorized()->assertContent('');
        $this->travelBack();
    }

    public function test_login_throttles_sixth_attempt(): void
    {
        app('cache')->flush();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/browser/v1/login', ['token' => 'invalid'])->assertUnauthorized();
        }
        $this->postJson('/browser/v1/login', ['token' => 'invalid'])->assertStatus(429);
    }

    public function test_real_csrf_middleware_requires_origin_or_token_for_login_and_logout(): void
    {
        $f = $this->browserInputs();
        app()->instance(PreventRequestForgery::class, new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->getJson('/browser/v1/session')->assertOk();
        $this->postJson('/browser/v1/login', ['token' => $f['token']])->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', session()->token())->postJson('/browser/v1/login', ['token' => $f['token']])->assertOk();
        $this->flushHeaders();
        $this->postJson('/browser/v1/logout')->assertStatus(419);
        $this->withHeader('Sec-Fetch-Site', 'cross-site')->postJson('/browser/v1/logout')->assertStatus(419);
        $this->withHeader('Sec-Fetch-Site', 'same-site')->postJson('/browser/v1/logout')->assertStatus(419);
        $this->withHeader('Sec-Fetch-Site', 'same-origin')->postJson('/browser/v1/logout')->assertNoContent();
    }
}
