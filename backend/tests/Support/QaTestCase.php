<?php

namespace Tests\Support;

use App\Domain\Access\TrustedActor;
use App\Domain\DeveloperAnalysis\SyntheticMrRegistry;
use App\Domain\Lifecycle\RequirementService;
use App\Domain\Lifecycle\TaskService;
use App\Domain\Qa\QaContract;
use App\Domain\Qa\QaCoordination;
use App\Domain\Qa\QaDefectService;
use App\Domain\Qa\QaDefinitionService;
use App\Domain\Qa\QaEligibility;
use App\Domain\Qa\QaExecutionService;
use App\Domain\Qa\QaFixtureService;
use App\Domain\Qa\QaHandoffService;
use App\Domain\Qa\QaPlanService;
use App\Domain\Qa\QaReceiptWriter;
use App\Domain\Qa\QaSelectionService;
use App\Domain\Qa\QaVerificationCalculator;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

abstract class QaTestCase extends BaselineTestCase
{
    protected array $historicalClose = [];

    protected string $handoffId;

    protected array $executionInstants = [];

    protected function defectReport(array $body, ?string $execution = null, string $key = 'bug-a'): array
    {
        return $this->qaEnvelope('defect', ['logical_key' => $key, 'criterion_version_id' => $this->criteria[0]['version_id'], 'definition_version_id' => $body['definition_version_id'], 'build_id' => $body['build_id'], 'environment_version_id' => $body['environment_version_id'], 'task_version_id' => $body['task_version_id'], 'severity' => 'high', 'reproducibility' => 'reproduced', 'reproduction' => 'Synthetic reproduction'] + ($execution ? ['execution_id' => $execution] : []), $key);
    }

    protected function qaFixSnapshot(): string
    {
        $this->grant('analysis.fixture.manage', $this->p);
        $fixture = require __DIR__.'/../Fixtures/developer-mr.php';
        $fixture['mr_id'] = 'synthetic-'.Str::uuid();

        return app(SyntheticMrRegistry::class)->register($this->actor(), $this->p, $fixture, 0)['snapshot_id'];
    }

    protected function affectedDefectPins(array $body): array
    {
        return ['criterion_version_id' => $this->criteria[0]['version_id'], 'definition_version_id' => $body['definition_version_id'], 'build_id' => $body['build_id'], 'environment_version_id' => $body['environment_version_id'], 'task_version_id' => $body['task_version_id']];
    }

    protected function fixedDefect(array $body, array $defect): array
    {
        $this->defectTransition($defect['id'], 1, 'triaged', []);

        return $this->defectTransition($defect['id'], 2, 'fix_requested', $this->affectedDefectPins($body) + ['fix_snapshot_id' => $this->qaFixSnapshot()]);
    }

    protected function defectTransition(string $defect, int $revision, string $kind, array $pins): array
    {
        return app(QaDefectService::class)->transition($this->actor(), $this->p, $defect, ['expected_revision' => $revision, 'kind' => $kind, 'reason' => 'Synthetic human review', 'pins' => $pins ?: new \stdClass]);
    }

    protected function closeUsingHistoricalPass(): array
    {
        return $this->defectTransition($this->historicalClose[0], $this->historicalClose[1], 'verified_closed', $this->historicalClose[2]);
    }

    protected function defectWriteCounts(): array
    {
        return app(TenantContext::class)->run($this->tenant, function () {
            $counts = [];
            foreach (['qa_defects', 'qa_defect_events', 'qa_handoffs', 'qa_handoff_events', 'qa_changes', 'qa_outbox', 'audit_events', 'qa_receipts', 'qa_request_keys'] as $table) {
                $counts[$table] = DB::table($table)->where('project_id', $this->p)->count();
            }
            $counts['defect_heads'] = DB::table('qa_defects')->where('project_id', $this->p)->orderBy('id')->get(['id', 'revision', 'current_event_id', 'state', 'disposition'])->map(fn ($row) => (array) $row)->all();
            $counts['handoff_heads'] = DB::table('qa_handoffs')->where('project_id', $this->p)->orderBy('id')->get(['id', 'revision', 'current_event_id', 'state', 'owner_id'])->map(fn ($row) => (array) $row)->all();

            return $counts;
        });
    }

    protected function qaDefectRow(string $id): object
    {
        return app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_defects')->find($id));
    }

    protected function qaExecutionRow(string $id): object
    {
        return app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_execution_versions')->find($id));
    }

    protected function currentQaSelection(): string
    {
        return app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_project_state')->where('project_id', $this->p)->value('current_selection_id'));
    }

    protected function handoffData(array $body): array
    {
        $envelope = $this->qaEnvelope('handoff', ['task_version_id' => $body['task_version_id'], 'criterion_version_id' => $this->criteria[0]['version_id'], 'owner_id' => $this->employee, 'handoff_type' => 'support_requested', 'reason' => 'Synthetic support request', 'occurred_at' => CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z')], 'handoff');
        $envelope['occurred_at'] = $envelope['source_updated_at'] = $envelope['body']['occurred_at'];

        return $envelope;
    }

    protected function handoffTransition(int $revision, string $kind, array $pins = []): array
    {
        return app(QaHandoffService::class)->transition($this->actor(), $this->p, $this->handoffId, ['expected_revision' => $revision, 'kind' => $kind, 'reason' => 'Synthetic reviewed handoff', 'pins' => $pins ?: new \stdClass]);
    }

    protected function handoffHistory(): array
    {
        return app(QaHandoffService::class)->history($this->actor(), $this->p, $this->handoffId)['events'];
    }

    protected function handoffAge(): array
    {
        return app(QaHandoffService::class)->history($this->actor(), $this->p, $this->handoffId)['age'];
    }

    protected function executionInputs(): array
    {
        $definition = $this->reviewedDefinition();
        $build = $this->qaBuild();
        $environment = $this->qaEnvironment();
        $this->grant('task.manage', $this->p);
        $task = $this->qaTask();

        return [['definition_version_id' => $definition['id'], 'build_id' => $build['record_id'], 'environment_version_id' => $environment['record_id'], 'attempt_ordinal' => 1, 'outcome' => 'passed', 'task_version_id' => $task['version_id'], 'locator' => 'synthetic.result'], $definition];
    }

    protected function selectedExecutionInputs(): array
    {
        [$body, $definition] = $this->executionInputs();
        $plan = $this->composeEntries([$this->planEntry(0, [['definition_version_id' => $definition['id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(1), $this->planEntry(2)]);
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic approval']);
        $this->selectPlan($plan['id'], $body['build_id'], $body['environment_version_id'], 0);

        return [$body, $plan];
    }

    protected function execution(array $body, string $object = 'execution-1', int $revision = 1, int $sourceRevision = 0, int $seriesRevision = 0): array
    {
        $envelope = $this->qaEnvelope('execution', $body, $object, $revision);
        // Capture after task creation, once per scoped source version, so calendar rollover and exact replay are stable.
        $identity = QaContract::canonical([$this->p, $envelope['source_instance_id'], $object, $revision]);
        $this->executionInstants[$identity] ??= CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $envelope['occurred_at'] = $envelope['source_updated_at'] = $this->executionInstants[$identity];

        return app(QaExecutionService::class)->register($this->actor(), $this->p, $envelope, $object.'-'.$revision, $sourceRevision, $seriesRevision);
    }

    protected function eligibilityInputs(): array
    {
        $eligibility = app(QaEligibility::class);
        $graph = $eligibility->collectSelectionPins($this->actor(), $this->p);

        return $this->qaRun(fn ($proofs) => ['graph' => $graph, 'proofs' => $proofs, 'inputs' => $eligibility->loadInputsLocked($this->p, $graph)], $graph['requests']);
    }

    protected function verification(): array
    {
        $eligible = $this->eligibilityInputs();

        return app(QaVerificationCalculator::class)->calculate(app(QaEligibility::class)->inspectLocked($this->p, $eligible['inputs'], $eligible['proofs']));
    }

    protected function seedMaximumExecutions(array $definitions, string $build, string $environment): void
    {
        app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.execution.record', 'qa.source.read'], false, function ($proofs) use ($definitions, $build, $environment) {
            $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
            $proof = QaContract::canonical($proofs['current']['proof']);
            $source = $this->sourceId();
            $receipts = $heads = $series = $attempts = $versions = $updates = [];
            foreach ($definitions as $index => $tests) {
                foreach ($tests as $number => $test) {
                    $key = 'maximum-execution-'.$index.'-'.$number;
                    $receipt = (string) Str::uuid();
                    $seriesId = (string) Str::uuid();
                    $attempt = (string) Str::uuid();
                    $version = (string) Str::uuid();
                    $body = ['definition_version_id' => $test['definition_version_id'], 'build_id' => $build, 'environment_version_id' => $environment, 'attempt_ordinal' => 1, 'outcome' => 'passed', 'task_version_id' => (string) Str::uuid(), 'locator' => 'synthetic.maximum'];
                    $envelope = $this->qaEnvelope('execution', $body, $key);
                    $pins = ['criterion_id' => $this->criteria[$index]['id'], 'criterion_version_id' => $this->criteria[$index]['version_id'], 'definition_id' => $test['definition_version_id'], 'build_id' => $build, 'environment_id' => $environment];
                    $receipts[] = ['id' => $receipt, 'source_instance_id' => $source, 'object_id' => $key, 'source_revision' => 1, 'kind' => 'execution', 'payload_hash' => hash('sha256', QaContract::canonical($envelope)), 'envelope' => QaContract::canonical($envelope), 'actor_id' => $this->user, 'authority_proof' => $proof, 'occurred_at' => $envelope['occurred_at'], 'source_updated_at' => $envelope['source_updated_at']] + $scope;
                    $heads[] = ['id' => (string) Str::uuid(), 'source_instance_id' => $source, 'object_id' => $key, 'source_revision' => 1, 'revision' => 1, 'receipt_id' => $receipt] + $scope;
                    $series[] = ['id' => $seriesId, 'source_instance_id' => $source] + $scope + $pins;
                    $attempts[] = ['id' => $attempt, 'series_id' => $seriesId, 'ordinal' => 1, 'execution_key' => $key] + $scope;
                    $versions[] = ['id' => $version, 'series_id' => $seriesId, 'attempt_id' => $attempt, 'receipt_id' => $receipt, 'source_revision' => 1, 'outcome' => 'passed', 'content_hash' => hash('sha256', QaContract::canonical($body)), 'responsibility' => '{"attribution":"unknown","reason_code":"synthetic_maximum_fixture"}', 'occurred_at' => $envelope['occurred_at']] + $scope + $pins;
                    $updates[] = ['series' => $seriesId, 'attempt' => $attempt, 'version' => $version];
                }
            }
            // Bulk synthetic setup still passes the actual nonowner RLS/FK/immutability guards.
            foreach (['qa_receipts' => $receipts, 'qa_source_heads' => $heads, 'qa_execution_series' => $series, 'qa_execution_attempts' => $attempts, 'qa_execution_versions' => $versions] as $table => $rows) {
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
            $json = json_encode($updates, JSON_THROW_ON_ERROR);
            DB::update('UPDATE qa_execution_attempts a SET revision=1,source_revision=1,current_version_id=u.version FROM jsonb_to_recordset(?::jsonb) AS u(series uuid,attempt uuid,version uuid) WHERE a.project_id=? AND a.id=u.attempt', [$json, $this->p]);
            DB::update('UPDATE qa_execution_series s SET revision=1,current_ordinal=1,current_attempt_id=u.attempt FROM jsonb_to_recordset(?::jsonb) AS u(series uuid,attempt uuid,version uuid) WHERE s.project_id=? AND s.id=u.series', [$json, $this->p]);
        });
    }

    protected function executionFixRequest(array $body, string $key = 'fix-request-fixture'): string
    {
        return app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.defect.review', 'qa.source.read'], false, function ($proofs) use ($body, $key) {
            $definition = DB::table('qa_definition_versions')->find($body['definition_version_id']);
            $pins = ['criterion_id' => $definition->criterion_id, 'criterion_version_id' => $definition->criterion_version_id, 'definition_id' => $definition->id, 'build_id' => $body['build_id'], 'environment_id' => $body['environment_version_id']];
            $report = ['logical_key' => $key, 'criterion_version_id' => $definition->criterion_version_id, 'definition_version_id' => $definition->id, 'build_id' => $body['build_id'], 'environment_version_id' => $body['environment_version_id'], 'task_version_id' => $body['task_version_id'], 'severity' => 'high', 'reproducibility' => 'reproduced', 'reproduction' => 'Synthetic exact request fixture'];
            $receipt = app(QaReceiptWriter::class)->recordLocked($this->actor(), $this->p, $this->qaEnvelope('defect', $report, $key), $key, 0, $proofs['current']['proof']);
            $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
            $id = (string) Str::uuid();
            DB::table('qa_defects')->insert(['id' => $id, 'receipt_id' => $receipt['receipt_id'], 'logical_key' => $key, 'severity' => 'high', 'reproducibility' => 'reproduced', 'reproduction' => $report['reproduction']] + $scope + $pins);
            foreach ([1 => ['opened', 'open'], 2 => ['triaged', 'triaged'], 3 => ['fix_requested', 'fixed_awaiting_retest']] as $revision => [$type, $state]) {
                $event = (string) Str::uuid();
                DB::table('qa_defect_events')->insert(['id' => $event, 'defect_id' => $id, 'revision' => $revision, 'event_type' => $type, 'state' => $state, 'reason' => 'Synthetic typed request fixture', 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($proofs['current']['proof']), 'task_version_id' => $body['task_version_id']] + $scope + $pins);
                DB::table('qa_defects')->where('id', $id)->update(['revision' => $revision, 'current_event_id' => $event, 'state' => $state]);
            }

            return $event;
        });
    }

    protected function qaInputs(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        foreach (['qa.fixture.manage', 'qa.plan.manage', 'qa.execution.record', 'qa.defect.review', 'qa.handoff.manage', 'qa.read', 'qa.source.read', 'qa.process'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
    }

    protected function qaRun(Closure $work, array $requests = []): mixed
    {
        return app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.read'], false, $work, $requests);
    }

    protected function runtimeOwnsAnyQaTable(): bool
    {
        return (bool) DB::scalar("select exists(select 1 from pg_class c join pg_roles r on r.oid=c.relowner where c.relname like 'qa_%' and c.relkind='r' and r.rolname=current_user)");
    }

    protected function allQaTablesForceRls(): bool
    {
        return (int) DB::scalar("select count(*) from pg_class where relname like 'qa_%' and relname not in ('qa_test_identities','qa_observations','qa_observation_heads') and relkind='r' and relrowsecurity and relforcerowsecurity") === 25;
    }

    protected function assertSqlState(string $state, Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected database rejection.');
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0]);
        }
    }

    protected function assertDenied(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected authorization denial.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    protected function captureTenantOnlyQaGrant(): array
    {
        $this->inputs();
        $this->grant('qa.read');

        return $this->qaRun(fn ($proofs) => $proofs);
    }

    protected function rewriteQaReviewHistory(): void
    {
        DB::table('qa_reviews')->update(['reason' => 'Changed']);
    }

    protected function crossCriterionExecutionWrite(): void
    {
        $this->seedQaLineage(true);
    }

    protected function qaEnvelope(string $kind, array $body, string $object = 'sample', int $revision = 1): array
    {
        return ['schema_version' => 'qa-evidence-v1', 'operation' => $kind, 'source_instance_id' => $this->envelopeSource(), 'object_id' => $object, 'source_revision' => $revision, 'occurred_at' => '2026-10-06T00:00:00.000000Z', 'source_updated_at' => '2026-10-06T00:00:00.000000Z', 'body' => $body];
    }

    protected function envelopeSource(): string
    {
        return app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_connectors')->where('id', $this->connector)->value('source_instance_id'));
    }

    protected function sourceId(): string
    {
        return $this->envelopeSource();
    }

    protected function definitionBody(int $index = 0, int $revision = 1, string $key = 'test-a'): array
    {
        return ['logical_key' => $key, 'revision' => $revision, 'criterion_version_id' => $this->criteria[$index]['version_id'], 'title' => 'Synthetic test', 'steps' => 'Run synthetic test', 'expected_result' => 'Synthetic result', 'test_type' => 'functional', 'release_critical' => false];
    }

    protected function reviewedDefinition(int $index = 0, string $key = 'test-a'): array
    {
        $registered = app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('definition', $this->definitionBody($index, key: $key), $key), $key, 0);
        $review = app(QaDefinitionService::class)->review($this->actor(), $this->p, $registered['record_id'], ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => 'Synthetic review']);

        return ['id' => $registered['record_id'], 'review_id' => $review['id'], 'review_revision' => $review['revision'], 'source_head_revision' => $registered['source_head_revision']];
    }

    protected function entryInput(int $index, array $tests = [], bool $included = true, ?string $baseline = null): array
    {
        $baseline ??= $this->baselineId;
        $item = app(TenantContext::class)->run($this->tenant, fn () => DB::table('baseline_items')->where('baseline_id', $baseline)->where('criterion_version_id', $this->criteria[$index]['version_id'])->first());

        return ['baseline_id' => $baseline, 'baseline_item_id' => $item->id, 'criterion_version_id' => $this->criteria[$index]['version_id'], 'disposition' => $included ? 'included' : 'excluded', 'reason' => $included ? null : 'Explicit synthetic exclusion', 'tests' => $tests];
    }

    protected function planEntry(int $index, array $tests = [], bool $included = true, ?string $key = null): array
    {
        return app(QaPlanService::class)->createEntry($this->actor(), $this->p, $this->entryInput($index, $tests, $included), $key ?? 'entry-'.$index);
    }

    protected function composeEntries(array $entries, string $key = 'compose'): array
    {
        return app(QaPlanService::class)->compose($this->actor(), $this->p, ['baseline_id' => $this->baselineId, 'entry_snapshot_ids' => array_column($entries, 'id')], $key);
    }

    protected function approvedPlan(): array
    {
        $definition = $this->reviewedDefinition();
        $plan = $this->composeEntries([$this->planEntry(0, [['definition_version_id' => $definition['id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(1), $this->planEntry(2)]);
        $review = app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic approval']);

        return $plan + ['review_id' => $review['id'], 'review_revision' => $review['revision']];
    }

    protected function qaBuild(int $revision = 1, int $expectedSourceRevision = 0): array
    {
        return app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('build', ['logical_key' => 'build', 'revision' => $revision, 'title' => 'Synthetic build', 'content_hash' => hash('sha256', 'build-'.$revision)], 'build', $revision), 'build-'.$revision, $expectedSourceRevision);
    }

    protected function qaEnvironment(int $revision = 1, int $expectedSourceRevision = 0): array
    {
        return app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('environment', ['logical_key' => 'environment', 'revision' => $revision, 'title' => 'Synthetic environment', 'content_hash' => hash('sha256', 'environment-'.$revision)], 'environment', $revision), 'environment-'.$revision, $expectedSourceRevision);
    }

    protected function selectPlan(string $plan, string $build, string $environment, int $expectedRevision): array
    {
        return app(QaSelectionService::class)->select($this->actor(), $this->p, ['plan_version_id' => $plan, 'build_id' => $build, 'environment_version_id' => $environment, 'expected_revision' => $expectedRevision, 'reason' => 'Synthetic selection']);
    }

    protected function currentSelection(): ?array
    {
        return $this->qaRun(function () {
            $id = DB::table('qa_project_state')->where('project_id', $this->p)->value('current_selection_id');

            return $id ? (array) DB::table('qa_selection_events')->find($id) : null;
        });
    }

    protected function qaCounts(): array
    {
        return $this->qaRun(fn () => array_map(fn ($table) => DB::table($table)->where('project_id', $this->p)->count(), ['qa_receipts', 'qa_changes', 'qa_outbox', 'qa_reviews', 'qa_selection_events', 'qa_plan_entry_snapshots', 'qa_plan_versions', 'qa_request_keys']));
    }

    protected function revokeQaGrant(string $purpose): void
    {
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('project_id', $this->p)->where('purpose', $purpose)->whereNull('revoked_at')->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => DB::raw('revision+1')]));
    }

    protected function distinctQaReviewer(): TrustedActor
    {
        return app(TenantContext::class)->run($this->tenant, function () {
            $employee = (string) Str::uuid();
            $user = (string) Str::uuid();
            DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic distinct reviewer']);
            DB::table('users')->insert(['id' => $user, 'tenant_id' => $this->tenant, 'employee_id' => $employee, 'display_name' => 'Synthetic distinct reviewer']);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
            foreach (['qa.plan.manage', 'qa.read', 'qa.source.read'] as $purpose) {
                DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'user_id' => $user, 'policy_version_id' => $this->policy, 'purpose' => $purpose, 'scope_type' => 'project', 'project_id' => $this->p, 'valid_from' => now()->subDay()]);
            }

            return new TrustedActor($user, $this->tenant);
        });
    }

    protected function revokeDistinctQaReviewer(TrustedActor $reviewer): void
    {
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $reviewer->id)->where('project_id', $this->p)->where('purpose', 'qa.plan.manage')->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => DB::raw('revision+1')]));
    }

    protected function assertQaConflict(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected stale/CAS rejection.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    protected function assembledPlanHash(array $plan): string
    {
        // Independently reconstruct from immutable stored data rather than the production hash builder.
        return app(TenantContext::class)->run($this->tenant, function () use ($plan) {
            $baseline = DB::table('baselines')->find($plan['baseline_id']);
            $entries = [];
            foreach (DB::table('qa_plan_entries')->where('plan_id', $plan['id'])->get() as $link) {
                $entry = DB::table('qa_plan_entry_snapshots')->find($link->entry_id);
                $tests = [];
                foreach (DB::table('qa_plan_entry_definitions')->where('entry_id', $entry->id)->orderBy('definition_id')->get() as $test) {
                    $definition = DB::table('qa_definition_versions')->find($test->definition_id);
                    $review = DB::table('qa_reviews')->find($test->definition_review_id);
                    $tests[] = ['definition_version_id' => $definition->id, 'definition_hash' => $definition->content_hash, 'definition_review_id' => $review->id, 'review_hash' => $review->subject_hash, 'source_instance_id' => $test->source_instance_id, 'required' => $test->required, 'release_critical' => $definition->release_critical];
                }
                $entries[] = ['entry_snapshot_id' => $entry->id, 'baseline_id' => $entry->baseline_id, 'policy_id' => $entry->policy_id, 'baseline_item_id' => $entry->baseline_item_id, 'criterion_id' => $entry->criterion_id, 'criterion_version_id' => $entry->criterion_version_id, 'included' => $entry->included, 'exclusion_reason' => $entry->exclusion_reason, 'tests' => $tests];
            }
            usort($entries, fn ($a, $b) => strcmp($a['criterion_id'], $b['criterion_id']));

            return hash('sha256', QaContract::canonical(['baseline_id' => $baseline->id, 'baseline_hash' => $baseline->content_hash, 'policy_id' => $baseline->policy_id, 'entries' => $entries]));
        });
    }

    protected function qaMaxSizeInputs(): array
    {
        $this->qaInputs();
        $input = $this->requirement();
        $input['criteria'] = array_map(fn ($i) => ['key' => sprintf('M%03d', $i), 'text' => 'Synthetic maximum criterion '.$i, 'required_test_keys' => ['max-'.$i]], range(0, 99));
        $draft = app(RequirementService::class)->createDraft($this->actor(), $this->p, $input);
        $approved = app(RequirementService::class)->approve($this->actor(), $this->p, $draft['id'], 1, $this->generation, 'Synthetic maximum scope');
        $this->criteria = $approved['criteria'];
        $this->generation = $approved['generation'];
        $this->baseline($this->scope(array_fill(0, 100, 1)));

        // Bulk immutable fixture setup exercises the real runtime FK/trigger rules; no owner/reset.
        return app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.fixture.manage', 'qa.plan.manage', 'qa.source.read'], true, function ($proofs) {
            $proof = QaContract::canonical($proofs['current']['proof']);
            $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
            $source = $this->sourceId();
            $receipts = $heads = $identities = $definitions = $reviews = $updates = $out = [];
            foreach ($this->criteria as $index => $criterion) {
                foreach (range(0, 19) as $number) {
                    $key = 'max-'.$index.'-'.$number;
                    $body = $this->definitionBody($index, key: $key);
                    $envelope = $this->qaEnvelope('definition', $body, $key);
                    $receipt = (string) Str::uuid();
                    $identity = (string) Str::uuid();
                    $id = (string) Str::uuid();
                    $review = (string) Str::uuid();
                    $hash = hash('sha256', QaContract::canonical($body));
                    $receipts[] = ['id' => $receipt, 'source_instance_id' => $source, 'object_id' => $key, 'source_revision' => 1, 'kind' => 'definition', 'payload_hash' => hash('sha256', QaContract::canonical($envelope)), 'envelope' => QaContract::canonical($envelope), 'actor_id' => $this->user, 'authority_proof' => $proof, 'occurred_at' => $envelope['occurred_at'], 'source_updated_at' => $envelope['source_updated_at']] + $scope;
                    $heads[] = ['id' => (string) Str::uuid(), 'source_instance_id' => $source, 'object_id' => $key, 'source_revision' => 1, 'revision' => 1, 'receipt_id' => $receipt] + $scope;
                    $identities[] = ['id' => $identity, 'criterion_id' => $criterion['id'], 'logical_key' => $key] + $scope;
                    unset($body['logical_key']);
                    $definitions[] = ['id' => $id, 'test_identity_id' => $identity, 'criterion_id' => $criterion['id'], 'receipt_id' => $receipt, 'content_hash' => $hash] + $body + $scope;
                    $reviews[] = ['id' => $review, 'definition_id' => $id, 'revision' => 1, 'decision' => 'reviewed', 'subject_hash' => $hash, 'actor_id' => $this->user, 'authority_proof' => $proof, 'reason' => 'Synthetic maximum reviewed definition'] + $scope;
                    $updates[] = ['identity' => $identity, 'definition' => $id, 'review' => $review];
                    $out[$index][] = ['definition_version_id' => $id, 'source_instance_id' => $source, 'required' => true];
                }
            }
            foreach (['qa_receipts' => $receipts, 'qa_source_heads' => $heads, 'qa_definition_identities' => $identities, 'qa_definition_versions' => $definitions, 'qa_reviews' => $reviews] as $table => $rows) {
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
            $json = json_encode($updates, JSON_THROW_ON_ERROR);
            DB::update('UPDATE qa_definition_identities i SET revision=1,current_definition_id=u.definition FROM jsonb_to_recordset(?::jsonb) AS u(identity uuid,definition uuid,review uuid) WHERE i.project_id=? AND i.id=u.identity', [$json, $this->p]);
            DB::update("UPDATE qa_definition_versions d SET review_revision=1,current_review_id=u.review,review_state='reviewed' FROM jsonb_to_recordset(?::jsonb) AS u(identity uuid,definition uuid,review uuid) WHERE d.project_id=? AND d.id=u.definition", [$json, $this->p]);

            return $out;
        });
    }

    protected function completeSyntheticQaJobs(): void
    {
        app(QaCoordination::class)->run($this->actor(), $this->p, ['qa.process', 'qa.read', 'qa.source.read'], false, function ($proofs) {
            $proof = QaContract::canonical($proofs['current']['proof']);
            $token = (string) Str::uuid();
            DB::table('qa_outbox')->where('project_id', $this->p)->where('status', 'pending')->update(['status' => 'processing', 'attempt_count' => DB::raw('attempt_count+1'), 'lease_token' => $token, 'lease_until' => DB::raw("clock_timestamp()+interval '60 seconds'"), 'processing_authority' => $proof, 'processing_authority_hash' => hash('sha256', $proof)]);
            DB::table('qa_outbox')->where('project_id', $this->p)->where('lease_token', $token)->update(['status' => 'completed', 'lease_token' => null, 'lease_until' => null, 'completed_at' => DB::raw('clock_timestamp()')]);
        });
    }

    protected function contendQaOperation(string $mode, array $pins, array $expected = ['ok', 'status=409'], ?string $releaseAfter = null): void
    {
        // Two fixed test-only processes use the same runtime role and contend on the real project lock.
        DB::commit(); // Retain synthetic immutable fixture; independent connections cannot see rolled-back setup.
        DB::beginTransaction();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('projects')->where('id', $this->p)->lockForUpdate()->first());
        $children = [];
        $pids = [];
        try {
            foreach (range(1, 2) as $number) {
                $input = new InputStream;
                $child = new Process([PHP_BINARY, base_path('tests/Support/qa-cas-child.php'), $this->tenant, $this->user, $this->p, $mode, json_encode($pins, JSON_THROW_ON_ERROR)], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'monitoring_ai_test', 'DB_URL' => ''], null, 20);
                $child->setInput($input);
                $child->start();
                $children[] = [$child, $input];
            }
            foreach ($children as [$child, $input]) {
                $deadline = microtime(true) + 10;
                while (! str_contains($child->getOutput(), 'ready') && $child->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready', $child->getOutput(), $child->getErrorOutput());
                $this->assertSame(1, preg_match('/ready pid=([0-9]+)/', $child->getOutput(), $pid));
                $pids[] = (int) $pid[1];
                $input->write("go\n");
                $input->close();
            }
            $deadline = microtime(true) + 5;
            $this->assertCount(2, array_unique($pids), 'Two distinct PostgreSQL backends must contend.');
            do {
                foreach ($children as [$child]) {
                    $child->isRunning();
                }
                DB::select('select pg_stat_clear_snapshot()');
                $blocked = (int) DB::scalar("select count(*) from pg_stat_activity where datname=current_database() and usename=current_user and pid in (?,?) and wait_event_type='Lock'", $pids);
                if ($blocked >= 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both exact child runtime transactions must overlap on the project lock.');
            if ($releaseAfter) {
                while (DB::scalar('select clock_timestamp() < ?::timestamptz', [$releaseAfter])) {
                    DB::select('select pg_sleep(0.02)');
                }
            }
            DB::commit();
            $outcomes = [];
            foreach ($children as [$child]) {
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                $outcomes[] = str_contains($child->getOutput(), 'revision=') ? 'ok' : trim(substr($child->getOutput(), strpos($child->getOutput(), 'status=')));
            }
            sort($outcomes);
            sort($expected);
            $this->assertSame($expected, $outcomes);
            if (isset($pins['expire_after_insert'])) {
                $this->assertTrue(collect($children)->contains(fn ($child) => str_contains($child[0]->getOutput(), 'work_written=1')), 'Final-clock expiry must be reached after all transactional writes.');
            }
            file_put_contents(base_path('../.superpowers/sdd/2026-10-06-e05-qa-handoff-plan/task-4-retained-fixtures.jsonl'), json_encode(['tenant_id' => $this->tenant, 'project_id' => $this->p, 'actor_id' => $this->user, 'mode' => $mode, 'pins' => $pins, 'pg_pids' => $pids, 'outcomes' => $outcomes], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        } finally {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($children as [$child, $input]) {
                $input->close();
                if ($child->isRunning()) {
                    $child->stop(0);
                }
            }
            DB::beginTransaction();
        }
    }

    protected function receipt(array $proof, string $kind, array $body, string $object): array
    {
        $envelope = $this->qaEnvelope($kind, $body, $object);
        $receipt = app(QaReceiptWriter::class)->recordLocked($this->actor(), $this->p, $envelope, $object, 0, $proof);

        return $receipt;
    }

    protected function seedQaLineage(bool $wrongCriterion = false, string $reviewState = 'designed'): array
    {
        $hash = str_repeat('a', 64);
        $proof = $this->qaRun(fn ($r) => $r['current']['proof']);

        return $this->qaRun(function () use ($proof, $hash, $wrongCriterion, $reviewState) {
            $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
            $ids = [];
            foreach (['build', 'environment'] as $kind) {
                $body = ['logical_key' => $kind, 'revision' => 1, 'title' => 'Synthetic '.$kind, 'content_hash' => $hash];
                $receipt = $this->receipt($proof, $kind, $body, $kind);
                $ids[$kind] = (string) Str::uuid();
                DB::table($kind === 'build' ? 'qa_build_versions' : 'qa_environment_versions')->insert(['id' => $ids[$kind], 'receipt_id' => $receipt['receipt_id']] + $scope + $body);
            }
            $criterion = DB::table('criterion_versions')->where('id', $this->criteria[0]['version_id'])->first();
            $identity = (string) Str::uuid();
            DB::table('qa_definition_identities')->insert(['id' => $identity, 'criterion_id' => $criterion->criterion_id, 'logical_key' => 'test-a'] + $scope);
            $body = ['logical_key' => 'test-a', 'revision' => 1, 'criterion_version_id' => $criterion->id, 'title' => 'Synthetic test', 'steps' => 'Run synthetic test', 'expected_result' => 'Synthetic result', 'test_type' => 'functional', 'release_critical' => false];
            $receipt = $this->receipt($proof, 'definition', $body, 'definition');
            unset($body['logical_key']);
            $ids['definition'] = (string) Str::uuid();
            DB::table('qa_definition_versions')->insert(['id' => $ids['definition'], 'test_identity_id' => $identity, 'criterion_id' => $criterion->criterion_id, 'receipt_id' => $receipt['receipt_id'], 'content_hash' => $hash, 'review_state' => $reviewState] + $scope + $body);
            DB::table('qa_definition_identities')->where('id', $identity)->update(['revision' => 1, 'current_definition_id' => $ids['definition']]);
            $ids['series'] = (string) Str::uuid();
            $bindings = ['criterion_id' => $criterion->criterion_id, 'criterion_version_id' => $criterion->id, 'definition_id' => $ids['definition'], 'build_id' => $ids['build'], 'environment_id' => $ids['environment']];
            DB::table('qa_execution_series')->insert(['id' => $ids['series'], 'source_instance_id' => $this->envelopeSource()] + $scope + $bindings);
            $ids['attempt'] = (string) Str::uuid();
            DB::table('qa_execution_attempts')->insert(['id' => $ids['attempt'], 'series_id' => $ids['series'], 'ordinal' => 1, 'execution_key' => 'execution'] + $scope);
            if ($wrongCriterion) {
                // The series/definition is criterion A; a same-project criterion B cannot be substituted.
                $bindings['criterion_version_id'] = $this->criteria[1]['version_id'];
                $executionReceipt = (string) Str::uuid();
                $badEnvelope = $this->qaEnvelope('execution', ['definition_version_id' => $ids['definition'], 'build_id' => $ids['build'], 'environment_version_id' => $ids['environment'], 'attempt_ordinal' => 1, 'outcome' => 'passed', 'task_version_id' => (string) Str::uuid(), 'locator' => 'synthetic'], 'execution');
                DB::table('qa_receipts')->insert(['id' => $executionReceipt, 'source_instance_id' => $this->envelopeSource(), 'object_id' => 'execution', 'source_revision' => 1, 'kind' => 'execution', 'payload_hash' => $hash, 'envelope' => QaContract::canonical($badEnvelope), 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($proof), 'occurred_at' => '2026-10-06T00:00:00Z', 'source_updated_at' => '2026-10-06T00:00:00Z'] + $scope);
                DB::table('qa_execution_versions')->insert(['id' => (string) Str::uuid(), 'series_id' => $ids['series'], 'attempt_id' => $ids['attempt'], 'source_revision' => 1, 'receipt_id' => $executionReceipt, 'outcome' => 'passed', 'content_hash' => $hash, 'responsibility' => '{}', 'occurred_at' => '2026-10-06T00:00:00Z'] + $scope + $bindings);
            }

            return $ids + ['criterion_id' => $criterion->criterion_id, 'criterion_version_id' => $criterion->id];
        });
    }

    protected function qaTask(int $index = 0): array
    {
        $cv = app(TenantContext::class)->run($this->tenant, fn () => DB::table('criterion_versions')->where('id', $this->criteria[$index]['version_id'])->first());
        $task = app(TaskService::class)->create($this->actor(), $this->p, ['title' => 'Synthetic task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic only', 'remaining_effort' => null, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => [['criterion_id' => $cv->criterion_id, 'criterion_version_id' => $cv->id]]], $this->generation);
        $this->generation = $task['generation'];

        return $task;
    }

    protected function insertQaPlan(array $proof, string $state = 'draft'): string
    {
        $baseline = DB::table('baselines')->where('project_id', $this->p)->first();
        $id = (string) Str::uuid();
        DB::table('qa_plan_versions')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'baseline_id' => $baseline->id, 'policy_id' => $baseline->policy_id, 'revision' => 1, 'logical_key' => $id, 'content_hash' => str_repeat('a', 64), 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($proof), 'review_state' => $state]);

        return $id;
    }

    protected function insertQaSelection(string $plan, array $ids, array $proof, ?string $review = null, int $revision = 1): string
    {
        $id = (string) Str::uuid();
        DB::table('qa_selection_events')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'revision' => $revision, 'plan_id' => $plan, 'plan_review_id' => $review, 'build_id' => $ids['build'], 'environment_id' => $ids['environment'], 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($proof), 'reason' => 'Synthetic selection']);

        return $id;
    }

    protected function qaReview(string $kind, string $subject, string $decision, array $proof): string
    {
        $table = $kind === 'definition' ? 'qa_definition_versions' : 'qa_plan_versions';
        $row = DB::table($table)->where('id', $subject)->first();
        $id = (string) Str::uuid();
        DB::table('qa_reviews')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'project_id' => $this->p, $kind.'_id' => $subject, 'revision' => $row->review_revision + 1, 'decision' => $decision, 'subject_hash' => $row->content_hash, 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($proof), 'reason' => 'Synthetic review']);
        DB::table($table)->where('id', $subject)->update(['review_revision' => $row->review_revision + 1, 'current_review_id' => $id, 'review_state' => $decision]);

        return $id;
    }

    protected function insertQaEntry(array $ids, array $proof, string $review): string
    {
        $item = DB::table('baseline_items')->where('baseline_id', $this->baselineId)->where('criterion_version_id', $ids['criterion_version_id'])->first();
        $entry = (string) Str::uuid();
        DB::table('qa_plan_entry_snapshots')->insert(['id' => $entry, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'baseline_id' => $this->baselineId, 'policy_id' => $item->policy_id, 'baseline_item_id' => $item->id, 'criterion_id' => $ids['criterion_id'], 'criterion_version_id' => $ids['criterion_version_id'], 'included' => true, 'content_hash' => hash('sha256', QaContract::canonical(['definition' => $ids['definition'], 'review' => $review])), 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($proof)]);
        DB::table('qa_plan_entry_definitions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'entry_id' => $entry, 'criterion_id' => $ids['criterion_id'], 'criterion_version_id' => $ids['criterion_version_id'], 'definition_id' => $ids['definition'], 'definition_review_id' => $review, 'source_instance_id' => $this->envelopeSource(), 'required' => true]);

        return $entry;
    }

    protected function seedDefectRetest(array $ids, array $proof): array
    {
        $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
        $pins = ['criterion_id' => $ids['criterion_id'], 'criterion_version_id' => $ids['criterion_version_id'], 'definition_id' => $ids['definition'], 'build_id' => $ids['build'], 'environment_id' => $ids['environment']];
        $out = [];
        foreach (['a', 'b'] as $key) {
            $body = ['logical_key' => 'defect-'.$key, 'criterion_version_id' => $ids['criterion_version_id'], 'definition_version_id' => $ids['definition'], 'build_id' => $ids['build'], 'environment_version_id' => $ids['environment'], 'severity' => 'high', 'reproducibility' => 'reproduced', 'reproduction' => 'Synthetic reproduction'];
            // Foundation tests deliberately insert a source receipt without a service body to isolate PG lineage.
            $receipt = (string) Str::uuid();
            DB::table('qa_receipts')->insert(['id' => $receipt, 'source_instance_id' => $this->envelopeSource(), 'object_id' => 'defect-'.$key, 'source_revision' => 1, 'kind' => 'defect', 'payload_hash' => str_repeat('a', 64), 'envelope' => QaContract::canonical($this->qaEnvelope('defect', $body, 'defect-'.$key)), 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($proof), 'occurred_at' => '2026-10-06T00:00:00Z', 'source_updated_at' => '2026-10-06T00:00:00Z'] + $scope);
            $out[$key] = (string) Str::uuid();
            DB::table('qa_defects')->insert(['id' => $out[$key], 'receipt_id' => $receipt, 'logical_key' => 'defect-'.$key, 'severity' => 'high', 'reproducibility' => 'reproduced', 'reproduction' => 'Synthetic reproduction'] + $scope + $pins);
            foreach ([1 => ['opened', 'open'], 2 => ['triaged', 'triaged'], 3 => ['fix_requested', 'fixed_awaiting_retest']] as $revision => [$type, $state]) {
                $event = (string) Str::uuid();
                DB::table('qa_defect_events')->insert(['id' => $event, 'defect_id' => $out[$key], 'revision' => $revision, 'event_type' => $type, 'state' => $state, 'reason' => 'Synthetic', 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($proof)] + $scope + $pins);
                DB::table('qa_defects')->where('id', $out[$key])->update(['revision' => $revision, 'current_event_id' => $event, 'state' => $state]);
                $out['request_'.$key] = $event;
            }
        }
        $attempt = (string) Str::uuid();
        DB::table('qa_execution_attempts')->insert(['id' => $attempt, 'series_id' => $ids['series'], 'ordinal' => 2, 'execution_key' => 'retest-b', 'predecessor_id' => $ids['attempt'], 'fix_request_event_id' => $out['request_b']] + $scope);
        $body = ['definition_version_id' => $ids['definition'], 'build_id' => $ids['build'], 'environment_version_id' => $ids['environment'], 'attempt_ordinal' => 2, 'outcome' => 'passed', 'task_version_id' => (string) Str::uuid(), 'locator' => 'synthetic'];
        $receipt = $this->receipt($proof, 'execution', $body, 'retest-b');
        $out['execution'] = (string) Str::uuid();
        DB::table('qa_execution_versions')->insert(['id' => $out['execution'], 'series_id' => $ids['series'], 'attempt_id' => $attempt, 'source_revision' => 1, 'receipt_id' => $receipt['receipt_id'], 'outcome' => 'passed', 'content_hash' => str_repeat('a', 64), 'responsibility' => '{}', 'occurred_at' => '2026-10-06T00:00:00Z'] + $scope + $pins);

        return $out;
    }

    protected function insertDefectClosure(string $defect, array $ids, array $proof, ?string $execution, ?string $request): string
    {
        $id = (string) Str::uuid();
        DB::table('qa_defect_events')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'defect_id' => $defect, 'revision' => 4, 'event_type' => 'verified_closed', 'state' => 'verified_closed', 'reason' => 'Synthetic closure', 'actor_id' => $this->user, 'authority_proof' => QaContract::canonical($proof), 'criterion_id' => $ids['criterion_id'], 'criterion_version_id' => $ids['criterion_version_id'], 'definition_id' => $ids['definition'], 'build_id' => $ids['build'], 'environment_id' => $ids['environment'], 'execution_id' => $execution, 'fix_request_event_id' => $request]);

        return $id;
    }

    protected function revisedTaskOrigin(array $task, ?string $actor = null): string
    {
        $row = (array) DB::table('task_versions')->where('id', $task['version_id'])->first();
        $row['id'] = $id = (string) Str::uuid();
        $row['revision']++;
        $row['actor_id'] = $actor ?? $this->user;
        $row['effective_at'] = DB::raw('clock_timestamp()');
        DB::table('task_versions')->insert($row);
        DB::table('lifecycle_tasks')->where('id', $task['id'])->update(['revision' => $row['revision'], 'current_version_id' => $id]);

        return $id;
    }
}
