<?php

namespace Tests\Feature;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\Ingestion\IngestionService;
use App\Domain\Ingestion\TicketSnapshotProjector;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AccessTestCase;

class ConnectorAccessTest extends AccessTestCase
{
    public function test_unauthenticated_connector_request_is_denied(): void
    {
        $this->postJson('/api/v1/projects/'.Str::uuid().'/connectors', [])->assertUnauthorized();
    }

    public function test_connector_management_requires_explicit_grant(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('project.read', $project);
        $this->withToken($this->token)->postJson($this->url($project), $this->payload($this->source()))->assertForbidden();
    }

    public function test_connector_grant_does_not_grant_project_report_access(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('connector.manage', $project);
        $this->withToken($this->token)->postJson($this->url($project), $this->payload($this->source()))->assertCreated();
        $this->getJson('/api/v1/projects/'.$project)->assertForbidden();
        foreach (['ingestion.accept', 'ingestion.read', 'ingestion.process'] as $purpose) {
            try {
                app(AccessGateway::class)->authorize(new TrustedActor($this->user, $this->tenant), $purpose, ['tenant_id' => $this->tenant, 'project_id' => $project]);
                $this->fail('Connector management widened ingestion permissions.');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_revoked_grant_denies_connector_status(): void
    {
        $this->fixture();
        $project = $this->project();
        $grant = $this->grant('connector.manage', $project);
        $id = $this->withToken($this->token)->postJson($this->url($project), $this->payload($this->source()))->assertCreated()->json('data.id');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
        $this->getJson('/api/v1/connectors/'.$id)->assertForbidden();
    }

    public function test_revoked_membership_denies_connector_status(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('connector.manage', $project);
        $id = $this->withToken($this->token)->postJson($this->url($project), $this->payload($this->source()))->assertCreated()->json('data.id');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('project_id', $project)->update(['valid_to' => now()]));
        $this->getJson('/api/v1/connectors/'.$id)->assertForbidden();
    }

    public function test_unknown_adapter_and_nonmanual_source_are_rejected(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('connector.manage', $project);
        $this->withToken($this->token)->postJson($this->url($project), ['adapter' => 'remote-v1'] + $this->payload($this->source()))->assertUnprocessable();
        $this->postJson($this->url($project), $this->payload($this->source('gitlab')))->assertUnprocessable();
    }

    public function test_client_scope_cannot_override_trusted_binding(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('connector.manage', $project);
        $result = $this->withToken($this->token)->postJson($this->url($project), $this->payload($this->source()) + ['tenant_id' => (string) Str::uuid(), 'project_id' => (string) Str::uuid(), 'actor_id' => (string) Str::uuid()])->assertCreated();
        app(TenantContext::class)->run($this->tenant, function () use ($result, $project): void {
            $connector = DB::table('ingestion_connectors')->where('id', $result->json('data.id'))->first();
            $this->assertSame($this->tenant, $connector->tenant_id);
            $this->assertSame($project, $connector->project_id);
            $audit = DB::table('audit_events')->where('resource_id', $connector->id)->first();
            $this->assertNotNull($audit);
            $this->assertSame($this->user, $audit->actor_id);
            $this->assertSame($project, $audit->project_id);
            $this->assertSame('connector.created', $audit->action);
            $this->assertSame('ingestion_connectors', $audit->resource_type);
        });
    }

    public function test_cross_tenant_source_reference_is_rejected(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('connector.manage', $project);
        $otherTenant = (string) Str::uuid();
        $source = (string) Str::uuid();
        app(TenantContext::class)->run($otherTenant, function () use ($otherTenant, $source): void {
            DB::table('tenants')->insert(['id' => $otherTenant, 'name' => 'Synthetic other tenant']);
            DB::table('source_instances')->insert(['id' => $source, 'tenant_id' => $otherTenant, 'kind' => 'manual', 'label' => 'Synthetic private source']);
        });
        $this->withToken($this->token)->postJson($this->url($project), $this->payload($source))->assertNotFound()->assertDontSee('Synthetic private source');
    }

    public function test_project_grant_cannot_create_connector_for_another_project(): void
    {
        $this->fixture();
        $project = $this->project();
        $otherProject = $this->project();
        $this->grant('connector.manage', $project);
        $this->withToken($this->token)->postJson($this->url($otherProject), $this->payload($this->source()))->assertForbidden();
    }

    public function test_binding_is_unique_and_head_cannot_create_connector(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('connector.manage', $project);
        $payload = $this->payload($this->source());
        $this->withToken($this->token)->postJson($this->url($project), $payload)->assertCreated();
        $this->postJson($this->url($project), $payload)->assertConflict();
        $this->json('HEAD', $this->url($project), $this->payload($this->source()))->assertOk();
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(1, DB::table('ingestion_connectors')->count()));
    }

    public function test_missing_tenant_context_denies_connector_reads_and_writes(): void
    {
        $this->fixture();
        $project = $this->project();
        $source = $this->source();
        $this->assertSame(0, DB::table('ingestion_connectors')->count());
        try {
            DB::transaction(fn () => DB::table('ingestion_connectors')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $project, 'source_instance_id' => $source, 'adapter' => 'synthetic-v1', 'status' => 'active']));
            $this->fail('Missing tenant context accepted connector write.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->errorInfo[0]);
        }
    }

    public function test_ingestion_purposes_require_project_even_with_tenant_grant(): void
    {
        $this->fixture();
        foreach (['connector.manage', 'ingestion.accept', 'ingestion.read', 'ingestion.process'] as $purpose) {
            $this->grant($purpose);
            try {
                app(AccessGateway::class)->authorize(new TrustedActor($this->user, $this->tenant), $purpose, ['tenant_id' => $this->tenant]);
                $this->fail('Projectless ingestion access accepted.');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function source(string $kind = 'manual'): string
    {
        return app(TenantContext::class)->run($this->tenant, function () use ($kind): string {
            $id = (string) Str::uuid();
            DB::table('source_instances')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'kind' => $kind, 'label' => 'Synthetic source']);

            return $id;
        });
    }

    public function test_database_rejects_receipt_for_wrong_project_and_receipt_mutation(): void
    {
        $this->fixture();
        $project = $this->project();
        $otherProject = $this->project();
        $source = $this->source();
        $this->grant('connector.manage', $project);
        $connector = $this->withToken($this->token)->postJson($this->url($project), $this->payload($source))->assertCreated()->json('data.id');
        app(TenantContext::class)->run($this->tenant, function () use ($project, $otherProject, $source, $connector): void {
            $receipt = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $project, 'connector_id' => $connector, 'source_instance_id' => $source, 'source_object_type' => 'ticket', 'accepted_by' => $this->user, 'source_object_id' => 'synthetic-1', 'source_version' => 'v1', 'event_kind' => 'ticket.snapshot', 'payload_bytes' => '{}', 'payload_json' => '{}', 'transport_hash' => hash('sha256', '{}'), 'canonical_hash' => hash('sha256', '{}'), 'occurred_at' => now(), 'received_at' => now()];
            $this->sqlState('23503', fn () => DB::table('ingestion_receipts')->insert(['project_id' => $otherProject] + $receipt));
            DB::table('ingestion_receipts')->insert($receipt);
            $this->assertSame($this->user, DB::table('ingestion_receipts')->where('id', $receipt['id'])->value('accepted_by'));
            $this->sqlState('42501', fn () => DB::table('ingestion_receipts')->where('id', $receipt['id'])->update(['accepted_by' => (string) Str::uuid()]));
            $this->sqlState('42501', fn () => DB::table('ingestion_receipts')->where('id', $receipt['id'])->update(['payload_json' => '{"changed":true}']));
            $this->sqlState('42501', fn () => DB::table('ingestion_receipts')->where('id', $receipt['id'])->delete());
            $this->sqlState('23505', fn () => DB::table('ingestion_receipts')->insert(['id' => (string) Str::uuid()] + $receipt));
            $this->sqlState('23514', fn () => DB::table('ingestion_receipts')->insert(['id' => (string) Str::uuid(), 'source_version' => 'v2', 'transport_hash' => 'not-a-hash'] + $receipt));
            $this->sqlState('23514', fn () => DB::table('ingestion_receipts')->insert(['id' => (string) Str::uuid(), 'source_version' => 'v2', 'payload_bytes' => str_repeat('a', 65537)] + $receipt));
            $this->sqlState('23514', fn () => DB::table('ingestion_outbox')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $project, 'receipt_id' => $receipt['id'], 'status' => 'processing', 'available_at' => now()]));
            $this->sqlState('23503', fn () => DB::table('ingestion_outbox')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $otherProject, 'receipt_id' => $receipt['id'], 'status' => 'pending', 'available_at' => now()]));
            $outbox = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $project, 'receipt_id' => $receipt['id'], 'status' => 'pending', 'available_at' => now()];
            DB::table('ingestion_outbox')->insert($outbox);
            $this->sqlState('42501', fn () => DB::table('ingestion_outbox')->where('id', $outbox['id'])->update(['project_id' => $otherProject]));
            $projection = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $project, 'connector_id' => $connector, 'source_instance_id' => $source, 'source_object_type' => 'ticket', 'source_object_id' => 'synthetic-1', 'current_receipt_id' => $receipt['id'], 'state' => '{}', 'coverage_flags' => '[]'];
            $this->sqlState('23503', fn () => DB::table('ingestion_projections')->insert(['source_object_id' => 'wrong-object'] + $projection));
            DB::table('ingestion_projections')->insert($projection);
            $this->sqlState('42501', fn () => DB::table('ingestion_projections')->where('id', $projection['id'])->update(['source_object_id' => 'wrong-object']));
        });
    }

    public function test_status_exposes_operational_counts_without_payload_content(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('connector.manage', $project);
        $connector = $this->withToken($this->token)->postJson($this->url($project), $this->payload($this->source()))->assertCreated()->json('data.id');
        $this->getJson('/api/v1/connectors/'.$connector)->assertOk()->assertJsonPath('data.receipt_count', 0)->assertJsonPath('data.last_received_at', null)->assertJsonPath('data.last_processed_at', null)->assertJsonPath('data.pending_count', 0)->assertJsonPath('data.dead_letter_count', 0)->assertJsonPath('data.coverage_reason_codes', ['source_freshness_unknown'])->assertJsonMissingPath('data.payload_json')->assertJsonMissingPath('data.payload_bytes')->assertJsonMissingPath('data.source_watermark')->assertJsonMissingPath('data.healthy');
        $this->getJson($this->url($project))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_status_aggregates_receipts_processing_and_allowlisted_coverage_only(): void
    {
        $this->fixture();
        $project = $this->project();
        $source = $this->source();
        $this->grant('connector.manage', $project);
        $this->grant('ingestion.accept', $project);
        $connector = $this->withToken($this->token)->postJson($this->url($project), $this->payload($source))->assertCreated()->json('data.id');
        $e = require __DIR__.'/../Fixtures/ingestion-envelope.php';
        $first = app(IngestionService::class)->accept(new TrustedActor($this->user, $this->tenant), $connector, $e)['receipt_id'];
        $e['source_version'] = 'v2';
        $second = app(IngestionService::class)->accept(new TrustedActor($this->user, $this->tenant), $connector, $e)['receipt_id'];
        $e['source_version'] = 'v3';
        app(IngestionService::class)->accept(new TrustedActor($this->user, $this->tenant), $connector, $e);
        app(TenantContext::class)->run($this->tenant, function () use ($first, $second): void {
            $r = DB::table('ingestion_receipts')->where('id', $first)->first();
            app(TicketSnapshotProjector::class)->apply($r);
            DB::table('ingestion_outbox')->where('receipt_id', $first)->update(['status' => 'completed', 'completed_at' => '2026-10-05T07:00:00.654321Z']);
            DB::table('ingestion_outbox')->where('receipt_id', $second)->update(['status' => 'dead_letter', 'reason_code' => 'processing_failed']);
            DB::table('ingestion_projections')->update(['coverage_flags' => '["identity_unresolved","Synthetic secret"]']);
        });
        $response = $this->getJson('/api/v1/connectors/'.$connector)->assertOk()->assertJsonPath('data.pending_count', 1)->assertJsonPath('data.dead_letter_count', 1)->assertJsonPath('data.receipt_count', 3)->assertJsonPath('data.coverage_reason_codes', ['source_freshness_unknown', 'identity_unresolved'])->assertDontSee('Synthetic ticket')->assertDontSee('Synthetic secret')->assertJsonMissingPath('data.source_watermark')->assertJsonMissingPath('data.healthy');
        $this->assertNotNull($response->json('data.last_received_at'));
        $this->assertStringContainsString('07:00:00.654321', $response->json('data.last_processed_at'));
    }

    public function test_all_ingestion_grants_need_current_membership(): void
    {
        $this->fixture();
        $project = $this->project();
        foreach (['connector.manage', 'ingestion.accept', 'ingestion.read', 'ingestion.process'] as $purpose) {
            $this->grant($purpose, $project);
            app(AccessGateway::class)->authorize(new TrustedActor($this->user, $this->tenant), $purpose, ['tenant_id' => $this->tenant, 'project_id' => $project]);
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('project_id', $project)->update(['valid_to' => now()]));
        foreach (['connector.manage', 'ingestion.accept', 'ingestion.read', 'ingestion.process'] as $purpose) {
            try {
                app(AccessGateway::class)->authorize(new TrustedActor($this->user, $this->tenant), $purpose, ['tenant_id' => $this->tenant, 'project_id' => $project]);
                $this->fail('Revoked membership retained ingestion access.');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function sqlState(string $state, callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Database invariant accepted invalid write.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }

    private function payload(string $source): array
    {
        return ['source_instance_id' => $source, 'adapter' => 'synthetic-v1', 'status' => 'active'];
    }

    private function url(string $project): string
    {
        return '/api/v1/projects/'.$project.'/connectors';
    }
}
