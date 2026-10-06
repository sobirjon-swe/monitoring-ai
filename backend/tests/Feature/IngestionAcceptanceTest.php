<?php

namespace Tests\Feature;

use App\Domain\Access\TrustedActor;
use App\Domain\Ingestion\IngestionService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessTestCase;

class IngestionAcceptanceTest extends AccessTestCase
{
    private string $connector;

    private string $projectId;

    private string $sourceId;

    private function setupConnector(bool $grant = true, string $status = 'active'): void
    {
        $this->fixture();
        $this->projectId = $this->project();
        $this->sourceId = (string) Str::uuid();
        $this->connector = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($status): void {
            DB::table('source_instances')->insert(['id' => $this->sourceId, 'tenant_id' => $this->tenant, 'kind' => 'manual', 'label' => 'Synthetic source']);
            DB::table('ingestion_connectors')->insert(['id' => $this->connector, 'tenant_id' => $this->tenant, 'project_id' => $this->projectId, 'source_instance_id' => $this->sourceId, 'status' => $status]);
        });
        if ($grant) {
            $this->grant('ingestion.accept', $this->projectId);
        }
        $this->withToken($this->token);
    }

    private function envelope(): array
    {
        return ['version' => 'ingestion-v1', 'source_object_id' => 'ticket-1', 'source_version' => 'v1', 'event_kind' => 'ticket.snapshot', 'occurred_at' => '2026-10-05T12:00:00.123456+05:00', 'source_updated_at' => null, 'payload' => ['external_project_id' => 'project-1', 'external_ticket_id' => 'ticket-1', 'title' => 'Synthetic ticket', 'status' => 'open', 'external_actor_id' => null, 'due_at' => null]];
    }

    private function url(): string
    {
        return '/api/v1/connectors/'.$this->connector.'/receipts';
    }

    private function counts(int $expected): void
    {
        app(TenantContext::class)->run($this->tenant, function () use ($expected): void {
            $this->assertSame($expected, DB::table('ingestion_receipts')->count());
            $this->assertSame($expected, DB::table('ingestion_outbox')->count());
        });
    }

    public function test_replay_and_equivalent_key_order_preserve_first_bytes_and_single_outbox(): void
    {
        $this->setupConnector();
        $envelope = $this->envelope();
        $raw = json_encode($envelope, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $first = $this->call('POST', $this->url(), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], $raw)->assertCreated();
        for ($i = 0; $i < 3; $i++) {
            $reordered = array_reverse($envelope, true);
            $reordered['payload'] = array_reverse($envelope['payload'], true);
            $this->postJson($this->url(), $reordered)->assertOk()->assertJsonPath('data.receipt_id', $first->json('data.receipt_id'))->assertJsonPath('data.duplicate', true);
        }
        $this->counts(1);
        app(TenantContext::class)->run($this->tenant, function () use ($raw): void {
            $receipt = DB::table('ingestion_receipts')->first();
            $this->assertSame($raw, $receipt->payload_bytes);
            $this->assertSame(hash('sha256', $raw), $receipt->transport_hash);
            $this->assertSame($this->user, $receipt->accepted_by);
            $this->assertStringContainsString('07:00:00.123456', $receipt->occurred_at);
        });
    }

    public function test_conflicting_same_version_preserves_original_receipt(): void
    {
        $this->setupConnector();
        $this->postJson($this->url(), $this->envelope())->assertCreated();
        $changed = $this->envelope();
        $changed['payload']['title'] = 'Changed';
        $this->postJson($this->url(), $changed)->assertConflict()->assertDontSee('Changed');
        $this->counts(1);
    }

    public function test_invalid_fields_types_dates_and_forged_scope_write_nothing(): void
    {
        $this->setupConnector();
        $cases = [];
        $e = $this->envelope();
        unset($e['source_version']);
        $cases[] = $e;
        foreach (['', '   ', 123, null] as $value) {
            $e = $this->envelope();
            $e['source_object_id'] = $value;
            $cases[] = $e;
        }
        foreach (['2026-10-05T12:00:00', '2026-02-30T12:00:00Z', 'tomorrow'] as $value) {
            $e = $this->envelope();
            $e['occurred_at'] = $value;
            $cases[] = $e;
        }
        foreach (['tenant_id', 'project_id', 'metadata'] as $key) {
            $e = $this->envelope();
            $e[$key] = 'forged';
            $cases[] = $e;
        }
        foreach (['title' => str_repeat('a', 501), 'status' => 2, 'due_at' => '2026-10-05', 'external_actor_id' => false, 'metadata' => []] as $key => $value) {
            $e = $this->envelope();
            $e['payload'][$key] = $value;
            $cases[] = $e;
        }
        foreach ($cases as $case) {
            $this->postJson($this->url(), $case)->assertUnprocessable();
        }
        $this->counts(0);
    }

    public function test_oversized_transport_rejected_before_write(): void
    {
        $this->setupConnector();
        $raw = str_repeat(' ', 65536).json_encode($this->envelope());
        $this->call('POST', $this->url(), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], $raw)->assertStatus(413);
        $this->counts(0);
    }

    public function test_disabled_connector_and_missing_grant_are_denied(): void
    {
        $this->setupConnector(false);
        $this->postJson($this->url(), $this->envelope())->assertForbidden();
        $this->grant('ingestion.accept', $this->projectId);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_connectors')->where('id', $this->connector)->update(['status' => 'disabled', 'revision' => 2]));
        $this->postJson($this->url(), $this->envelope())->assertConflict();
        $this->counts(0);
    }

    public function test_unauthenticated_unknown_and_cross_project_connectors_are_denied(): void
    {
        $this->postJson('/api/v1/connectors/'.Str::uuid().'/receipts', [])->assertUnauthorized();
        $this->setupConnector(false);
        $this->grant('ingestion.accept', $this->project());
        $this->postJson($this->url(), $this->envelope())->assertForbidden();
        $this->postJson('/api/v1/connectors/'.Str::uuid().'/receipts', $this->envelope())->assertNotFound();
        $this->counts(0);
    }

    public function test_replay_rechecks_revoked_membership(): void
    {
        $this->setupConnector();
        $this->postJson($this->url(), $this->envelope())->assertCreated();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('project_id', $this->projectId)->update(['valid_to' => now()]));
        $this->postJson($this->url(), $this->envelope())->assertForbidden();
        $this->counts(1);
    }

    public function test_different_versions_cannot_rebind_source_object_to_another_project(): void
    {
        $this->setupConnector();
        $this->postJson($this->url(), $this->envelope())->assertCreated();
        $other = $this->project();
        $connector = (string) Str::uuid();
        $this->grant('ingestion.accept', $other);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_connectors')->insert(['id' => $connector, 'tenant_id' => $this->tenant, 'project_id' => $other, 'source_instance_id' => $this->sourceId, 'status' => 'active']));
        $e = $this->envelope();
        $e['source_version'] = 'v2';
        $this->postJson('/api/v1/connectors/'.$connector.'/receipts', $e)->assertConflict();
        $this->counts(1);
    }

    public function test_transaction_failure_rolls_back_receipt_and_outbox(): void
    {
        $this->setupConnector();
        // Inject failure after the real outbox INSERT to exercise rollback of both real writes.
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'insert into "ingestion_outbox"')) {
                throw new \RuntimeException('Synthetic failure');
            }
        });
        $this->postJson($this->url(), $this->envelope())->assertStatus(500)->assertDontSee('Synthetic failure');
        $this->counts(0);
    }

    public function test_malformed_ambiguous_and_wrong_json_container_types_are_rejected(): void
    {
        $this->setupConnector();
        $valid = json_encode($this->envelope(), JSON_THROW_ON_ERROR);
        $cases = ['{', 'null', '[]', str_replace('"payload":{', '"payload":[', $valid), str_replace('"payload":{', '"payload":{"title":"hidden",', $valid), str_replace('"payload":{', '"payload":{"ti\\u0074le":"hidden",', $valid)];
        foreach ($cases as $raw) {
            $this->call('POST', $this->url(), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], $raw)->assertUnprocessable()->assertDontSee('hidden');
        }
        $e = $this->envelope();
        $e['payload'] = [];
        $this->postJson($this->url(), $e)->assertUnprocessable();
        $this->counts(0);
    }

    public function test_nul_in_envelope_identifier_is_rejected_before_database_write(): void
    {
        $this->setupConnector();
        $e = $this->envelope();
        $e['source_object_id'] = 'ticket'.chr(0).'1';
        $this->postJson($this->url(), $e)->assertUnprocessable();
        $this->counts(0);
    }

    public function test_nul_in_payload_title_is_rejected_before_database_write(): void
    {
        $this->setupConnector();
        $e = $this->envelope();
        $e['payload']['title'] = 'Synthetic'.chr(0).'ticket';
        $this->postJson($this->url(), $e)->assertUnprocessable();
        $this->counts(0);
    }

    public function test_direct_service_rejects_mismatched_original_bytes(): void
    {
        $this->setupConnector();
        $changed = $this->envelope();
        $changed['payload']['title'] = 'Different transport';
        try {
            app(IngestionService::class)->accept(new TrustedActor($this->user, $this->tenant), $this->connector, $this->envelope(), json_encode($changed, JSON_THROW_ON_ERROR));
            $this->fail('Mismatched transport accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->counts(0);
    }

    public function test_other_session_object_lock_serializes_acceptance_across_versions(): void
    {
        $this->setupConnector();
        config(['database.connections.ingestion_contender' => config('database.connections.pgsql')]);
        $other = DB::connection('ingestion_contender');
        $this->assertSame('monitoring_ai_test', $other->scalar('select current_database()'));
        $this->assertSame('monitoring_ai_app', $other->scalar('select current_user'));
        $other->beginTransaction();
        try {
            // Independent connection emulates an in-flight acceptance of the same source object.
            $key = json_encode([$this->tenant, $this->sourceId, 'ticket', 'ticket-1'], JSON_THROW_ON_ERROR);
            $other->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
            foreach (['v1', 'v2'] as $version) {
                $e = $this->envelope();
                $e['source_version'] = $version;
                try {
                    app(TenantContext::class)->run($this->tenant, function () use ($e): void {
                        DB::statement("SET LOCAL lock_timeout='100ms'");
                        app(IngestionService::class)->accept(new TrustedActor($this->user, $this->tenant), $this->connector, $e);
                    });
                    $this->fail('Concurrent source object lock was bypassed.');
                } catch (QueryException $exception) {
                    $this->assertSame('55P03', $exception->errorInfo[0]);
                }
            }
            $this->counts(0);
        } finally {
            $other->rollBack();
            DB::purge('ingestion_contender');
        }
        $this->postJson($this->url(), $this->envelope())->assertCreated();
        $this->counts(1);
    }
}
