<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementContract;
use App\Domain\Management\ManagementSupportService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ManagementTestCase;

class ManagementApiTest extends ManagementTestCase
{
    private function startApi(): void
    {
        $this->managementInputs();
        $this->withToken($this->token);
    }

    private function url(string $path): string
    {
        return '/api/v1/projects/'.$this->p.'/management'.$path;
    }

    private function raw(string $path, string $raw, array $headers = [], string $method = 'POST')
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token];
        foreach ($headers as $key => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
        }

        return $this->call($method, $this->url($path), [], [], [], $server, $raw);
    }

    public function test_management_queue_requires_local_authentication(): void
    {
        $this->managementInputs();
        $this->getJson($this->url('/queue'))->assertUnauthorized();
        $this->call('HEAD', $this->url('/queue'))->assertUnauthorized()->assertContent('');
    }

    public function test_source_open_human_event_replay_and_cas_are_separate(): void
    {
        $this->startApi();
        $envelope = $this->managementEnvelope('action.open', $this->actionInput());
        $headers = ['Idempotency-Key' => 'api-open', 'Expected-Source-Revision' => '0'];
        $open = $this->postJson($this->url('/actions'), $envelope, $headers)->assertCreated()->json('data');
        $this->postJson($this->url('/actions'), $envelope, $headers)->assertOk()->assertJsonPath('data.replayed', true);
        $changed = $envelope;
        $changed['body']['reason'] = 'Changed synthetic reason';
        $this->postJson($this->url('/actions'), $changed, $headers)->assertConflict();
        $path = '/actions/'.$open['action_id'].'/events';
        $body = ['operation' => 'context.add', 'expected_revision' => 1, 'context' => 'Synthetic context'];
        $this->postJson($this->url($path), $body, ['Idempotency-Key' => 'context'])->assertCreated();
        $this->postJson($this->url($path), $body, ['Idempotency-Key' => 'context'])->assertOk();
        $this->postJson($this->url($path), $body, ['Idempotency-Key' => 'stale'])->assertConflict();
        $this->get($this->url('/queue'))->assertOk()->assertJsonPath('data.synthetic', true);
        $this->raw('/queue', '', method: 'HEAD')->assertOk()->assertContent('');
        $this->raw('/history/'.Str::uuid(), '', method: 'HEAD')->assertOk()->assertContent('');
    }

    public function test_each_write_route_rejects_wrong_operation_and_closed_body_before_service(): void
    {
        $this->startApi();
        $id = (string) Str::uuid();
        $envelope = $this->managementEnvelope('action.open', $this->actionInput());
        foreach (['/actions/'.$id.'/support', '/actions/'.$id.'/events', '/actions/'.$id.'/reviews'] as $path) {
            $this->postJson($this->url($path), $envelope, ['Idempotency-Key' => 'wrong', 'Expected-Source-Revision' => '0'])->assertUnprocessable();
        }
        foreach (['/actions', '/actions/'.$id.'/support', '/actions/'.$id.'/events', '/actions/'.$id.'/reviews'] as $path) {
            $this->postJson($this->url($path), ['operation' => 'context.add', 'expected_revision' => 1, 'context' => 'Synthetic', 'unknown' => true], ['Idempotency-Key' => 'closed', 'Expected-Source-Revision' => '0'])->assertUnprocessable();
        }
        $this->raw('/actions/not-a-uuid/events', '{}', ['Idempotency-Key' => 'uuid'])->assertNotFound();
        $this->assertSame(0, $this->managementActionCount());
    }

    private function managementActionCount(): int
    {
        return app(TenantContext::class)->run($this->tenant, fn () => DB::table('management_actions')->count());
    }

    public function test_raw_json_bytes_dates_duplicates_secrets_and_types_are_rejected_without_writes(): void
    {
        $this->startApi();
        $envelope = $this->managementEnvelope('action.open', $this->actionInput());
        $raw = ManagementContract::canonical($envelope);
        $cases = ['[]', '{', "{\"x\":\"\xff\"}", '{"operation":"action.open","operat\\u0069on":"action.open"}', str_replace('"reason":"Synthetic escalation"', '"reason":"Synthetic","rea\\u0073on":"Other"', $raw)];
        foreach (['source_revision' => true, 'occurred_at' => '2026-02-30T00:00:00.000000Z', 'source_updated_at' => '2026-10-01T00:00:00Z', 'body' => []] as $field => $value) {
            $cases[] = ManagementContract::canonical(array_replace($envelope, [$field => $value]));
        }
        foreach ([str_repeat('é', 1001), "bad\u{0001}reason", 'password=synthetic-private-password'] as $reason) {
            $bad = $envelope;
            $bad['body']['reason'] = $reason;
            $cases[] = ManagementContract::canonical($bad);
        }
        $cases[] = '{"x":'.str_repeat('[', 17).'0'.str_repeat(']', 17).'}';
        foreach ($cases as $case) {
            $this->raw('/actions', $case, ['Idempotency-Key' => 'invalid', 'Expected-Source-Revision' => '0'])->assertUnprocessable();
        }
        $this->raw('/actions', str_repeat(' ', 65537), ['Idempotency-Key' => 'large', 'Expected-Source-Revision' => '0'])->assertStatus(413);
        $this->assertSame(0, $this->managementActionCount());
        $envelope['body']['reason'] = str_repeat('é', 1000);
        $this->postJson($this->url('/actions'), $envelope, ['Idempotency-Key' => 'byte-boundary', 'Expected-Source-Revision' => '0'])->assertCreated();
    }

    public function test_controls_are_single_exact_applicable_headers_and_queries_are_closed(): void
    {
        $this->startApi();
        $raw = ManagementContract::canonical($this->managementEnvelope('action.open', $this->actionInput()));
        foreach (['', ' 0', '+0', '00', '0,0', '9007199254740992', ['0', '0']] as $revision) {
            $this->raw('/actions', $raw, ['Idempotency-Key' => 'headers', 'Expected-Source-Revision' => $revision])->assertUnprocessable();
        }
        foreach (['', 'a b', 'a,b', ['first', 'second'], str_repeat('a', 101)] as $key) {
            $this->raw('/actions', $raw, ['Idempotency-Key' => $key, 'Expected-Source-Revision' => '0'])->assertUnprocessable();
        }
        foreach (['Expected-Revision', 'Expected-Series-Revision', 'Expected-Project-Generation'] as $header) {
            $this->raw('/actions', $raw, ['Idempotency-Key' => 'headers', 'Expected-Source-Revision' => '0', $header => '0'])->assertUnprocessable();
        }
        $this->raw('/actions?limit=1', $raw, ['Idempotency-Key' => 'headers', 'Expected-Source-Revision' => '0'])->assertUnprocessable();
        foreach (['?department=x', '?department_id=x', '?limit=0', '?limit=51', '?limit[]=1', '?cursor=x', '?unknown=x'] as $query) {
            $this->getJson($this->url('/queue'.$query))->assertUnprocessable();
            $this->raw('/queue'.$query, '', method: 'HEAD')->assertUnprocessable()->assertContent('');
        }
        foreach (['Idempotency-Key', 'Expected-Source-Revision', 'Expected-Revision', 'Expected-Series-Revision', 'Expected-Project-Generation'] as $header) {
            $this->raw('/queue', '', [$header => '0'], 'GET')->assertUnprocessable();
        }
        $this->raw('/queue', '{}', method: 'GET')->assertUnprocessable();
        $this->assertSame(0, $this->managementActionCount());
    }

    public function test_conservative_joint_bound_returns_503_without_writing_a_review_or_heads(): void
    {
        $this->startApi();
        $ba = $this->managementBaReviewedFixture();
        $reference = array_replace($this->lifecycleReference(), ['kind' => 'ba_review', 'ba_review_id' => $ba['review']['review_id']]);
        $fixture = $this->workflowAssigned([$reference], false);
        $body = $this->workflowSupportBody($fixture);
        $body['references'] = [$reference];
        $support = app(ManagementSupportService::class)->record($fixture['helper'], $this->p, $fixture['open']['action_id'], $this->managementEnvelope('support.record', $body, 'bounded-support'), 'bounded-support', 0);
        $this->grant('management.outcome.review', $this->p);
        $snapshot = fn () => app(TenantContext::class)->run($this->tenant, function () {
            $rows = [];
            foreach (['management_actions', 'management_events', 'management_reviews', 'management_receipts', 'management_request_bindings', 'management_source_heads', 'management_project_state', 'audit_events'] as $table) {
                $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
            }

            return $rows;
        });
        $before = $snapshot();
        $this->postJson($this->url('/actions/'.$fixture['open']['action_id'].'/reviews'), $this->workflowReviewBody($support), ['Idempotency-Key' => 'bounded-review'])->assertStatus(503);
        $this->assertEquals($before, $snapshot());
    }
}
