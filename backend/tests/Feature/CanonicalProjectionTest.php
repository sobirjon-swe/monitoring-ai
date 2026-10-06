<?php

namespace Tests\Feature;

use App\Domain\Access\TrustedActor;
use App\Domain\Identity\IdentityMappingService;
use App\Domain\Ingestion\IngestionService;
use App\Domain\Ingestion\TicketSnapshotProjector;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessTestCase;

class CanonicalProjectionTest extends AccessTestCase
{
    private string $connector;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $project = $this->project();
        $this->source = (string) Str::uuid();
        $this->connector = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($project): void {
            DB::table('source_instances')->insert(['id' => $this->source, 'tenant_id' => $this->tenant, 'kind' => 'manual', 'label' => 'Synthetic']);
            DB::table('ingestion_connectors')->insert(['id' => $this->connector, 'tenant_id' => $this->tenant, 'project_id' => $project, 'source_instance_id' => $this->source, 'status' => 'active']);
        });
        $this->grant('ingestion.accept', $project);
    }

    private function receipt(string $version = 'v1', ?string $time = '2026-10-05T12:00:00.123456+05:00', string $title = 'Synthetic ticket', ?string $actor = null, ?string $occurred = null): object
    {
        $e = require __DIR__.'/../Fixtures/ingestion-envelope.php';
        $e['source_version'] = $version;
        $e['source_updated_at'] = $time;
        $e['payload']['title'] = $title;
        $e['payload']['external_actor_id'] = $actor;
        if ($occurred !== null) {
            $e['occurred_at'] = $occurred;
        }
        $result = app(IngestionService::class)->accept(new TrustedActor($this->user, $this->tenant), $this->connector, $e);

        return app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_receipts')->where('id', $result['receipt_id'])->first());
    }

    private function apply(object $receipt): array
    {
        return app(TenantContext::class)->run($this->tenant, fn () => app(TicketSnapshotProjector::class)->apply($receipt));
    }

    private function projection(): object
    {
        return app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_projections')->first());
    }

    public function test_newer_microsecond_wins_and_late_receipt_is_retained(): void
    {
        $first = $this->receipt();
        $this->apply($first);
        $newer = $this->receipt('v2', '2026-10-05T07:00:00.123457Z', 'Newer');
        $this->assertTrue($this->apply($newer)['applied']);
        $this->assertSame('older_snapshot', $this->apply($first)['reason_code']);
        $p = $this->projection();
        $this->assertSame($newer->id, $p->current_receipt_id);
        $this->assertSame('Newer', json_decode($p->state, true)['title']);
        $this->assertStringContainsString('07:00:00.123457', $p->source_updated_at);
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(2, DB::table('ingestion_receipts')->count()));
    }

    public function test_equal_time_contradiction_preserves_current_and_marks_conflict(): void
    {
        $first = $this->receipt();
        $this->apply($first);
        $this->assertSame('ordering_conflict', $this->apply($this->receipt('v2', title: 'Contradiction'))['reason_code']);
        $this->assertSame($first->id, $this->projection()->current_receipt_id);
        $this->assertContains('ordering_conflict', json_decode($this->projection()->coverage_flags, true));
    }

    public function test_unknown_ordering_never_replaces_known_or_unknown_current(): void
    {
        $first = $this->receipt(time: null);
        $this->apply($first);
        $this->assertSame('ordering_unknown', $this->apply($this->receipt('v2', title: 'Known later'))['reason_code']);
        $this->assertSame($first->id, $this->projection()->current_receipt_id);
        $this->assertContains('ordering_unknown', json_decode($this->projection()->coverage_flags, true));
    }

    public function test_unknown_incoming_cannot_replace_known_current_and_equivalent_tie_is_not_conflict(): void
    {
        $first = $this->receipt();
        $this->assertContains('identity_unresolved', $this->apply($first)['coverage_flags']);
        $this->assertSame('equivalent_snapshot', $this->apply($this->receipt('v2'))['reason_code']);
        $this->assertNotContains('ordering_conflict', json_decode($this->projection()->coverage_flags, true));
        $this->assertSame('ordering_unknown', $this->apply($this->receipt('v3', null, 'Unknown'))['reason_code']);
        $this->assertSame($first->id, $this->projection()->current_receipt_id);
    }

    public function test_newer_known_snapshot_keeps_unresolved_timestamp_less_history_visible_in_status(): void
    {
        $first = $this->receipt();
        $this->apply($first);
        $this->apply($this->receipt('v2', null, 'Unorderable contradiction'));
        $newer = $this->receipt('v3', '2026-10-05T07:00:01Z', 'Newer known snapshot');
        $result = $this->apply($newer);
        $this->assertTrue($result['applied']);
        $this->assertContains('ordering_unknown', $result['coverage_flags']);
        $this->assertSame($newer->id, $this->projection()->current_receipt_id);
        $this->assertContains('ordering_unknown', json_decode($this->projection()->coverage_flags, true));
        $project = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_connectors')->where('id', $this->connector)->value('project_id'));
        $this->grant('connector.manage', $project);
        $this->withToken($this->token)->getJson('/api/v1/connectors/'.$this->connector)->assertOk()->assertJsonPath('data.coverage_reason_codes', ['source_freshness_unknown', 'identity_unresolved', 'ordering_unknown']);
    }

    public function test_missing_and_unknown_actor_remain_explicit_and_replay_is_idempotent(): void
    {
        $first = $this->receipt(actor: 'unmapped');
        $this->assertContains('identity_unresolved', $this->apply($first)['coverage_flags']);
        $this->assertSame('already_current', $this->apply($first)['reason_code']);
        $this->assertNull(json_decode($this->projection()->state, true)['attribution']);
    }

    public function test_event_time_half_open_mapping_and_revision_are_pinned(): void
    {
        $mapping = app(TenantContext::class)->run($this->tenant, function (): object {
            $service = app(IdentityMappingService::class);
            $identity = $service->createIdentity($this->tenant, $this->user, $this->source, ['external_user_id' => 'actor-1']);
            $m = $service->propose($this->tenant, $this->user, $identity->id, ['employee_id' => $this->employee, 'reason' => 'Synthetic', 'valid_from' => '2026-10-05T07:00:00.123456Z']);

            return $service->review($this->tenant, $this->user, $m->id, ['employee_id' => $this->employee, 'status' => 'approved', 'reason' => 'Synthetic', 'expected_revision' => 1, 'valid_from' => '2026-10-05T07:00:00.123456Z', 'valid_to' => '2026-10-05T07:00:00.123457Z']);
        });
        $r = $this->receipt(actor: 'actor-1');
        $this->apply($r);
        $a = json_decode($this->projection()->state, true)['attribution'];
        $this->assertSame($mapping->id, $a['mapping_id']);
        $this->assertSame(2, $a['revision']);
        $this->assertSame($this->employee, $a['employee_id']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('identity_mappings')->where('id', $mapping->id)->update(['revision' => 3, 'status' => 'rejected']));
        $this->apply($r);
        $this->assertSame(2, json_decode($this->projection()->state, true)['attribution']['revision']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('identity_mappings')->where('id', $mapping->id)->update(['revision' => 4, 'status' => 'approved']));
        $later = $this->receipt('v2', '2026-10-05T07:00:00.123457Z', actor: 'actor-1', occurred: '2026-10-05T07:00:00.123457Z');
        $this->assertContains('identity_unresolved', $this->apply($later)['coverage_flags']);
    }

    public function test_caller_payload_is_ignored_and_missing_or_foreign_context_rejected(): void
    {
        $r = $this->receipt();
        $r->payload_json = '{"payload":{"title":"Injected"}}';
        $this->apply($r);
        $this->assertSame('Synthetic ticket', json_decode($this->projection()->state, true)['title']);
        foreach ([null, (string) Str::uuid()] as $tenant) {
            try {
                $work = fn () => app(TicketSnapshotProjector::class)->apply($r);
                $tenant === null ? $work() : app(TenantContext::class)->run($tenant, $work);
                $this->fail('Unauthorized receipt projected.');
            } catch (\LogicException|NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
