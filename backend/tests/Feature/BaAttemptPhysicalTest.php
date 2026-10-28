<?php

namespace Tests\Feature;

use Tests\Support\BaAttemptPhysicalTestCase;

class BaAttemptPhysicalTest extends BaAttemptPhysicalTestCase
{
    private ?string $browserCookie = null;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $name = config('session.cookie');
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
    public function test_claim_is_visible_on_an_independent_connection_and_survives_reconnection(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->grant('project.read', $this->p);
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic physical commit context'];
        $this->getJson('/browser/v1/session')->assertOk();
        $this->postJson('/browser/v1/login', ['token' => $this->token])->assertOk();
        $attempt = $this->postJson($path, $body)->assertCreated()->json('data.attempt_id');
        $this->assertNotEmpty($attempt);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::transactionLevel());
        $observer = $this->observer();
        $count = $observer->transaction(function () use ($observer, $attempt): int {
            $observer->select("select set_config('app.tenant_id', ?, true)", [$this->tenant]);
            return $observer->table('browser_ba_attempts')->where('tenant_id', $this->tenant)
                ->where('project_id', $this->p)->where('id', $attempt)->count();
        });
        $this->assertSame(1, $count);
        \Illuminate\Support\Facades\DB::purge('ba_physical_observer');
        $reconnected = $this->observer();
        $count = $reconnected->transaction(function () use ($reconnected, $attempt): int {
            $reconnected->select("select set_config('app.tenant_id', ?, true)", [$this->tenant]);
            return $reconnected->table('browser_ba_attempts')->where('tenant_id', $this->tenant)
                ->where('project_id', $this->p)->where('id', $attempt)->count();
        });
        $this->assertSame(1, $count);
        $this->get($path, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.attempt_id', $attempt);
        $this->postJson($path, array_replace($body, ['context' => 'Different synthetic context']))->assertConflict();
    }
    public function test_committed_context_and_receipt_recover_without_repeating_the_write(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->grant('project.read', $this->p);
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic recoverable context'];
        $this->getJson('/browser/v1/session')->assertOk();
        $this->postJson('/browser/v1/login', ['token' => $this->token])->assertOk();
        $attempt = $this->postJson($path, $body)->assertCreated()->json('data.attempt_id');
        $before = $this->physicalCounts($id);

        // Discard the response body to exercise recovery from persisted state.
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertSuccessful();
        $this->assertSame(0, \Illuminate\Support\Facades\DB::transactionLevel());
        $this->reconnectRuntime();
        $after = $this->physicalCounts($id);
        $this->assertSame($before['events'] + 1, $after['events']);
        $this->assertSame($before['receipts'] + 1, $after['receipts']);
        $this->assertSame($before['context_events'] + 1, $after['context_events']);
        $this->assertSame($before['linked_context_receipts'] + 1, $after['linked_context_receipts']);
        $this->get($path, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.attempt_id', $attempt)->assertJsonPath('data.state', 'completed');
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertSuccessful();
        $this->assertSame($after, $this->physicalCounts($id));
    }

    public function test_stale_execution_closes_attempt_without_receipt_and_allows_corrected_claim(): void
    {
        $this->baInputs();
        $this->baRole();
        $this->grant('project.read', $this->p);
        $id = $this->openRequest()['clarification_id'];
        $path = '/browser/v1/projects/'.$this->p.'/ba/clarifications/'.$id.'/attempt';
        $body = ['clarification_id' => $id, 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'Synthetic stale context'];
        $this->getJson('/browser/v1/session')->assertOk();
        $this->postJson('/browser/v1/login', ['token' => $this->token])->assertOk();
        $attempt = $this->postJson($path, $body)->assertCreated()->json('data.attempt_id');

        // Another authorized committed event advances the clarification after the claim.
        app(\App\Domain\Ba\BaClarificationService::class)->event(
            $this->actor(), $this->p, $id, array_replace($body, ['context' => 'Synthetic concurrent context']), 'physical-other-event'
        );
        $before = $this->physicalCounts($id);
        $this->postJson($path.'/execute', ['attempt_id' => $attempt])->assertConflict();
        $this->reconnectRuntime();
        $this->assertSame($before, $this->physicalCounts($id));
        $observer = $this->observer();
        $closed = $observer->transaction(function () use ($observer, $attempt) {
            $observer->select("select set_config('app.tenant_id', ?, true)", [$this->tenant]);
            return $observer->table('browser_ba_attempts')->where('tenant_id', $this->tenant)->where('id', $attempt)->first(['state', 'ciphertext']);
        });
        $this->assertSame('closed', $closed->state);
        $this->assertNull($closed->ciphertext);
        $corrected = $this->postJson($path, array_replace($body, ['expected_revision' => 2]))->assertCreated()->json('data.attempt_id');
        $this->assertNotSame($attempt, $corrected);
        $this->postJson($path.'/execute', ['attempt_id' => $corrected])->assertOk();
        $after = $this->physicalCounts($id);
        $this->assertSame($before['events'] + 1, $after['events']);
        $this->assertSame($before['receipts'] + 1, $after['receipts']);
        $this->assertSame($before['linked_context_receipts'] + 1, $after['linked_context_receipts']);
    }
    private function physicalCounts(string $clarification): array
    {
        $observer = $this->observer();
        return $observer->transaction(function () use ($observer, $clarification): array {
            $observer->select("select set_config('app.tenant_id', ?, true)", [$this->tenant]);
            return [
                'events' => $observer->table('ba_events')->where('tenant_id', $this->tenant)->where('project_id', $this->p)->where('clarification_id', $clarification)->count(),
                'receipts' => $observer->table('ba_receipts')->where('tenant_id', $this->tenant)->where('project_id', $this->p)->count(),
                'context_events' => $observer->table('ba_events')->where('tenant_id', $this->tenant)->where('project_id', $this->p)->where('clarification_id', $clarification)->where('kind', 'context.add')->count(),
                'linked_context_receipts' => $observer->table('ba_events as e')->join('ba_receipts as r', function ($join): void {
                    $join->on('r.id', '=', 'e.receipt_id')->on('r.tenant_id', '=', 'e.tenant_id')->on('r.project_id', '=', 'e.project_id');
                })->where('e.tenant_id', $this->tenant)->where('e.project_id', $this->p)->where('e.clarification_id', $clarification)->where('e.kind', 'context.add')->count(),
            ];
        });
    }
}
