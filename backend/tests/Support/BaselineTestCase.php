<?php

namespace Tests\Support;

use App\Domain\Ingestion\IngestionService;
use App\Domain\Lifecycle\BaselineService;
use App\Domain\Lifecycle\DodPolicyService;
use App\Domain\Lifecycle\EvidenceService;
use App\Domain\Lifecycle\HumanAcceptanceService;
use App\Domain\Lifecycle\ProgressCalculator;
use App\Domain\Lifecycle\QaObservationService;
use App\Domain\Lifecycle\RequirementService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class BaselineTestCase extends LifecycleTestCase
{
    protected string $p;

    protected array $criteria;

    protected string $policyId;

    protected int $generation;

    protected ?string $baselineId = null;

    protected string $readGrant;

    protected string $connector;

    protected array $envelope;

    protected array $draft;

    protected function inputs(): void
    {
        $this->p = $this->lifecycle();
        foreach (['baseline.approve', 'acceptance.review', 'progress.read', 'evidence.review', 'ingestion.accept'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
        $this->readGrant = $this->grant('ingestion.read', $this->p);
        $data = $this->requirement();
        $data['criteria'][] = ['key' => 'C', 'text' => 'Synthetic C', 'required_test_keys' => ['test-c']];
        $this->draft = app(RequirementService::class)->createDraft($this->actor(), $this->p, $data);
        $v = app(RequirementService::class)->approve($this->actor(), $this->p, $this->draft['id'], 1, 0, 'Synthetic approval');
        $this->criteria = $v['criteria'];
        $this->policyId = app(DodPolicyService::class)->approve($this->actor(), $this->p, ['fictional' => true, 'mandatory_qa' => true, 'human_acceptance' => true], 1)['id'];
        $this->generation = 2;
        $this->connector = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () {
            $source = (string) Str::uuid();
            DB::table('source_instances')->insert(['id' => $source, 'tenant_id' => $this->tenant, 'kind' => 'manual', 'label' => 'Synthetic source']);
            DB::table('ingestion_connectors')->insert(['id' => $this->connector, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'source_instance_id' => $source, 'status' => 'active']);
        });
        $this->envelope = ['version' => 'ingestion-v1', 'source_object_id' => 'ticket-1', 'source_version' => 'v1', 'event_kind' => 'ticket.snapshot', 'occurred_at' => '2026-10-05T12:00:00Z', 'source_updated_at' => '2026-10-05T12:00:00Z', 'payload' => ['external_project_id' => 'project-1', 'external_ticket_id' => 'ticket-1', 'title' => 'Sensitive synthetic title', 'status' => 'done', 'external_actor_id' => null, 'due_at' => null]];
    }

    protected function scope(array $weights = [67, 20, 13], ?string $carry = null): array
    {
        return array_map(fn ($c, $w, $i) => ['criterion_version_id' => $c['version_id'], 'weight' => $w] + ($i === 0 && $carry ? ['carry_decision_id' => $carry] : []), $this->criteria, $weights, array_keys($weights));
    }

    protected function baseline(array $items, bool $reopen = false): array
    {
        $r = app(BaselineService::class)->approve($this->actor(), $this->p, $items, $this->policyId, $this->baselineId, $this->generation, 'Synthetic scope', $reopen);
        $this->generation = $r['generation'];
        $this->baselineId = $r['id'];

        return $r;
    }

    protected function evidence(int $index = 0, string $result = 'passed'): array
    {
        $receipt = app(IngestionService::class)->accept($this->actor(), $this->connector, $this->envelope);
        $hash = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_receipts')->where('id', $receipt['receipt_id'])->value('canonical_hash'));
        $l = app(EvidenceService::class)->propose($this->actor(), $this->p, ['criterion_version_id' => $this->criteria[$index]['version_id'], 'receipt_id' => $receipt['receipt_id'], 'canonical_hash' => $hash, 'locator' => 'payload.status'], $this->generation);
        $this->generation = $l['generation'];
        $r = app(EvidenceService::class)->review($this->actor(), $this->p, $l['id'], 'reviewed', 0, $this->generation, 'Synthetic review');
        $this->generation = $r['generation'];
        $q = app(QaObservationService::class)->record($this->actor(), $this->p, ['criterion_version_id' => $this->criteria[$index]['version_id'], 'link_id' => $l['id'], 'test_key' => 'test-'.strtolower($this->criteria[$index]['key']), 'result' => $result], 0, $this->generation);
        $this->generation = $q['generation'];

        return $l;
    }

    protected function accept(array $link, int $index = 0): array
    {
        $r = app(HumanAcceptanceService::class)->accept($this->actor(), $this->p, $this->baselineId, $this->criteria[$index]['version_id'], [$link['id']], $this->generation, 'Synthetic acceptance');
        $this->generation = $r['generation'];

        return $r;
    }

    protected function progress(?string $baseline = null): array
    {
        return app(ProgressCalculator::class)->calculate($this->actor(), $this->p, $baseline);
    }
}
