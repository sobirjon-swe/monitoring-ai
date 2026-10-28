<?php

namespace Tests\Feature;

use App\Domain\Ba\BaClarificationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BaTestCase;

class BrowserBaContextTest extends BaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'array', 'session.secure' => false]);
        app('url')->forceRootUrl('http://127.0.0.1:18080');
        $this->withServerVariables(['HTTP_HOST' => '127.0.0.1:18080', 'SERVER_PORT' => 18080]);
    }

    public function test_authorized_context_is_created_and_exact_replay_is_safe(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->grant('project.read', $this->p);
        $request = $this->openRequest();
        $id = $request['clarification_id'];
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $this->loginBrowser();
        $this->assertContains('ba.request.manage', $this->get('/browser/v1/projects/'.$this->p.'/capabilities')->assertOk()->json('data.purposes'));
        $before = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ba_clarifications')->where('id', $id)->first());
        $attempt = $this->postJson($path, $body)->assertCreated()->json('data.attempt_id');
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertOk()->assertJsonPath('data.replayed', false);
        $after = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ba_clarifications')->where('id', $id)->first());
        foreach (['state', 'current_request_id', 'current_answer_id', 'current_review_id'] as $field) {
            $this->assertSame($before->$field, $after->$field);
        }
        $this->get('/browser/v1/projects/'.$this->p.'/ba/history/'.$id)->assertOk()->assertJsonCount(2, 'data.items');
        $counts = $this->baCounts();
        $this->postJson($path, $body)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertOk();
        $this->assertSame($counts, $this->baCounts());
    }

    public function test_unrelated_valid_event_and_invalid_payloads_never_write(): void
    {
        [$id, $path] = $this->ready();
        $counts = $this->baCounts();
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'request.withdraw', 'reason' => 'Synthetic reason'];
        $this->postJson($path, $body)->assertUnprocessable();
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        foreach ([$body + ['extra' => true], array_replace($body, ['context' => '']), array_replace($body, ['context' => str_repeat('é', 2001)]), array_replace($body, ['context' => 'password=synthetic-secret-value']), array_replace($body, ['clarification_id' => (string) Str::uuid()])] as $invalid) {
            $this->postJson($path, $invalid)->assertUnprocessable();
        }
        $this->postJson($path, $body, ['Idempotency-Key' => 'valid', 'Expected-Source-Revision' => '0'])->assertUnprocessable();
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $raw = str_replace('"kind":', '"kind":"context.add","kind":', $raw);
        $this->call('POST', $path, [], $this->unencryptedCookies, [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertUnprocessable();
        $this->call('POST', $path, [], $this->unencryptedCookies, [], ['CONTENT_TYPE' => 'application/json'], str_repeat(' ', 65537))->assertStatus(413);
        $this->assertSame($counts, $this->baCounts());
    }

    public function test_conflicts_and_revocation_are_enforced_on_replay(): void
    {
        [$id, $path] = $this->ready();
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        $attempt = $this->postJson($path, $body)->assertCreated()->json('data.attempt_id');
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertOk();
        $counts = $this->baCounts();
        $this->postJson($path, array_replace($body, ['context' => 'Different synthetic context']))->assertConflict();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'ba.request.manage')->update(['revoked_at' => now()]));
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertForbidden();
        $this->assertSame($counts, $this->baCounts());
    }

    public function test_browser_auth_and_csrf_do_not_accept_bearer_fallback(): void
    {
        [$id, $path] = $this->ready();
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        $counts = $this->baCounts();
        $this->withToken($this->token)->postJson($path, $body)->assertUnauthorized();
        $this->flushHeaders();
        app()->instance(PreventRequestForgery::class, new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson($path, $body)->assertStatus(419);
        $this->postJson($path, $body, ['Sec-Fetch-Site' => 'cross-site'])->assertStatus(419);
        $this->assertSame($counts, $this->baCounts());
    }

    public function test_project_role_and_source_revocation_deny_writes_and_replay(): void
    {
        [$id, $path] = $this->ready();
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => str_repeat('é', 2000)];
        $attempt = $this->postJson($path, $body)->assertCreated()->json('data.attempt_id');
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertOk();
        $counts = $this->baCounts();
        foreach ([
            ['role_assignments', 'employee_id', $this->employee, ['valid_to' => now()]],
            ['project_memberships', 'employee_id', $this->employee, ['valid_to' => now()]],
            ['access_grants', 'purpose', 'ba.source.read', ['revoked_at' => now()]],
        ] as [$table, $column, $value, $changes]) {
            app(TenantContext::class)->run($this->tenant, function () use ($table, $column, $value, $changes, $path, $attempt): void {
                DB::beginTransaction();
                try {
                    DB::table($table)->where($column, $value)->update($changes);
                    $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertForbidden();
                    $this->assertNull(DB::table('browser_ba_attempts')->where('id', $attempt)->value('ciphertext'));
                } finally {
                    DB::rollBack();
                }
            });
        }
        $other = $this->project();
        $this->postJson('/browser/v1/projects/'.$other.'/ba/clarifications/'.$id.'/attempt', $body)->assertNotFound();
        $this->assertSame($counts, $this->baCounts());
    }

    public function test_foreign_tenant_record_is_not_writable_through_authenticated_browser(): void
    {
        $this->ready();
        $this->tenant = (string) Str::uuid();
        $this->user = (string) Str::uuid();
        $this->employee = (string) Str::uuid();
        $this->policy = (string) Str::uuid();
        $this->baselineId = null;
        $this->baInputs();
        $this->baRole();
        $id = $this->openRequest()['clarification_id'];
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        $counts = $this->baCounts();
        $this->postJson('/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt', $body)->assertNotFound();
        $this->assertSame($counts, $this->baCounts());
    }

    public function test_original_producer_revocation_denies_another_actors_replay(): void
    {
        $this->baInputs();
        $this->baRole();
        $producer = $this->otherBaActor();
        $request = app(BaClarificationService::class)->open($producer, $this->p, $this->baEnvelope('request.open', $this->requestInput()), 'producer', 0);
        $id = $request['clarification_id'];
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic context'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $this->loginBrowser();
        $attempt = $this->postJson($path, $body)->assertCreated()->json('data.attempt_id');
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertOk();
        $counts = $this->baCounts();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $producer->id)->where('purpose', 'ba.request.manage')->update(['revoked_at' => now()]));
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertForbidden();
        $slot = app(TenantContext::class)->run($this->tenant, fn () => DB::table('browser_ba_attempts')->where('id', $attempt)->first());
        $this->assertNull($slot->ciphertext);
        $this->assertSame('tombstone', $slot->state);
        $this->assertSame($counts, $this->baCounts());
    }

    private function ready(): array
    {
        $this->baInputs();
        $this->baRole();
        $this->grant('project.read', $this->p);
        $id = $this->openRequest()['clarification_id'];
        $this->loginBrowser();

        return [$id, '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt'];
    }
}
