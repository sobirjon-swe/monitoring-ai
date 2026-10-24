<?php

namespace Tests\Support;

use App\Domain\Access\TrustedActor;
use App\Domain\Ba\BaAnswerService;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaFixtureService;
use App\Domain\Ba\BaReviewService;
use App\Domain\Lifecycle\TaskService;
use App\Domain\Management\ManagementActionService;
use App\Domain\Management\ManagementContract;
use App\Domain\Management\ManagementCoordination;
use App\Domain\Management\ManagementPins;
use App\Domain\Qa\QaDefectService;
use App\Domain\Qa\QaDefinitionService;
use App\Domain\Qa\QaExecutionService;
use App\Domain\Qa\QaFixtureService;
use App\Domain\Qa\QaHandoffService;
use App\Domain\Qa\QaPlanService;
use App\Domain\Qa\QaSelectionService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class ManagementTestCase extends BaselineTestCase
{
    protected array $managementTask;

    protected string $managementSource;

    protected function managementInputs(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        $this->managementTask = app(TaskService::class)->create($this->actor(), $this->p, ['title' => 'Synthetic management task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic estimate', 'remaining_effort' => null, 'status' => 'unstarted', 'expected_test_keys' => ['test-a'], 'criterion_links' => [['criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id']]]], $this->generation);
        $this->generation = $this->managementTask['generation'];
        foreach (['management.action.manage', 'management.read', 'management.source.read'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
        app(TenantContext::class)->run($this->tenant, function (): void {
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'pm', 'valid_from' => now()->subDay()]);
            $this->managementSource = (string) Str::uuid();
            DB::table('source_instances')->insert(['id' => $this->managementSource, 'tenant_id' => $this->tenant, 'kind' => 'manual', 'label' => 'Synthetic management source']);
            DB::table('ingestion_connectors')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'source_instance_id' => $this->managementSource, 'status' => 'active', 'adapter' => 'synthetic-v1']);
        });
    }

    protected function managementActor(string $role, ?string $employee = null): TrustedActor
    {
        return app(TenantContext::class)->run($this->tenant, function () use ($role, $employee) {
            if ($employee === null) {
                $employee = (string) Str::uuid();
                DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic management employee']);
                DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
            }
            $id = (string) Str::uuid();
            DB::table('users')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'employee_id' => $employee, 'display_name' => 'Synthetic management user']);
            if (! DB::table('role_assignments')->where('project_id', $this->p)->where('employee_id', $employee)->where('role', $role)->exists()) {
                DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'role' => $role, 'valid_from' => now()->subDay()]);
            }

            return new TrustedActor($id, $this->tenant);
        });
    }

    protected function managementSourceEnvelope(string $schema, string $operation, array $body, string $object): array
    {
        $envelope = $this->managementEnvelope($operation, $body, $object);
        $envelope['schema_version'] = $schema;

        return $envelope;
    }

    protected function managementBaFixture(?string $requester = null, ?string $coordinator = null): array
    {
        foreach (['ba.fixture.manage', 'ba.request.manage', 'ba.answer.record', 'ba.source.read', 'ba.read'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'ba', 'valid_from' => now()->subDay()]));
        $party = app(BaFixtureService::class)->registerParty($this->actor(), $this->p, $this->managementSourceEnvelope('ba-evidence-v1', 'party.register', ['party_reference' => 'synthetic-customer', 'party_kind' => 'customer', 'label' => 'Synthetic customer', 'predecessor_party_version_id' => null], 'party'), 'party', 0);
        $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $link = $this->lifecycleReference();
        unset($link['kind']);
        $body = ['question' => 'Synthetic clarification', 'requester_id' => $requester ?? $this->employee, 'coordinator_id' => $coordinator ?? $this->employee, 'decision_party' => 'customer', 'decision_owner_id' => null, 'decision_owner_party_version_id' => $party['party_version_id'], 'requested_at' => $at, 'links' => [$link]];
        $request = app(BaClarificationService::class)->open($this->actor(), $this->p, $this->managementSourceEnvelope('ba-evidence-v1', 'request.open', $body, 'request'), 'request', 0);

        return $request + ['party' => $party, 'body' => $body];
    }

    protected function managementBaReviewedFixture(string $disposition = 'scope_change_required'): array
    {
        $request = $this->managementBaFixture();
        $stored = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ba_request_versions')->where('id', $request['request_version_id'])->first());
        $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $body = ['clarification_id' => $request['clarification_id'], 'expected_revision' => 1, 'request_version_id' => $stored->id, 'request_hash' => $stored->request_hash, 'links_hash' => $stored->links_hash, 'author_party_version_id' => $request['party']['party_version_id'], 'response_at' => $at, 'answer' => 'Synthetic response', 'predecessor_answer_version_id' => null];
        $answer = app(BaAnswerService::class)->record($this->actor(), $this->p, $request['clarification_id'], $this->managementSourceEnvelope('ba-evidence-v1', 'answer.record', $body, 'answer'), 'answer', 0);
        $reviewer = $this->managementActor('pm');
        app(TenantContext::class)->run($this->tenant, function () use ($reviewer) {
            foreach (['ba.decision.confirm', 'ba.source.read', 'ba.read'] as $purpose) {
                DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $reviewer->id, 'policy_version_id' => $this->policy, 'purpose' => $purpose, 'scope_type' => 'project', 'valid_from' => now()->subDay()]);
            }
        });
        $answerStored = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ba_answer_versions')->where('id', $answer['answer_version_id'])->first());
        $review = app(BaReviewService::class)->review($reviewer, $this->p, $request['clarification_id'], ['kind' => 'review.confirm', 'clarification_id' => $request['clarification_id'], 'expected_revision' => 2, 'request_version_id' => $stored->id, 'request_hash' => $stored->request_hash, 'links_hash' => $stored->links_hash, 'answer_version_id' => $answerStored->id, 'answer_hash' => $answerStored->answer_hash, 'disposition' => $disposition, 'reason' => 'Synthetic independent review'], 'review');

        return compact('request', 'answer', 'review', 'reviewer');
    }

    protected function managementQaHandoff(string $key = 'handoff', ?TrustedActor $actor = null, bool $configure = true): array
    {
        $actor ??= $this->actor();
        if ($configure) {
            foreach (['qa.handoff.manage', 'qa.source.read', 'qa.read'] as $purpose) {
                $this->grant($purpose, $this->p);
            }
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => now()->subDay()]));
        }
        $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $body = ['task_version_id' => $this->managementTask['version_id'], 'criterion_version_id' => $this->criteria[0]['version_id'], 'handoff_type' => 'support_requested', 'owner_id' => $this->employee, 'occurred_at' => $at, 'reason' => 'Synthetic support request'];
        $envelope = $this->managementSourceEnvelope('qa-evidence-v1', 'handoff', $body, $key);
        $envelope['occurred_at'] = $at;

        return app(QaHandoffService::class)->open($actor, $this->p, $envelope, $key);
    }

    protected function managementQaExecutionFixture(int $definitionCount = 1, array $definitionActors = []): array
    {
        foreach (['qa.fixture.manage', 'qa.plan.manage', 'qa.execution.record', 'qa.defect.review', 'qa.source.read', 'qa.read'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
        $definitions = [];
        for ($i = 0; $i < $definitionCount; $i++) {
            $key = 'test-'.$i;
            $producer = $definitionActors[$i]['producer'] ?? $this->actor();
            $reviewer = $definitionActors[$i]['reviewer'] ?? $this->actor();
            $definition = app(QaFixtureService::class)->register($producer, $this->p, $this->managementSourceEnvelope('qa-evidence-v1', 'definition', ['logical_key' => $key, 'revision' => 1, 'criterion_version_id' => $this->criteria[0]['version_id'], 'title' => 'Synthetic test', 'steps' => 'Run test', 'expected_result' => 'Synthetic result', 'test_type' => 'functional', 'release_critical' => false], $key), $key, 0);
            app(QaDefinitionService::class)->review($reviewer, $this->p, $definition['record_id'], ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => 'Synthetic review']);
            $definitions[] = ['definition_version_id' => $definition['record_id'], 'source_instance_id' => $this->managementSource, 'required' => true];
        }
        $entries = [];
        foreach ($this->criteria as $i => $criterion) {
            $item = app(TenantContext::class)->run($this->tenant, fn () => DB::table('baseline_items')->where('baseline_id', $this->baselineId)->where('criterion_version_id', $criterion['version_id'])->first());
            $entries[] = app(QaPlanService::class)->createEntry($this->actor(), $this->p, ['baseline_id' => $this->baselineId, 'baseline_item_id' => $item->id, 'criterion_version_id' => $criterion['version_id'], 'disposition' => 'included', 'reason' => null, 'tests' => $i === 0 ? $definitions : []], 'entry-'.$i);
        }
        $plan = app(QaPlanService::class)->compose($this->actor(), $this->p, ['baseline_id' => $this->baselineId, 'entry_snapshot_ids' => array_column($entries, 'id')], 'plan');
        $planReview = app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic approval']);
        $build = app(QaFixtureService::class)->register($this->actor(), $this->p, $this->managementSourceEnvelope('qa-evidence-v1', 'build', ['logical_key' => 'build', 'revision' => 1, 'title' => 'Synthetic build', 'content_hash' => hash('sha256', 'build')], 'build'), 'build', 0);
        $environment = app(QaFixtureService::class)->register($this->actor(), $this->p, $this->managementSourceEnvelope('qa-evidence-v1', 'environment', ['logical_key' => 'environment', 'revision' => 1, 'title' => 'Synthetic environment', 'content_hash' => hash('sha256', 'environment')], 'environment'), 'environment', 0);
        $selection = app(QaSelectionService::class)->select($this->actor(), $this->p, ['plan_version_id' => $plan['id'], 'build_id' => $build['record_id'], 'environment_version_id' => $environment['record_id'], 'expected_revision' => 0, 'reason' => 'Synthetic selection']);
        $body = ['definition_version_id' => $definitions[0]['definition_version_id'], 'build_id' => $build['record_id'], 'environment_version_id' => $environment['record_id'], 'attempt_ordinal' => 1, 'outcome' => 'failed', 'task_version_id' => $this->managementTask['version_id'], 'locator' => 'synthetic.test'];
        $execution = app(QaExecutionService::class)->register($this->actor(), $this->p, $this->managementSourceEnvelope('qa-evidence-v1', 'execution', $body, 'execution'), 'execution', 0, 0);
        $defectBody = ['logical_key' => 'defect', 'criterion_version_id' => $this->criteria[0]['version_id'], 'definition_version_id' => $definitions[0]['definition_version_id'], 'build_id' => $build['record_id'], 'environment_version_id' => $environment['record_id'], 'task_version_id' => $this->managementTask['version_id'], 'severity' => 'high', 'reproducibility' => 'reproduced', 'reproduction' => 'Synthetic reproduction'];
        $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->managementSourceEnvelope('qa-evidence-v1', 'defect', $defectBody, 'defect'), 'defect');
        $defectEvent = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_defects')->where('id', $defect['id'])->value('current_event_id'));

        return compact('execution', 'defect', 'defectEvent', 'selection', 'plan', 'planReview', 'definitions', 'body');
    }

    protected function actionInput(array $references = []): array
    {
        return ['owner_id' => $this->employee, 'reason' => 'Synthetic escalation', 'references' => $references ?: [$this->lifecycleReference()]];
    }

    protected function managementGrantActor(TrustedActor $actor, array $purposes, ?string $department = null): void
    {
        app(TenantContext::class)->run($this->tenant, function () use ($actor, $purposes, $department) {
            foreach ($purposes as $purpose) {
                $isDepartment = str_starts_with($purpose, 'management.department.');
                DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $isDepartment ? null : $this->p, 'department_id' => $isDepartment ? $department : null, 'user_id' => $actor->id, 'policy_version_id' => $this->policy, 'purpose' => $purpose, 'scope_type' => $isDepartment ? 'department' : 'project', 'valid_from' => now()->subDay()]);
            }
        });
    }

    protected function workflowAssigned(array $references = [], bool $initialize = true): array
    {
        if ($initialize) {
            $this->managementInputs();
        }
        $open = app(ManagementActionService::class)->open($this->actor(), $this->p, $this->managementEnvelope('action.open', $this->actionInput($references)), 'open', 0);
        $head = $this->managementActor('department_head');
        $helper = $this->managementActor('developer');
        $department = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($head, $helper, $department) {
            DB::table('departments')->insert(['id' => $department, 'tenant_id' => $this->tenant, 'name' => 'Synthetic support department']);
            foreach ([$this->employee, DB::table('users')->where('id', $head->id)->value('employee_id'), DB::table('users')->where('id', $helper->id)->value('employee_id')] as $employee) {
                DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
            }
        });
        $this->managementGrantActor($head, ['management.support.assign', 'management.department.manage', 'management.source.read', 'requirements.read', 'progress.read'], $department);
        $this->managementGrantActor($helper, ['management.support.record', 'management.source.read', 'requirements.read', 'progress.read']);
        if (array_filter($references, fn ($r) => str_starts_with($r['kind'], 'qa_'))) {
            $this->managementGrantActor($head, ['qa.read', 'qa.source.read']);
            $this->managementGrantActor($helper, ['qa.read', 'qa.source.read']);
        }
        if (array_filter($references, fn ($r) => str_starts_with($r['kind'], 'ba_'))) {
            $this->managementGrantActor($head, ['ba.read', 'ba.source.read']);
            $this->managementGrantActor($helper, ['ba.read', 'ba.source.read']);
        }
        $helperId = app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $helper->id)->value('employee_id'));
        $assignment = app(ManagementActionService::class)->event($head, $this->p, $open['action_id'], ['operation' => 'assignment.set', 'expected_revision' => 1, 'owner_id' => $this->employee, 'helper_id' => $helperId, 'department_id' => $department, 'reason' => 'Synthetic assignment'], 'assignment');

        return compact('open', 'head', 'helper', 'department', 'assignment');
    }

    protected function workflowSupportBody(array $fixture, int $revision = 2): array
    {
        return app(TenantContext::class)->run($this->tenant, function () use ($fixture, $revision) {
            $assignment = DB::table('management_assignments')->where('id', $fixture['assignment']['assignment_id'])->first();

            return ['action_id' => $fixture['open']['action_id'], 'expected_revision' => $revision, 'action_version_id' => $assignment->action_version_id, 'action_hash' => $assignment->action_hash, 'assignment_id' => $assignment->id, 'assignment_hash' => $assignment->assignment_hash, 'references' => [$this->lifecycleReference()], 'text' => 'Synthetic support result', 'outcome' => 'completed', 'predecessor_support_id' => null];
        });
    }

    protected function workflowReviewBody(array $support, string $operation = 'outcome.confirm'): array
    {
        return app(TenantContext::class)->run($this->tenant, function () use ($support, $operation) {
            $fact = DB::table('management_support_versions')->where('id', $support['support_id'])->first();

            return ['operation' => $operation, 'expected_revision' => $support['revision'], 'action_version_id' => $fact->action_version_id, 'action_hash' => $fact->action_hash, 'assignment_id' => $fact->assignment_id, 'assignment_hash' => $fact->assignment_hash, 'support_id' => $fact->id, 'support_hash' => $fact->support_hash, 'references_hash' => $fact->references_hash, 'reason' => 'Synthetic independent review'];
        });
    }

    protected function managementEnvelope(string $operation, array $body, string $object = 'case', int $revision = 1): array
    {
        $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');

        return ['schema_version' => 'management-evidence-v1', 'operation' => $operation, 'source_instance_id' => $this->managementSource, 'object_id' => $object, 'source_revision' => $revision, 'occurred_at' => $at, 'source_updated_at' => $at, 'body' => $body];
    }

    protected function lifecycleReference(): array
    {
        return ['kind' => 'lifecycle', 'task_version_id' => $this->managementTask['version_id'], 'criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id'], 'relation' => 'blocks'];
    }

    protected function managementRun(Closure $work, array $named = []): mixed
    {
        return app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, ['coverage' => 'complete', 'refs' => [], 'heads' => [], 'proof_slots' => [], 'current_requests' => [], 'named_slots' => $named, 'responsibility_slots' => []], $work);
    }

    protected function managementProofSlot(array $proof, string $subjectKind, string $subjectId, bool $mandatory = false): array
    {
        $slot = ['format' => $proof['version'], 'subject_kind' => $subjectKind, 'subject_id' => $subjectId, 'proof_hash' => ManagementContract::proofHash($proof), 'actor_id' => $proof['actor_id'], 'purposes' => $proof['purposes'], 'pinned_proof' => $proof, 'mandatory' => $mandatory];

        return ['key' => ManagementContract::proofKey($this->tenant, $this->p, $slot)] + $slot;
    }

    protected function seedManagementBoundary(array $actionReferences = [], array $supportReferences = [], string $object = 'seed', bool $exactSource = false, ?TrustedActor $assignmentActor = null, ?string $helper = null): array
    {
        $actionReferences = $actionReferences ?: [$this->lifecycleReference()];
        $supportReferences = $supportReferences ?: [$this->lifecycleReference()];
        $helper ??= $this->employee;
        $assignmentProof = $assignmentActor === null ? null : app(ManagementCoordination::class)->run($assignmentActor, $this->p, ['management.action.manage'], false, ['coverage' => 'complete', 'named_slots' => [['key' => 'fixture:helper', 'employee_id' => $helper, 'origin' => 'management', 'mandatory' => true], ['key' => 'fixture:owner', 'employee_id' => $this->employee, 'origin' => 'management', 'mandatory' => true]]], fn ($proofs) => $proofs['current']['proof']);
        $snapshots = [];
        if ($exactSource) {
            $source = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [...$actionReferences, ...$supportReferences]);
            foreach ($source['references'] as $reference) {
                $snapshots[$reference['reference_hash']] = $reference['source_snapshot_hash'];
            }
        }

        return $this->managementRun(function ($proofs) use ($actionReferences, $supportReferences, $object, $snapshots, $assignmentProof, $helper) {
            $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
            $ids = [];
            foreach (['action', 'version', 'assignment', 'support', 'department'] as $key) {
                $ids[$key] = (string) Str::uuid();
            }
            $proof = ManagementContract::canonical($proofs['current']['proof']);
            $assignmentAuth = ['actor_id' => $assignmentProof['actor_id'] ?? $this->user, 'authority_proof' => $assignmentProof === null ? $proof : ManagementContract::canonical($assignmentProof)];
            $hash = str_repeat('a', 64);
            $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
            DB::table('departments')->insert(['id' => $ids['department'], 'tenant_id' => $this->tenant, 'name' => 'Synthetic management department']);
            DB::table('management_actions')->insert(['id' => $ids['action'], 'source_instance_id' => $this->managementSource, 'object_id' => $object, 'original_opened_at' => $at, 'episode_opened_at' => $at] + $scope);
            $insertReceipt = function (string $operation, array $body, array $output, bool $source, ?array $auth = null) use ($scope, $ids, $proof, $hash, $object): array {
                $receipt = (string) Str::uuid();
                $event = (string) Str::uuid();
                $envelope = $source ? $this->managementEnvelope($operation, $body, $object === 'seed' ? $operation : $operation.'.'.$object, 1) : ['operation' => $operation, 'expected_revision' => 1] + $body;
                DB::table('management_receipts')->insert(['id' => $receipt, 'variant' => $source ? 'source' : 'human', 'operation' => $operation, 'input_hash' => $hash, 'envelope' => ManagementContract::canonical($envelope), 'action_id' => $ids['action'], 'event_id' => $event] + ($auth ?? ['actor_id' => $this->user, 'authority_proof' => $proof]) + ($source ? ['family' => $operation === 'action.open' ? 'action' : 'support', 'source_instance_id' => $this->managementSource, 'object_id' => $envelope['object_id'], 'source_revision' => 1, 'occurred_at' => $envelope['occurred_at'], 'source_updated_at' => $envelope['source_updated_at']] : []) + $output + $scope);

                return ['receipt' => $receipt, 'event' => $event, 'envelope' => $envelope];
            };
            $insertRefs = function (?string $version, ?string $support) use ($scope, $ids, $hash, $actionReferences, $supportReferences, $snapshots): string {
                $set = (string) Str::uuid();
                DB::table('management_reference_sets')->insert(['id' => $set, 'action_id' => $ids['action'], 'action_version_id' => $version, 'support_id' => $support, 'references_hash' => $hash] + $scope);
                $rv = DB::table('requirement_version_criteria')->where('project_id', $this->p)->where('criterion_version_id', $this->criteria[0]['version_id'])->first();
                $requirementHash = DB::table('requirement_versions')->where('id', $rv->requirement_version_id)->value('content_hash');
                foreach ($version ? $actionReferences : $supportReferences as $ordinal => $reference) {
                    $refHash = hash('sha256', ManagementContract::canonical($reference));
                    DB::table('management_references')->insert(['id' => (string) Str::uuid(), 'reference_set_id' => $set, 'ordinal' => $ordinal + 1, 'requirement_version_id' => $rv->requirement_version_id, 'requirement_hash' => $requirementHash, 'source_snapshot_hash' => $snapshots[$refHash] ?? $hash, 'reference_hash' => $refHash] + $reference + $scope);
                }

                return $set;
            };
            $open = $insertReceipt('action.open', $this->actionInput($actionReferences), ['action_version_id' => $ids['version']], true);
            DB::table('management_action_versions')->insert(['id' => $ids['version'], 'action_id' => $ids['action'], 'revision' => 1, 'episode' => 1, 'owner_id' => $this->employee, 'requester_id' => $this->employee, 'reason' => 'Synthetic escalation', 'occurred_at' => $at, 'action_hash' => $hash, 'references_hash' => $hash, 'receipt_id' => $open['receipt'], 'actor_id' => $this->user, 'authority_proof' => $proof] + $scope);
            $ids['action_set'] = $insertRefs($ids['version'], null);
            $eventBase = ['action_id' => $ids['action'], 'episode' => 1, 'action_version_id' => $ids['version'], 'actor_id' => $this->user, 'authority_proof' => $proof] + $scope;
            DB::table('management_events')->insert(['id' => $open['event'], 'revision' => 1, 'operation' => 'action.open', 'state' => 'open', 'support_status' => 'none', 'review_status' => 'unverified', 'receipt_id' => $open['receipt']] + $eventBase);
            DB::table('management_source_heads')->insert(['id' => (string) Str::uuid(), 'source_instance_id' => $this->managementSource, 'family' => 'action', 'object_id' => $open['envelope']['object_id'], 'source_revision' => 1, 'revision' => 1, 'receipt_id' => $open['receipt'], 'complete' => true] + $scope);
            DB::table('management_actions')->where('id', $ids['action'])->update(['revision' => 1, 'current_event_id' => $open['event'], 'current_action_version_id' => $ids['version']]);
            $assignmentBody = ['owner_id' => $this->employee, 'helper_id' => $helper, 'department_id' => $ids['department'], 'reason' => 'Synthetic assignment'];
            $assignment = $insertReceipt('assignment.set', $assignmentBody, ['assignment_id' => $ids['assignment']], false, $assignmentAuth);
            DB::table('management_assignments')->insert(['id' => $ids['assignment'], 'action_id' => $ids['action'], 'action_version_id' => $ids['version'], 'action_hash' => $hash, 'assignment_hash' => $hash, 'receipt_id' => $assignment['receipt']] + $assignmentAuth + $assignmentBody + $scope);
            DB::table('management_events')->insert(['id' => $assignment['event'], 'revision' => 2, 'operation' => 'assignment.set', 'state' => 'assigned', 'support_status' => 'none', 'review_status' => 'unverified', 'assignment_id' => $ids['assignment'], 'receipt_id' => $assignment['receipt'], 'reason' => 'Synthetic assignment'] + $assignmentAuth + $eventBase);
            DB::table('management_actions')->where('id', $ids['action'])->update(['revision' => 2, 'state' => 'assigned', 'current_event_id' => $assignment['event'], 'current_assignment_id' => $ids['assignment']]);
            $supportBody = ['action_id' => $ids['action'], 'expected_revision' => 2, 'action_version_id' => $ids['version'], 'action_hash' => $hash, 'assignment_id' => $ids['assignment'], 'assignment_hash' => $hash, 'references' => $supportReferences, 'text' => 'Synthetic support', 'outcome' => 'completed', 'predecessor_support_id' => null];
            $support = $insertReceipt('support.record', $supportBody, ['support_id' => $ids['support']], true);
            $supportRow = $supportBody;
            unset($supportRow['expected_revision'], $supportRow['references']);
            DB::table('management_support_versions')->insert(['id' => $ids['support'], 'operation' => 'support.record', 'helper_id' => $helper, 'recorder_id' => $this->employee, 'support_hash' => $hash, 'references_hash' => $hash, 'source_instance_id' => $this->managementSource, 'object_id' => $support['envelope']['object_id'], 'source_revision' => 1, 'receipt_id' => $support['receipt'], 'actor_id' => $this->user, 'authority_proof' => $proof, 'occurred_at' => $support['envelope']['occurred_at'], 'source_updated_at' => $support['envelope']['source_updated_at']] + $supportRow + $scope);
            $ids['support_set'] = $insertRefs(null, $ids['support']);
            DB::table('management_events')->insert(['id' => $support['event'], 'revision' => 3, 'operation' => 'support.record', 'state' => 'awaiting_review', 'support_status' => 'recorded', 'review_status' => 'unverified', 'assignment_id' => $ids['assignment'], 'support_id' => $ids['support'], 'subject_support_id' => $ids['support'], 'receipt_id' => $support['receipt']] + $eventBase);
            DB::table('management_source_heads')->insert(['id' => (string) Str::uuid(), 'source_instance_id' => $this->managementSource, 'family' => 'support', 'object_id' => $support['envelope']['object_id'], 'source_revision' => 1, 'revision' => 1, 'receipt_id' => $support['receipt'], 'complete' => true] + $scope);
            DB::table('management_actions')->where('id', $ids['action'])->update(['revision' => 3, 'state' => 'awaiting_review', 'support_status' => 'recorded', 'current_event_id' => $support['event'], 'current_support_id' => $ids['support']]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $ids + ['proof' => $proofs['current']['proof'], 'hash' => $hash];
        });
    }

    protected function seedManagementSourceSubject(array $ids, int $sourceRevision, bool $late = false, bool $projectOld = false, string $object = 'support.record', ?string $occurredAt = null): array
    {
        return $this->managementRun(function ($proofs) use ($ids, $sourceRevision, $late, $projectOld, $object, $occurredAt) {
            $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
            $auth = ['actor_id' => $this->user, 'authority_proof' => ManagementContract::canonical($proofs['current']['proof'])];
            $row = (array) DB::table('management_support_versions')->where('id', $ids['support'])->first();
            $receipt = (string) Str::uuid();
            $subject = (string) Str::uuid();
            $event = (string) Str::uuid();
            $head = (array) DB::table('management_events')->where('action_id', $ids['action'])->orderByDesc('revision')->first();
            $operation = $late || $sourceRevision === 1 ? 'support.record' : 'support.correct';
            $body = ['action_id' => $ids['action'], 'expected_revision' => $head['revision'], 'action_version_id' => $ids['version'], 'action_hash' => $ids['hash'], 'assignment_id' => $ids['assignment'], 'assignment_hash' => $ids['hash'], 'references' => [$this->lifecycleReference()], 'text' => 'Synthetic later source subject', 'outcome' => 'partial', 'predecessor_support_id' => $late || $sourceRevision === 1 ? null : $ids['support']];
            $envelope = $this->managementEnvelope($operation, $body, $object, $sourceRevision);
            if ($occurredAt !== null) {
                $envelope['occurred_at'] = $occurredAt;
            }
            DB::table('management_receipts')->insert(['id' => $receipt, 'variant' => 'source', 'operation' => $operation, 'family' => 'support', 'source_instance_id' => $this->managementSource, 'object_id' => $object, 'source_revision' => $sourceRevision, 'occurred_at' => $envelope['occurred_at'], 'source_updated_at' => $envelope['source_updated_at'], 'input_hash' => $ids['hash'], 'envelope' => ManagementContract::canonical($envelope), 'action_id' => $ids['action'], 'support_id' => $subject, 'event_id' => $event] + $auth + $scope);
            $row = array_replace($row, ['id' => $subject, 'operation' => $operation, 'receipt_id' => $receipt, 'object_id' => $object, 'source_revision' => $sourceRevision, 'text' => $body['text'], 'outcome' => 'partial', 'predecessor_support_id' => $body['predecessor_support_id'], 'occurred_at' => $envelope['occurred_at'], 'source_updated_at' => $envelope['source_updated_at']], $auth);
            $row['assignment_id'] = $ids['assignment'];
            $row['helper_id'] = DB::table('management_assignments')->where('id', $ids['assignment'])->value('helper_id');
            DB::table('management_support_versions')->insert($row);
            $set = (string) Str::uuid();
            DB::table('management_reference_sets')->insert(['id' => $set, 'action_id' => $ids['action'], 'support_id' => $subject, 'references_hash' => $ids['hash']] + $scope);
            $reference = (array) DB::table('management_references')->where('reference_set_id', $ids['support_set'])->first();
            DB::table('management_references')->insert(array_replace($reference, ['id' => (string) Str::uuid(), 'reference_set_id' => $set]));
            $newHead = array_replace($head, ['id' => $event, 'revision' => $head['revision'] + 1, 'operation' => $operation, 'receipt_id' => $receipt, 'subject_support_id' => $subject, 'reason' => null, 'context' => null], $auth);
            unset($newHead['project_generation']);
            $projection = ['revision' => $newHead['revision'], 'current_event_id' => $event];
            if (! $late) {
                $newHead = array_replace($newHead, ['support_id' => $subject, 'state' => 'awaiting_review', 'support_status' => 'recorded', 'review_status' => 'unverified', 'current_outcome' => null, 'review_id' => null]);
                $projection += ['current_support_id' => $subject, 'state' => 'awaiting_review', 'support_status' => 'recorded', 'review_status' => 'unverified', 'current_outcome' => null, 'current_review_id' => null];
                if ($projectOld) {
                    $newHead['support_id'] = $ids['support'];
                    $projection['current_support_id'] = $ids['support'];
                }
            }
            DB::table('management_events')->insert($newHead);
            if (! $late) {
                $sourceHead = DB::table('management_source_heads')->where('project_id', $this->p)->where('source_instance_id', $this->managementSource)->where('family', 'support')->where('object_id', $object)->first();
                if ($sourceHead) {
                    DB::table('management_source_heads')->where('id', $sourceHead->id)->update(['revision' => $sourceHead->revision + 1, 'source_revision' => $sourceRevision, 'receipt_id' => $receipt, 'complete' => $sourceHead->complete && $sourceRevision === $sourceHead->source_revision + 1]);
                } else {
                    DB::table('management_source_heads')->insert(['id' => (string) Str::uuid(), 'source_instance_id' => $this->managementSource, 'family' => 'support', 'object_id' => $object, 'source_revision' => $sourceRevision, 'revision' => 1, 'receipt_id' => $receipt, 'complete' => $sourceRevision === 1] + $scope);
                }
            }
            DB::table('management_actions')->where('id', $ids['action'])->update($projection);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return array_replace($ids, ['support' => $late ? $ids['support'] : $subject, 'subject' => $subject, 'support_set' => $late ? $ids['support_set'] : $set, 'revision' => $newHead['revision']]);
        });
    }

    protected function seedManagementReassignment(array $ids): array
    {
        return $this->managementRun(function ($proofs) use ($ids) {
            $head = (array) DB::table('management_events')->where('action_id', $ids['action'])->orderByDesc('revision')->first();
            $assignment = (array) DB::table('management_assignments')->where('id', $ids['assignment'])->first();
            $id = (string) Str::uuid();
            $receipt = (string) Str::uuid();
            $event = (string) Str::uuid();
            $body = ['operation' => 'assignment.set', 'expected_revision' => $head['revision'], 'owner_id' => $this->employee, 'helper_id' => $this->employee, 'department_id' => $assignment['department_id'], 'reason' => 'Synthetic replacement assignment'];
            $auth = ['actor_id' => $this->user, 'authority_proof' => ManagementContract::canonical($proofs['current']['proof'])];
            DB::table('management_receipts')->insert(['id' => $receipt, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'variant' => 'human', 'operation' => 'assignment.set', 'input_hash' => $ids['hash'], 'envelope' => ManagementContract::canonical($body), 'action_id' => $ids['action'], 'assignment_id' => $id, 'event_id' => $event] + $auth);
            unset($assignment['assigned_at']);
            DB::table('management_assignments')->insert(array_replace($assignment, ['id' => $id, 'receipt_id' => $receipt, 'helper_id' => $this->employee, 'reason' => $body['reason']], $auth));
            unset($head['project_generation']);
            $head = array_replace($head, ['id' => $event, 'revision' => $head['revision'] + 1, 'operation' => 'assignment.set', 'state' => 'assigned', 'support_status' => 'none', 'review_status' => 'unverified', 'current_outcome' => null, 'assignment_id' => $id, 'support_id' => null, 'subject_support_id' => null, 'review_id' => null, 'receipt_id' => $receipt, 'reason' => $body['reason'], 'context' => null], $auth);
            DB::table('management_events')->insert($head);
            DB::table('management_actions')->where('id', $ids['action'])->update(['revision' => $head['revision'], 'current_event_id' => $event, 'current_assignment_id' => $id, 'current_support_id' => null, 'current_review_id' => null, 'state' => 'assigned', 'support_status' => 'none', 'review_status' => 'unverified', 'current_outcome' => null]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return array_replace($ids, ['assignment' => $id, 'revision' => $head['revision']]);
        });
    }

    protected function seedManagementConfirmation(array $ids): array
    {
        $reviewer = $this->managementActor('pm');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $reviewer->id, 'policy_version_id' => $this->policy, 'purpose' => 'management.outcome.review', 'scope_type' => 'project', 'valid_from' => now()->subDay()]));

        return app(ManagementCoordination::class)->run($reviewer, $this->p, ['management.outcome.review'], false, ['coverage' => 'complete'], function ($proofs) use ($reviewer, $ids) {
            $proof = $proofs['current']['proof'];
            $outcome = DB::table('management_support_versions')->where('id', $ids['support'])->value('outcome');
            $body = ['operation' => 'outcome.confirm', 'expected_revision' => ($ids['revision'] ?? 3), 'action_version_id' => $ids['version'], 'action_hash' => $ids['hash'], 'assignment_id' => $ids['assignment'], 'assignment_hash' => $ids['hash'], 'support_id' => $ids['support'], 'support_hash' => $ids['hash'], 'references_hash' => $ids['hash'], 'reason' => 'Synthetic independent review'];
            $receipt = (string) Str::uuid();
            $review = (string) Str::uuid();
            $event = (string) Str::uuid();
            $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
            $auth = ['actor_id' => $reviewer->id, 'authority_proof' => ManagementContract::canonical($proof)];
            DB::table('management_receipts')->insert(['id' => $receipt, 'variant' => 'human', 'operation' => 'outcome.confirm', 'input_hash' => $ids['hash'], 'envelope' => ManagementContract::canonical($body), 'action_id' => $ids['action'], 'review_id' => $review, 'event_id' => $event] + $auth + $scope);
            $row = $body;
            unset($row['expected_revision']);
            DB::table('management_reviews')->insert(['id' => $review, 'action_id' => $ids['action'], 'reviewer_id' => $proof['employee_id'], 'receipt_id' => $receipt, 'review_hash' => $ids['hash']] + $row + $auth + $scope);
            DB::table('management_events')->insert(['id' => $event, 'action_id' => $ids['action'], 'revision' => ($ids['revision'] ?? 3) + 1, 'episode' => 1, 'operation' => 'outcome.confirm', 'state' => 'reviewed', 'support_status' => 'recorded', 'review_status' => 'confirmed', 'current_outcome' => $outcome, 'action_version_id' => $ids['version'], 'assignment_id' => $ids['assignment'], 'support_id' => $ids['support'], 'review_id' => $review, 'receipt_id' => $receipt, 'reason' => $body['reason']] + $auth + $scope);
            DB::table('management_actions')->where('id', $ids['action'])->update(['revision' => ($ids['revision'] ?? 3) + 1, 'state' => 'reviewed', 'review_status' => 'confirmed', 'current_outcome' => $outcome, 'current_review_id' => $review, 'current_event_id' => $event]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return array_replace($ids, ['review' => $review, 'revision' => ($ids['revision'] ?? 3) + 1]);
        });
    }

    protected function seedManagementContext(array $ids): array
    {
        return $this->managementRun(function ($proofs) use ($ids) {
            $head = (array) DB::table('management_events')->where('action_id', $ids['action'])->where('revision', $ids['revision'])->first();
            $receipt = (string) Str::uuid();
            $event = (string) Str::uuid();
            $body = ['operation' => 'context.add', 'expected_revision' => $ids['revision'], 'context' => 'Synthetic reviewed context'];
            $auth = ['actor_id' => $this->user, 'authority_proof' => ManagementContract::canonical($proofs['current']['proof'])];
            DB::table('management_receipts')->insert(['id' => $receipt, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'variant' => 'human', 'operation' => 'context.add', 'input_hash' => hash('sha256', ManagementContract::canonical($body)), 'envelope' => ManagementContract::canonical($body), 'action_id' => $ids['action'], 'event_id' => $event] + $auth);
            unset($head['project_generation'], $head['created_at']);
            $head = array_replace($head, ['id' => $event, 'revision' => $ids['revision'] + 1, 'operation' => 'context.add', 'receipt_id' => $receipt, 'subject_support_id' => null, 'context' => $body['context'], 'reason' => null], $auth);
            DB::table('management_events')->insert($head);
            DB::table('management_actions')->where('id', $ids['action'])->update(['revision' => $head['revision'], 'current_event_id' => $event]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return array_replace($ids, ['revision' => $head['revision']]);
        });
    }
}
