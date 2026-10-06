<?php

namespace Tests\Feature;

use App\Domain\Ingestion\IngestionService;
use App\Domain\Lifecycle\CurrentInputEligibility;
use App\Domain\Lifecycle\DodPolicyService;
use App\Domain\Lifecycle\EvidenceService;
use App\Domain\Lifecycle\ProjectCoordination;
use App\Domain\Lifecycle\QaObservationService;
use App\Domain\Lifecycle\RequirementService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LifecycleTestCase;

class LifecycleEvidenceTest extends LifecycleTestCase
{
    private string $p;

    private string $criterion;

    private string $policyId;

    private array $proposal;

    private int $generation;

    private string $readGrant;

    private string $connector;

    private array $envelope;

    private function inputs(?string $sourceTime = null): void
    {
        $this->p = $this->lifecycle();
        $this->grant('evidence.review', $this->p);
        $this->grant('ingestion.accept', $this->p);
        $this->readGrant = $this->grant('ingestion.read', $this->p);
        $s = app(RequirementService::class);
        $d = $s->createDraft($this->actor(), $this->p, $this->requirement());
        $v = $s->approve($this->actor(), $this->p, $d['id'], 1, 0, 'Synthetic approval');
        $this->criterion = $v['criteria'][0]['version_id'];
        $policy = app(DodPolicyService::class)->approve($this->actor(), $this->p, ['fictional' => true, 'mandatory_qa' => true, 'human_acceptance' => true], 1);
        $this->policyId = $policy['id'];
        $this->generation = 2;
        $connector = (string) Str::uuid();
        $this->connector = $connector;
        app(TenantContext::class)->run($this->tenant, function () use ($connector): void {
            $source = (string) Str::uuid();
            DB::table('source_instances')->insert(['id' => $source, 'tenant_id' => $this->tenant, 'kind' => 'manual', 'label' => 'Synthetic source']);
            DB::table('ingestion_connectors')->insert(['id' => $connector, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'source_instance_id' => $source, 'status' => 'active']);
        });
        $e = ['version' => 'ingestion-v1', 'source_object_id' => 'ticket-1', 'source_version' => 'v1', 'event_kind' => 'ticket.snapshot', 'occurred_at' => '2026-10-05T12:00:00Z', 'source_updated_at' => $sourceTime, 'payload' => ['external_project_id' => 'project-1', 'external_ticket_id' => 'ticket-1', 'title' => 'Sensitive synthetic title', 'status' => 'open', 'external_actor_id' => null, 'due_at' => null]];
        $this->envelope = $e;
        $receipt = app(IngestionService::class)->accept($this->actor(), $connector, $e);
        $hash = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_receipts')->where('id', $receipt['receipt_id'])->value('canonical_hash'));
        $this->proposal = ['criterion_version_id' => $this->criterion, 'receipt_id' => $receipt['receipt_id'], 'canonical_hash' => $hash, 'locator' => 'payload.status'];
    }

    private function propose(): array
    {
        $r = app(EvidenceService::class)->propose($this->actor(), $this->p, $this->proposal, $this->generation);
        $this->generation = $r['generation'];

        return $r;
    }

    private function review(array $link, string $decision = 'reviewed', int $revision = 0): array
    {
        $r = app(EvidenceService::class)->review($this->actor(), $this->p, $link['id'], $decision, $revision, $this->generation, 'Synthetic review');
        $this->generation = $r['generation'];

        return $r;
    }

    private function qa(array $link, string $result, int $revision = 0): array
    {
        $r = app(QaObservationService::class)->record($this->actor(), $this->p, ['criterion_version_id' => $this->criterion, 'link_id' => $link['id'], 'test_key' => 'test-a', 'result' => $result], $revision, $this->generation);
        $this->generation = $r['generation'];

        return $r;
    }

    private function eligibility(array $ids): array
    {
        return app(ProjectCoordination::class)->read($this->actor(), $this->p, 'requirements.read', fn () => app(CurrentInputEligibility::class)->check($this->actor(), $this->p, $this->criterion, $this->policyId, $ids));
    }

    public function test_proposed_link_never_valid(): void
    {
        $this->inputs();
        $l = $this->propose();
        $this->assertSame('unknown', $this->eligibility([$l['id']])['status']);
        $this->assertContains('evidence_not_reviewed', $this->eligibility([$l['id']])['reason_codes']);
    }

    public function test_latest_failed_and_no_qa_never_pass(): void
    {
        $this->inputs();
        $l = $this->propose();
        $this->review($l);
        $this->assertSame('unknown', $this->eligibility([$l['id']])['status']);
        $this->qa($l, 'passed');
        $valid = $this->eligibility([$l['id']]);
        $this->assertSame('valid', $valid['status']);
        $this->assertSame(1, $valid['pins']['qa'][0]['revision']);
        $this->qa($l, 'failed', 1);
        $this->assertSame('stale', $this->eligibility([$l['id']])['status']);
        $this->assertContains('qa_failed', $this->eligibility([$l['id']])['reason_codes']);
        $this->qa($l, 'passed', 2);
        $this->review($l, 'revoked', 1);
        $this->assertContains('evidence_not_reviewed', $this->eligibility([$l['id']])['reason_codes']);
    }

    public function test_revoked_source_access_unknown_without_payload(): void
    {
        $this->inputs();
        $l = $this->propose();
        $this->review($l);
        $this->qa($l, 'passed');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $this->readGrant)->update(['revoked_at' => now()]));
        $r = $this->eligibility([$l['id']]);
        $this->assertSame(['status' => 'unknown', 'reason_codes' => ['source_access_unavailable'], 'pins' => []], $r);
        $this->assertStringNotContainsString('Sensitive', json_encode($r));
    }

    public function test_invalid_locator_hash_scope_rejected(): void
    {
        $this->inputs();
        $this->withToken($this->token);
        foreach (['locator' => 'payload.secret', 'canonical_hash' => str_repeat('0', 64), 'criterion_version_id' => (string) Str::uuid()] as $k => $v) {
            $d = $this->proposal;
            $d[$k] = $v;
            $response = $this->postJson('/api/v1/projects/'.$this->p.'/evidence-links', $d + ['expected_generation' => 2]);
            $this->assertContains($response->status(), [404, 422]);
            $response->assertDontSee('Sensitive');
        }
    }

    public function test_correction_atomic_change_event(): void
    {
        $this->inputs();
        $l = $this->propose();
        $this->review($l);
        $this->qa($l, 'passed');
        $before = $this->generation;
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'insert into "lifecycle_outbox"')) {
                throw new \RuntimeException('Synthetic rollback');
            }
        });
        try {
            $this->qa($l, 'failed', 1);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic rollback', $e->getMessage());
        }
        $this->assertSame('valid', $this->eligibility([$l['id']])['status']);
        app(TenantContext::class)->run($this->tenant, function () use ($before): void {
            $this->assertSame($before, DB::table('lifecycle_project_state')->where('project_id', $this->p)->value('generation'));
            $this->assertSame(1, DB::table('qa_observations')->count());
        });
    }

    public function test_unused_policy_and_unrelated_generation_preserve_pins(): void
    {
        $this->inputs();
        $l = $this->propose();
        $this->review($l);
        $this->qa($l, 'passed');
        $before = $this->eligibility([$l['id']]);
        app(DodPolicyService::class)->approve($this->actor(), $this->p, ['fictional' => true, 'mandatory_qa' => true, 'human_acceptance' => true], $this->generation);
        $this->assertSame($before, $this->eligibility([$l['id']]));
    }

    public function test_latest_qa_is_per_criterion_test_not_per_evidence_link(): void
    {
        $this->inputs();
        $a = $this->propose();
        $this->review($a);
        $this->qa($a, 'passed');
        $b = $this->propose();
        $this->review($b);
        $this->qa($b, 'failed', 1);
        $this->assertContains('qa_failed', $this->eligibility([$a['id']])['reason_codes']);
        $this->assertSame('stale', $this->eligibility([$a['id'], $b['id']])['status']);
        $this->assertSame(2, $this->eligibility([$a['id'], $b['id']])['pins']['qa'][0]['revision']);
    }

    public function test_strict_http_inputs_and_independent_source_permission(): void
    {
        $this->inputs();
        $this->withToken($this->token);
        $url = '/api/v1/projects/'.$this->p.'/evidence-links';
        foreach (['tenant_id' => $this->tenant, 'expected_generation' => 2.0, 'receipt_id' => false, 'locator' => "payload.status\0"] as $k => $v) {
            $d = $this->proposal + ['expected_generation' => 2];
            $d[$k] = $v;
            $response = $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], json_encode($d, JSON_PRESERVE_ZERO_FRACTION));
            $response->assertUnprocessable();
        }
        $raw = json_encode($this->proposal + ['expected_generation' => 2]);
        $raw = substr($raw, 0, -1).',"expected_generation":2}';
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], $raw)->assertUnprocessable();
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], str_repeat(' ', 65537))->assertStatus(413);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $this->readGrant)->update(['revoked_at' => now()]));
        $this->postJson($url, $this->proposal + ['expected_generation' => 2])->assertForbidden();
    }

    public function test_cas_unknown_tests_and_unreviewed_qa_rejected(): void
    {
        $this->inputs();
        $l = $this->propose();
        $this->withToken($this->token);
        $data = ['criterion_version_id' => $this->criterion, 'link_id' => $l['id'], 'test_key' => 'test-a', 'result' => 'passed', 'expected_revision' => 0, 'expected_generation' => $this->generation];
        $url = '/api/v1/projects/'.$this->p.'/qa-observations';
        $this->postJson($url, $data)->assertConflict();
        $this->review($l);
        $data['expected_generation'] = $this->generation;
        $d = $data;
        $d['test_key'] = 'unrecognized';
        $this->postJson($url, $d)->assertUnprocessable();
        $this->postJson($url, $data)->assertCreated()->assertDontSee('Sensitive');
        $this->generation++;
        $data['expected_generation'] = $this->generation;
        $this->postJson($url, $data)->assertConflict();
        $data['expected_revision'] = 1;
        $data['expected_generation']--;
        $this->postJson($url, $data)->assertConflict();
        $this->getJson('/api/v1/projects/'.$this->p.'/evidence-links')->assertStatus(405);
    }

    public function test_rls_immutable_snapshots_and_composite_lineage(): void
    {
        $this->inputs();
        $l = $this->propose();
        $this->review($l);
        $q = $this->qa($l, 'passed');
        $this->assertSame(0, DB::table('evidence_link_proposals')->count());
        app(TenantContext::class)->run($this->tenant, function () use ($q, $l): void {
            foreach (['evidence_link_proposals', 'evidence_reviews', 'qa_test_identities', 'qa_observations'] as $table) {
                $id = DB::table($table)->value('id');
                try {
                    DB::transaction(fn () => DB::table($table)->where('id', $id)->update(['id' => (string) Str::uuid()]));
                    $this->fail('Immutable update accepted');
                } catch (QueryException $e) {
                    $this->assertSame('42501', $e->errorInfo[0]);
                }
            }
            $obs = (array) DB::table('qa_observations')->where('id', $q['id'])->first();
            $obs['id'] = (string) Str::uuid();
            $obs['revision'] = 2;
            $obs['criterion_version_id'] = (string) Str::uuid();
            try {
                DB::transaction(fn () => DB::table('qa_observations')->insert($obs));
                $this->fail('QA criterion binding widened');
            } catch (QueryException $e) {
                $this->assertSame('23503', $e->errorInfo[0]);
            }
            try {
                DB::transaction(fn () => DB::table('evidence_review_heads')->where('link_id', $l['id'])->update(['revision' => 1]));
                $this->fail('Pointer failed to advance');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
            $link = (array) DB::table('evidence_link_proposals')->where('id', $l['id'])->first();
            $link['id'] = (string) Str::uuid();
            $link['canonical_hash'] = str_repeat('0', 64);
            try {
                DB::transaction(fn () => DB::table('evidence_link_proposals')->insert($link));
                $this->fail('Receipt hash binding widened');
            } catch (QueryException $e) {
                $this->assertSame('23503', $e->errorInfo[0]);
            }
        });
    }

    public function test_two_sessions_coordination_fences_review_qa_and_eligibility(): void
    {
        $this->inputs();
        $l = $this->propose();
        $this->review($l);
        $this->qa($l, 'passed');
        $marker = DB::scalar("select shobj_description(oid,'pg_database') from pg_database where datname=current_database()");
        $this->assertStringStartsWith('monitoring-ai-local-v1:', $marker);
        DB::commit(); // Guarded isolated test database retains a synthetic immutable fixture.
        config(['database.connections.lifecycle_contender' => config('database.connections.pgsql')]);
        $other = DB::connection('lifecycle_contender');
        $this->assertSame('monitoring_ai_test', $other->scalar('select current_database()'));
        $this->assertSame('monitoring_ai_app', $other->scalar('select current_user'));
        $other->beginTransaction();
        try {
            $other->select("select set_config('app.tenant_id',?,true)", [$this->tenant]);
            $other->table('projects')->where('id', $this->p)->lockForUpdate()->first();
            foreach (['review', 'qa', 'eligibility', 'source'] as $op) {
                try {
                    app(TenantContext::class)->run($this->tenant, function () use ($op, $l): void {
                        DB::statement("SET LOCAL lock_timeout='100ms'");
                        match ($op) {
                            'review' => $this->review($l, 'revoked', 1),'qa' => $this->qa($l, 'failed', 1),'eligibility' => $this->eligibility([$l['id']]),'source' => app(IngestionService::class)->accept($this->actor(), $this->connector, $this->envelope)
                        };
                    });
                    $this->fail('Concurrent coordination lock bypassed');
                } catch (QueryException $e) {
                    $this->assertSame('55P03', $e->errorInfo[0]);
                }
            }
        } finally {
            $other->rollBack();
            DB::purge('lifecycle_contender');
        }
        $this->assertSame('valid', $this->eligibility([$l['id']])['status']);
        $this->qa($l, 'failed', 1);
        $this->assertSame('stale', $this->eligibility([$l['id']])['status']);
    }

    public function test_newer_pending_source_receipt_invalidates_before_projection(): void
    {
        $this->inputs('2026-10-05T12:00:00Z');
        $l = $this->propose();
        $this->review($l);
        $this->qa($l, 'passed');
        $this->assertSame('valid', $this->eligibility([$l['id']])['status']);
        $e = $this->envelope;
        $e['source_version'] = 'v2';
        $e['source_updated_at'] = '2026-10-05T12:01:00Z';
        app(IngestionService::class)->accept($this->actor(), $this->connector, $e);
        $r = $this->eligibility([$l['id']]);
        $this->assertSame('stale', $r['status']);
        $this->assertContains('source_receipt_superseded', $r['reason_codes']);
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(0, DB::table('ingestion_projections')->count()));
    }

    public function test_older_late_source_receipt_preserves_pins(): void
    {
        $this->inputs('2026-10-05T12:00:00Z');
        $l = $this->propose();
        $this->review($l);
        $this->qa($l, 'passed');
        $before = $this->eligibility([$l['id']]);
        $e = $this->envelope;
        $e['source_version'] = 'old';
        $e['source_updated_at'] = '2026-10-05T11:00:00Z';
        app(IngestionService::class)->accept($this->actor(), $this->connector, $e);
        $this->assertSame($before, $this->eligibility([$l['id']]));
    }

    public function test_unknown_or_equal_contradictory_source_ordering_is_unknown(): void
    {
        $this->inputs('2026-10-05T12:00:00Z');
        $l = $this->propose();
        $this->review($l);
        $this->qa($l, 'passed');
        foreach (['equal' => '2026-10-05T12:00:00Z', 'unknown' => null] as $version => $time) {
            $e = $this->envelope;
            $e['source_version'] = $version;
            $e['source_updated_at'] = $time;
            app(IngestionService::class)->accept($this->actor(), $this->connector, $e);
            $r = $this->eligibility([$l['id']]);
            $this->assertSame('unknown', $r['status']);
            $this->assertContains('source_ordering_unknown', $r['reason_codes']);
        }
    }
}
