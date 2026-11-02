<?php

namespace App\Domain\Browser\UzbekDemo;

use App\Domain\Access\TrustedActor;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaFixtureService;
use App\Domain\Ingestion\IngestionService;
use App\Domain\Lifecycle\BaselineService;
use App\Domain\Lifecycle\DodPolicyService;
use App\Domain\Lifecycle\EvidenceService;
use App\Domain\Lifecycle\HumanAcceptanceService;
use App\Domain\Lifecycle\QaObservationService;
use App\Domain\Lifecycle\RequirementService;
use App\Domain\Lifecycle\TaskService;
use App\Domain\Management\ManagementActionService;
use App\Domain\Management\ManagementSupportService;
use App\Domain\Qa\QaDefinitionService;
use App\Domain\Qa\QaExecutionService;
use App\Domain\Qa\QaFixtureService;
use App\Domain\Qa\QaHandoffService;
use App\Domain\Qa\QaPlanService;
use App\Domain\Qa\QaSelectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Native service scenario: synthetic source facts never bypass acceptance or review. */
final class UzbekDemoScenario
{
    public function populate(array $m, array $project): void
    {
        $p = $project['id'];
        $m['_project'] = $p;
        $source = new TrustedActor(UzbekDemoWorkspaceService::SOURCE, $m['tenant_id']);
        $reviewer = new TrustedActor(UzbekDemoWorkspaceService::REVIEWER, $m['tenant_id']);
        $people = $m['people'];
        $draft = app(RequirementService::class)->createDraft($source, $p, [
            'title' => 'DEMO — '.$project['name'].' asosiy jarayonlari', 'owner_id' => $people[1]['employee_id'],
            'stakeholder' => 'DEMO xayoliy buyurtmachi', 'business_outcome' => 'DEMO: dalil, QA va inson qabuli holatlarini namoyish qilish',
            'nfr_constraints' => ['Faqat suniy lokal namuna; haqiqiy mijoz yoki xodim malumoti emas'], 'dependencies' => [],
            'criteria' => [
                ['key' => 'A', 'text' => 'Foydalanuvchi yozuvi tekshirilgan holda saqlanadi', 'required_test_keys' => ['demo-save']],
                ['key' => 'B', 'text' => 'Hisobot filtri natijani togri chegaralaydi', 'required_test_keys' => ['demo-filter']],
                ['key' => 'C', 'text' => 'Buyurtmachi eksport formatini tasdiqlaydi', 'required_test_keys' => ['demo-export']],
            ],
        ]);
        $requirement = app(RequirementService::class)->approve($source, $p, $draft['id'], 1, 0, 'DEMO native reviewed requirements');
        $criteria = $requirement['criteria'];
        $generation = $requirement['generation'];
        $baseline = null;
        if ($project['baseline']) {
            $policy = app(DodPolicyService::class)->approve($source, $p, ['fictional' => true, 'mandatory_qa' => true, 'human_acceptance' => true], $generation);
            $baseline = app(BaselineService::class)->approve($source, $p, array_map(fn ($c, $w) => ['criterion_version_id' => $c['version_id'], 'weight' => $w], $criteria, [40, 35, 25]), $policy['id'], null, $policy['generation'], 'DEMO approved weighted baseline');
            $generation = $baseline['generation'];
        }
        $tasks = [];
        $titles = ['Yozuvni saqlash API va forma', 'Hisobot filtri va cheklovlar', 'Eksport formati kelishuvi', 'Foydalanuvchi qollanmasi'];
        foreach (['done', 'in_progress', 'blocked', 'unstarted'] as $i => $status) {
            $c = $criteria[min($i, 2)];
            $tasks[] = $task = app(TaskService::class)->create($source, $p, ['title' => 'DEMO — '.$titles[$i], 'assignee_id' => $people[[2, 4, 8, 11][$i]]['employee_id'], 'estimate_basis' => 'DEMO fictional scope; actual employee hours unavailable', 'remaining_effort' => null, 'status' => $status, 'expected_test_keys' => [['demo-save'], ['demo-filter'], ['demo-export'], ['demo-export']][$i], 'criterion_links' => [['criterion_id' => $c['id'], 'criterion_version_id' => $c['version_id']]]], $generation);
            $generation = $task['generation'];
        }
        if ($baseline !== null) {
            $connector = DB::table('ingestion_connectors')->where('project_id', $p)->value('id');
            $at = $this->instant();
            $receipt = app(IngestionService::class)->accept($source, $connector, ['version' => 'ingestion-v1', 'source_object_id' => $p.'-demo-save', 'source_version' => 'v1', 'event_kind' => 'ticket.snapshot', 'occurred_at' => $at, 'source_updated_at' => $at, 'payload' => ['external_project_id' => $p, 'external_ticket_id' => 'demo-save', 'title' => 'DEMO reviewed save implementation', 'status' => 'done', 'external_actor_id' => null, 'due_at' => null]]);
            $hash = DB::table('ingestion_receipts')->where('id', $receipt['receipt_id'])->value('canonical_hash');
            $link = app(EvidenceService::class)->propose($reviewer, $p, ['criterion_version_id' => $criteria[0]['version_id'], 'receipt_id' => $receipt['receipt_id'], 'canonical_hash' => $hash, 'locator' => 'payload.status'], $generation);
            $review = app(EvidenceService::class)->review($reviewer, $p, $link['id'], 'reviewed', 0, $link['generation'], 'DEMO explicit reviewed canonical source');
            $qa = app(QaObservationService::class)->record($reviewer, $p, ['criterion_version_id' => $criteria[0]['version_id'], 'link_id' => $link['id'], 'test_key' => 'demo-save', 'result' => 'passed'], 0, $review['generation']);
            app(HumanAcceptanceService::class)->accept($reviewer, $p, $baseline['id'], $criteria[0]['version_id'], [$link['id']], $qa['generation'], 'DEMO explicit human acceptance of synthetic reviewed evidence and mandatory QA');
            $this->qa($m, $p, $source, $reviewer, $baseline, $criteria, $tasks);
        }
        $envelope = fn (string $schema, string $operation, array $body, string $object) => $this->envelope($m, $schema, $operation, $body, $object);
        $at = $this->instant();
        app(QaHandoffService::class)->open($source, $p, $envelope('qa-evidence-v1', 'handoff', ['task_version_id' => $tasks[1]['version_id'], 'criterion_version_id' => $criteria[1]['version_id'], 'handoff_type' => 'support_requested', 'owner_id' => $people[7]['employee_id'], 'occurred_at' => $at, 'reason' => 'DEMO — filtr tekshiruvi uchun QA yordami kutilmoqda'], 'handoff'), 'uzbek-handoff');
        $party = app(BaFixtureService::class)->registerParty($source, $p, $envelope('ba-evidence-v1', 'party.register', ['party_reference' => 'demo-customer', 'party_kind' => 'customer', 'label' => 'DEMO — xayoliy buyurtmachi; haqiqiyligi tekshirilmagan', 'predecessor_party_version_id' => null], 'party'), 'uzbek-party', 0);
        $link = ['task_version_id' => $tasks[2]['version_id'], 'criterion_id' => $criteria[2]['id'], 'criterion_version_id' => $criteria[2]['version_id'], 'relation' => 'blocks'];
        app(BaClarificationService::class)->open($source, $p, $envelope('ba-evidence-v1', 'request.open', ['question' => 'DEMO — eksport ustunlari tartibi qanday bolishi kerak?', 'requester_id' => $people[0]['employee_id'], 'coordinator_id' => $people[9]['employee_id'], 'decision_party' => 'customer', 'decision_owner_id' => null, 'decision_owner_party_version_id' => $party['party_version_id'], 'requested_at' => $this->instant(), 'links' => [$link]], 'request'), 'uzbek-request', 0);
        $reference = ['kind' => 'lifecycle'] + $link;
        $action = app(ManagementActionService::class)->open($source, $p, $envelope('management-evidence-v1', 'action.open', ['owner_id' => $people[2]['employee_id'], 'reason' => 'DEMO — eksport formati boyicha texnik yordam', 'references' => [$reference]], 'action'), 'uzbek-action', 0);
        $assignment = app(ManagementActionService::class)->event(new TrustedActor(UzbekDemoWorkspaceService::ASSIGNER, $m['tenant_id']), $p, $action['action_id'], ['operation' => 'assignment.set', 'expected_revision' => 1, 'owner_id' => $people[2]['employee_id'], 'helper_id' => $people[8]['employee_id'], 'department_id' => $m['technical_department_id'], 'reason' => 'DEMO — loyiha doirasida texnik yordam tayinlash'], 'uzbek-assignment');
        $stored = DB::table('management_assignments')->where('id', $assignment['assignment_id'])->first();
        app(ManagementSupportService::class)->record($source, $p, $action['action_id'], $envelope('management-evidence-v1', 'support.record', ['action_id' => $action['action_id'], 'expected_revision' => 2, 'action_version_id' => $stored->action_version_id, 'action_hash' => $stored->action_hash, 'assignment_id' => $stored->id, 'assignment_hash' => $stored->assignment_hash, 'references' => [$reference], 'text' => 'DEMO — texnik variantlar tayyor; buyurtmachi qarori kutilmoqda', 'outcome' => 'completed', 'predecessor_support_id' => null], 'support'), 'uzbek-support', 0);
    }

    private function qa(array $m, string $p, TrustedActor $source, TrustedActor $reviewer, array $baseline, array $criteria, array $tasks): void
    {
        $definitions = [];
        foreach ($criteria as $i => $c) {
            $definition = app(QaFixtureService::class)->register($source, $p, $this->envelope($m, 'qa-evidence-v1', 'definition', ['logical_key' => 'demo-test-'.$i, 'revision' => 1, 'criterion_version_id' => $c['version_id'], 'title' => 'DEMO — '.$c['key'].' mezoni', 'steps' => 'DEMO — test formasini ishga tushirish', 'expected_result' => 'DEMO — kutilgan natija', 'test_type' => 'functional', 'release_critical' => false], 'definition-'.$i), 'uzbek-definition-'.$i, 0);
            app(QaDefinitionService::class)->review($reviewer, $p, $definition['record_id'], ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => 'DEMO explicit separate definition reviewer']);
            $definitions[] = $definition['record_id'];
        }
        $build = app(QaFixtureService::class)->register($source, $p, $this->envelope($m, 'qa-evidence-v1', 'build', ['logical_key' => 'demo-build', 'revision' => 1, 'title' => 'DEMO lokal yigim v1', 'content_hash' => hash('sha256', 'uzbek-demo-build-v1')], 'build'), 'uzbek-build', 0);
        $env = app(QaFixtureService::class)->register($source, $p, $this->envelope($m, 'qa-evidence-v1', 'environment', ['logical_key' => 'demo-local', 'revision' => 1, 'title' => 'DEMO lokal sinov muhiti', 'content_hash' => hash('sha256', 'uzbek-demo-environment-v1')], 'environment'), 'uzbek-environment', 0);
        $entries = [];
        foreach ($criteria as $i => $c) {
            $item = DB::table('baseline_items')->where('baseline_id', $baseline['id'])->where('criterion_version_id', $c['version_id'])->first();
            $entries[] = app(QaPlanService::class)->createEntry($reviewer, $p, ['baseline_id' => $baseline['id'], 'baseline_item_id' => $item->id, 'criterion_version_id' => $c['version_id'], 'disposition' => 'included', 'reason' => null, 'tests' => [['definition_version_id' => $definitions[$i], 'source_instance_id' => $m['source_id'], 'required' => true]]], 'uzbek-entry-'.$i);
        }
        $plan = app(QaPlanService::class)->compose($reviewer, $p, ['baseline_id' => $baseline['id'], 'entry_snapshot_ids' => array_column($entries, 'id')], 'uzbek-plan');
        app(QaPlanService::class)->review($reviewer, $p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'DEMO explicit reviewed QA plan']);
        app(QaSelectionService::class)->select($reviewer, $p, ['plan_version_id' => $plan['id'], 'build_id' => $build['record_id'], 'environment_version_id' => $env['record_id'], 'expected_revision' => 0, 'reason' => 'DEMO selected current build and local environment']);
        foreach (['passed', 'failed'] as $i => $outcome) {
            app(QaExecutionService::class)->register($source, $p, $this->envelope($m, 'qa-evidence-v1', 'execution', ['definition_version_id' => $definitions[$i], 'build_id' => $build['record_id'], 'environment_version_id' => $env['record_id'], 'attempt_ordinal' => 1, 'outcome' => $outcome, 'task_version_id' => $tasks[$i]['version_id'], 'locator' => 'synthetic.result'], 'execution-'.$i), 'uzbek-execution-'.$i, 0, 0);
        }
    }

    private function envelope(array $m, string $schema, string $operation, array $body, string $object): array
    {
        $at = $body['occurred_at'] ?? $this->instant();

        return ['schema_version' => $schema, 'operation' => $operation, 'source_instance_id' => $m['source_id'], 'object_id' => 'uzbek-demo-'.$m['_project'].'-'.$object, 'source_revision' => 1, 'occurred_at' => $at, 'source_updated_at' => $at, 'body' => $body];
    }

    private function instant(): string
    {
        return CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
