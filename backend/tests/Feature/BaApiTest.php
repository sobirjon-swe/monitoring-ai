<?php

namespace Tests\Feature;

use App\Domain\Ba\BaContract;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BaTestCase;

class BaApiTest extends BaTestCase
{
    private function startApi(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->withToken($this->token);
    }

    private function url(string $path): string
    {
        return '/api/v1/projects/'.$this->p.'/ba'.$path;
    }

    private function source(string $path, array $envelope, string $key = 'api-source', array $controls = [])
    {
        return $this->postJson($this->url($path), $envelope, ['Idempotency-Key' => $key, 'Expected-Source-Revision' => '0'] + $controls);
    }

    private function raw(string $path, string $raw, array $controls = [], string $method = 'POST')
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token];
        foreach ($controls as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $this->url($path), [], [], [], $server, $raw);
    }

    public function test_source_open_answer_human_context_replay_and_cas(): void
    {
        $this->startApi();
        $envelope = $this->baEnvelope('request.open', $this->requestInput(), 'api-question');
        $opened = $this->source('/clarifications', $envelope)->assertCreated()->json('data');
        $id = $opened['clarification_id'];
        $counts = $this->baCounts();
        $this->postJson($this->url('/clarifications'), $envelope, ['Idempotency-Key' => 'api-source', 'Expected-Source-Revision' => '999'])->assertOk()->assertJsonPath('data.clarification_id', $id)->assertJsonPath('data.replayed', true);
        $this->assertSame($counts, $this->baCounts());
        $answer = $this->baEnvelope('answer.record', $this->answerInput($opened), 'api-answer');
        $path = '/clarifications/'.$id.'/answers';
        $this->source($path, $answer, 'answer')->assertCreated();
        $counts = $this->baCounts();
        $this->source($path, $answer, 'answer')->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($counts, $this->baCounts());
        $context = ['kind' => 'context.add', 'clarification_id' => $id, 'expected_revision' => 2, 'context' => 'Synthetic follow-up'];
        $path = '/clarifications/'.$id.'/events';
        $this->postJson($this->url($path), $context, ['Idempotency-Key' => 'context'])->assertCreated();
        $this->postJson($this->url($path), $context, ['Idempotency-Key' => 'context'])->assertOk();
        $this->postJson($this->url($path), $context, ['Idempotency-Key' => 'context-stale'])->assertConflict();
        $this->get($this->url('/queue'))->assertOk()->assertJsonPath('data.synthetic', true)->assertJsonPath('data.ai_interpretation', 'unavailable')->assertJsonPath('data.customer_authenticity', 'not_verified');
        $this->get($this->url('/history/'.$id))->assertOk()->assertJsonCount(3, 'data.items');
    }

    public function test_strict_json_shapes_duplicates_types_secrets_and_bytes_do_not_write(): void
    {
        $this->startApi();
        $envelope = $this->baEnvelope('request.open', $this->requestInput());
        $json = BaContract::canonical($envelope);
        $bad = [str_replace('"source_revision":1', '"source_revision":1,"source_revisio\\u006e":2', $json), str_replace('"source_revision":1', '"source_revision":true', $json), BaContract::canonical($envelope + ['actor_id' => $this->user]), BaContract::canonical(array_replace($envelope, ['body' => []])), BaContract::canonical(array_replace($envelope, ['body' => new \stdClass])), str_replace('Synthetic CRM', 'password=synthetic-secret-value Synthetic CRM', $json), str_replace('Synthetic CRM', '\\u0001Synthetic CRM', $json), '{"a":'.str_repeat('{"a":', 16).'{}'.str_repeat('}', 16).'}', "{\"a\":\"\xff\"}"];
        $counts = $this->baCounts();
        foreach ($bad as $raw) {
            $this->raw('/clarifications', $raw, ['Idempotency-Key' => 'raw', 'Expected-Source-Revision' => '0'])->assertUnprocessable();
            $this->assertSame($counts, $this->baCounts());
        }
        $this->raw('/clarifications', str_repeat(' ', 65537), ['Idempotency-Key' => 'raw', 'Expected-Source-Revision' => '0'])->assertStatus(413);
        $this->assertSame($counts, $this->baCounts());
    }

    public function test_headers_query_and_bodyless_reads_are_strict(): void
    {
        $this->startApi();
        $envelope = $this->baEnvelope('request.open', $this->requestInput());
        $counts = $this->baCounts();
        foreach ([[], ['Idempotency-Key' => 'key'], ['Idempotency-Key' => 'key', 'Expected-Source-Revision' => '01'], ['Idempotency-Key' => 'bad/key', 'Expected-Source-Revision' => '0'], ['Idempotency-Key' => 'key', 'Expected-Source-Revision' => '9007199254740992']] as $controls) {
            $this->raw('/clarifications', BaContract::canonical($envelope), $controls)->assertUnprocessable();
        }
        foreach (['Expected-Series-Revision', 'Expected-Project-Generation', 'Expected-Revision'] as $header) {
            $this->source('/clarifications', $envelope, controls: [$header => '0'])->assertUnprocessable();
        }
        foreach (['Idempotency-Key', 'Expected-Source-Revision', 'Expected-Series-Revision', 'Expected-Project-Generation', 'Expected-Revision'] as $header) {
            $this->get($this->url('/queue'), [$header => '0'])->assertUnprocessable();
        }
        foreach (['limit=51', 'limit=0', 'limit=01', 'limit[]=1', 'department=invalid', 'cursor[]=x', 'extra=1'] as $query) {
            $this->get($this->url('/queue?'.$query))->assertUnprocessable();
        }
        foreach (['GET', 'HEAD'] as $method) {
            $this->raw('/queue', '{}', method: $method)->assertUnprocessable();
            $this->raw('/queue', '', method: $method)->assertOk();
        }
        $this->assertSame($counts, $this->baCounts());
    }

    public function test_authentication_scoped_authority_uuid_and_head(): void
    {
        $this->startApi();
        $this->withToken('invalid');
        $this->get($this->url('/queue'))->assertUnauthorized();
        $this->withToken($this->token);
        $this->get('/api/v1/projects/not-uuid/ba/queue')->assertNotFound();
        $this->get($this->url('/history/'.Str::uuid()))->assertOk()->assertJsonCount(0, 'data.items');
        $this->get($this->url('/history/not-uuid'))->assertNotFound();
        $this->raw('/queue', '', method: 'HEAD')->assertOk()->assertContent('');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'ba.read')->update(['revoked_at' => now()]));
        $this->get($this->url('/queue'))->assertForbidden();
        $this->raw('/queue', '', method: 'HEAD')->assertForbidden()->assertContent('');
    }

    public function test_local_unavailable_is_generic_and_does_not_write(): void
    {
        $this->startApi();
        $envelope = $this->baEnvelope('request.open', $this->requestInput());
        $counts = $this->baCounts();
        config(['database.connections.pgsql.port' => '55433']);
        $this->source('/clarifications', $envelope)->assertStatus(503)->assertDontSee('55433');
        $this->get($this->url('/queue'))->assertStatus(503);
        config(['database.connections.pgsql.port' => '55432']);
        $this->assertSame($counts, $this->baCounts());
    }

    public function test_unreachable_runtime_guard_is_service_unavailable_without_secret_output(): void
    {
        $this->startApi();
        $database = DB::getFacadeRoot();
        $counts = $this->baCounts();
        try {
            // A real cluster stop would disrupt the shared authorized local substrate.
            $proxy = \Mockery::mock($database);
            $proxy->shouldReceive('selectOne')->once()->andThrow(new \PDOException('Synthetic private endpoint unavailable'));
            DB::swap($proxy);
            $this->get($this->url('/queue'))->assertStatus(503)->assertDontSee('Synthetic private endpoint');
        } finally {
            DB::swap($database);
        }
        $this->assertSame($counts, $this->baCounts());
    }

    public function test_pm_review_is_idempotent_distinct_and_rejects_source_controls(): void
    {
        $this->startApi();
        $request = $this->openRequest();
        $answer = $this->recordAnswer($request);
        $body = $this->reviewInput($request, $answer);
        $path = '/clarifications/'.$request['clarification_id'].'/reviews';
        $counts = $this->baCounts();
        $this->postJson($this->url($path), $body, ['Idempotency-Key' => 'review'])->assertForbidden();
        $pm = $this->pmActor();
        $pmToken = app(TenantContext::class)->run($this->tenant, function () use ($pm) {
            $subject = (string) Str::uuid();
            $id = (string) Str::uuid();
            $secret = bin2hex(random_bytes(32));
            DB::table('auth_subjects')->insert(['id' => $subject, 'tenant_id' => $this->tenant, 'user_id' => $pm->id, 'issuer' => 'local-development', 'subject' => $pm->id]);
            DB::table('auth_tokens')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'auth_subject_id' => $subject, 'secret_hash' => hash('sha256', $secret), 'expires_at' => now()->addHour()]);

            return $this->tenant.'.'.$id.'.'.$secret;
        });
        $this->withToken($pmToken);
        foreach (['Expected-Source-Revision', 'Expected-Series-Revision', 'Expected-Project-Generation', 'Expected-Revision'] as $control) {
            $this->postJson($this->url($path), $body, ['Idempotency-Key' => 'review', $control => '0'])->assertUnprocessable();
        }
        $this->assertSame($counts, $this->baCounts());
        $this->postJson($this->url($path), $body, ['Idempotency-Key' => 'review'])->assertCreated();
        $this->postJson($this->url($path), $body, ['Idempotency-Key' => 'review'])->assertOk();
        $this->postJson($this->url($path), $body, ['Idempotency-Key' => 'review-stale'])->assertConflict();
        $this->get($this->url('/queue'))->assertOk()->assertJsonPath('data.items.0.clarification_state', 'resolved');
    }

    public function test_cursor_continuation_scope_and_revocation(): void
    {
        $this->startApi();
        $this->openRequest(object: 'a-question');
        $this->openRequest(object: 'b-question');
        $cursor = $this->get($this->url('/queue?limit=1'))->assertOk()->assertJsonCount(1, 'data.items')->json('data.next_cursor');
        $this->assertIsString($cursor);
        $this->get($this->url('/queue?limit=1&cursor='.urlencode($cursor)))->assertOk()->assertJsonCount(1, 'data.items');
        $this->get($this->url('/queue?limit=2&cursor='.urlencode($cursor)))->assertUnprocessable();
        $this->get($this->url('/queue?department='.Str::uuid()))->assertForbidden();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'ba.source.read')->update(['revoked_at' => now()]));
        $this->get($this->url('/queue?limit=1&cursor='.urlencode($cursor)))->assertForbidden();
    }

    public function test_source_cas_body_cas_exact_route_and_text_limits(): void
    {
        $this->startApi();
        $input = $this->requestInput();
        $envelope = $this->baEnvelope('request.open', $input, 'cas-request');
        $this->postJson($this->url('/clarifications'), $envelope, ['Idempotency-Key' => 'wrong-source-head', 'Expected-Source-Revision' => '1'])->assertConflict();
        $request = $this->source('/clarifications', $envelope)->assertCreated()->json('data');
        $id = $request['clarification_id'];
        $answer = $this->baEnvelope('answer.record', $this->answerInput($request, 99), 'cas-answer');
        $counts = $this->baCounts();
        $this->source('/clarifications/'.$id.'/answers', $answer, 'stale-body')->assertConflict();
        $answer['body']['expected_revision'] = 1;
        $this->source('/clarifications/'.Str::uuid().'/answers', $answer, 'wrong-route')->assertUnprocessable();
        $this->source('/clarifications/'.$id.'/answers', $envelope, 'wrong-operation')->assertUnprocessable();
        foreach ([str_repeat('é', 2000).'x', '  ', 'password=synthetic-secret-value'] as $question) {
            $bad = $envelope;
            $bad['body']['question'] = $question;
            $this->source('/clarifications', $bad, 'bad-question')->assertUnprocessable()->assertDontSee($question);
        }
        $this->assertSame($counts, $this->baCounts());
        $missing = ['clarification_id' => (string) Str::uuid(), 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic'];
        $this->postJson($this->url('/clarifications/'.$missing['clarification_id'].'/events'), $missing, ['Idempotency-Key' => 'missing'])->assertNotFound();
        $context = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => str_repeat('é', 2000)];
        $this->postJson($this->url('/clarifications/'.$id.'/events'), $context, ['Idempotency-Key' => 'limit-context'])->assertCreated();
    }

    public function test_cli_guards_authorized_registration_replay_and_token_file_cleanup(): void
    {
        $this->startApi();
        $token = tempnam(sys_get_temp_dir(), 'ba-token-');
        $fixture = tempnam(sys_get_temp_dir(), 'ba-party-');
        try {
            file_put_contents($token, $this->token);
            file_put_contents($fixture, BaContract::canonical($this->baEnvelope(object: 'cli-party')));
            $args = ['project' => $this->p, 'fixture' => $fixture, '--token-file' => $token, '--expected-source-revision' => '0', '--idempotency-key' => 'cli-party'];
            $this->assertSame(0, Artisan::call('app:register-local-ba-party', $args));
            $this->assertStringNotContainsString($this->token, Artisan::output());
            $counts = $this->baCounts();
            $this->assertSame(0, Artisan::call('app:register-local-ba-party', $args));
            $this->assertStringContainsString('replayed=1', Artisan::output());
            $this->assertSame($counts, $this->baCounts());
            $grants = app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->count());
            $dbConfig = config('database.connections.pgsql');
            foreach (['port' => '5432', 'host' => 'example.invalid', 'database' => 'other', 'url' => 'postgres://invalid', 'read' => ['host' => 'localhost'], 'write' => ['host' => 'localhost'], 'options' => [1], 'search_path' => 'other'] as $key => $value) {
                config(['database.connections.pgsql' => array_replace($dbConfig, [$key => $value])]);
                $this->assertSame(1, Artisan::call('app:register-local-ba-party', $args));
            }
            config(['database.connections.pgsql' => $dbConfig]);
            foreach (['production', 'testing'] as $environment) {
                app()->instance('env', $environment);
                if ($environment === 'testing') {
                    config(['database.connections.pgsql.username' => 'monitoring_ai_owner']);
                }
                $this->assertSame(1, Artisan::call('app:register-local-ba-party', $args));
                $this->assertStringNotContainsString($this->token, Artisan::output());
            }
            app()->instance('env', 'testing');
            config(['database.connections.pgsql.username' => 'monitoring_ai_app']);
            $this->assertSame($counts, $this->baCounts());
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'ba.fixture.manage')->update(['revoked_at' => now()]));
            $this->assertSame(1, Artisan::call('app:register-local-ba-party', $args));
            $this->assertSame($counts, $this->baCounts());
            $this->assertSame($grants, app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->count()));
        } finally {
            app()->instance('env', 'testing');
            config(['database.connections.pgsql.username' => 'monitoring_ai_app']);
            unlink($token);
            unlink($fixture);
        }
        $this->assertFileDoesNotExist($token);
        $this->assertFileDoesNotExist($fixture);
    }
}
