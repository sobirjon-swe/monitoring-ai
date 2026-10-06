<?php

namespace Tests\Feature;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\DeveloperAnalysis\AnalysisPins;
use App\Domain\DeveloperAnalysis\AnalysisProcessor;
use App\Domain\DeveloperAnalysis\AnalysisRequestService;
use App\Domain\DeveloperAnalysis\DeveloperAuthority;
use App\Domain\DeveloperAnalysis\DeveloperContract;
use App\Domain\DeveloperAnalysis\DeveloperCoordination;
use App\Domain\DeveloperAnalysis\RunnerAvailability;
use App\Domain\DeveloperAnalysis\SyntheticMrRegistry;
use App\Domain\Lifecycle\RequirementService;
use App\Domain\Lifecycle\TaskService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\DeveloperAnalysisTestCase;

class DeveloperAnalysisApiTest extends DeveloperAnalysisTestCase
{
    private function payload(string $project): array
    {
        $receipt = app(SyntheticMrRegistry::class)->register($this->actor(), $project, $this->mr(), 0);
        $this->withToken($this->token);

        return ['snapshot_id' => $receipt['snapshot_id'], 'mode' => 'deterministic', 'criterion_version_ids' => [], 'task_version_ids' => []];
    }

    private function ready(): void
    {
        // Explicit test DI only: no flag or production configuration bypasses OS evidence.
        $this->app->instance(RunnerAvailability::class, new class extends RunnerAvailability
        {
            public function assertReady(): void {}
        });
    }

    private function requestRun(string $project, array $payload, string $key = 'request-a')
    {
        return $this->postJson('/api/v1/projects/'.$project.'/analysis-runs', $payload, ['Idempotency-Key' => $key]);
    }

    public function test_disabled_runner_returns_503_without_durable_execution(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->requestRun($project, $payload)->assertStatus(503);
        app(TenantContext::class)->run($this->tenant, function (): void {
            $this->assertSame(0, DB::table('developer_runs')->count());
            $this->assertSame(0, DB::table('developer_outbox')->whereNotNull('run_id')->count());
        });
    }

    public function test_ready_request_durably_binds_input_authority_and_execution_outbox(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->assertJsonPath('data.status', 'queued')->json('data.id');
        app(TenantContext::class)->run($this->tenant, function () use ($id, $payload): void {
            $run = DB::table('developer_runs')->where('id', $id)->first();
            $this->assertSame($this->user, $run->requester_id);
            $this->assertSame($payload['snapshot_id'], $run->snapshot_id);
            $this->assertSame(0, $run->attempt_count);
            $this->assertNotEmpty(json_decode($run->authority, true)['grants']);
            $this->assertSame(1, DB::table('developer_outbox')->where('run_id', $id)->where('status', 'pending')->count());
        });
        $this->getJson('/api/v1/analysis-runs/'.$id)->assertOk()->assertJsonPath('data.ai_interpretation', 'unavailable');
        $this->call('HEAD', '/api/v1/analysis-runs/'.$id, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token])->assertOk();
    }

    public function test_same_retry_precedes_runner_availability_but_mismatch_conflicts(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        $this->app->forgetInstance(RunnerAvailability::class);
        $this->requestRun($project, $payload)->assertStatus(202)->assertJsonPath('data.id', $id);
        $different = $payload;
        $different['snapshot_id'] = $this->user;
        $this->requestRun($project, $different)->assertStatus(409)->assertDontSee($id);
        $other = $this->project();
        foreach (['analysis.request', 'analysis.read', 'analysis.source.read'] as $purpose) {
            $this->grant($purpose, $other);
        }
        $this->requestRun($other, $payload)->assertStatus(409)->assertDontSee($id);
    }

    public function test_collision_and_read_require_current_permission_without_leaking_run(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'analysis.read')->update(['revoked_at' => now()]));
        $this->requestRun($project, $payload)->assertForbidden()->assertDontSee($id);
        $this->getJson('/api/v1/analysis-runs/'.$id)->assertForbidden()->assertDontSee($payload['snapshot_id']);
    }

    public function test_replacement_grant_cannot_restore_original_requester_proof(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'analysis.request')->update(['revoked_at' => now()]));
        $this->grant('analysis.request', $project);
        $this->requestRun($project, $payload)->assertForbidden();
        $this->getJson('/api/v1/analysis-runs/'.$id)->assertForbidden();
    }

    public static function deniedRetryProofs(): array
    {
        return [
            'initial request purpose' => ['analysis.request', false],
            'initial source purpose' => ['analysis.source.read', false],
            'pinned request proof' => ['analysis.request', true],
            'pinned source proof' => ['analysis.source.read', true],
        ];
    }

    #[DataProvider('deniedRetryProofs')]
    public function test_denied_retry_retains_observed_revocation_after_same_grant_restore(string $purpose, bool $replacement): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        $grant = app(TenantContext::class)->run($this->tenant, function () use ($purpose, $project): string {
            $id = DB::table('access_grants')->where('project_id', $project)->where('purpose', $purpose)->value('id');
            DB::table('access_grants')->where('id', $id)->update(['revoked_at' => now()]);

            return $id;
        });
        if ($replacement) {
            $this->grant($purpose, $project);
        }
        $this->requestRun($project, $payload)->assertForbidden()->assertDontSee($id)->assertDontSee($payload['snapshot_id'])->assertJsonMissingPath('data');
        // Inspect before any read/drain can independently observe and mask the missing retry maintenance.
        app(TenantContext::class)->run($this->tenant, function () use ($id, $grant): void {
            $run = DB::table('developer_runs')->where('id', $id)->first();
            $this->assertSame('stale', $run->applicability);
            $this->assertSame('cancelled', $run->status);
            $this->assertSame('access_revoked', $run->reason_code);
            $this->assertSame(1, DB::table('developer_invalidations')->where('run_id', $id)->count());
            $this->assertSame('blocked', DB::table('developer_outbox')->where('run_id', $id)->value('status'));
            DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => null]);
        });
        $this->requestRun($project, $payload)->assertStatus(202)->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.applicability', 'stale');
        $this->getJson('/api/v1/analysis-runs/'.$id)->assertOk()->assertJsonPath('data.applicability', 'stale')->assertJsonPath('data.output', null);
        $this->assertSame(['processed' => 0, 'states' => []], app(AnalysisProcessor::class)->drain($this->actor(), $project, 1));
        app(TenantContext::class)->run($this->tenant, function () use ($id): void {
            $this->assertSame(1, DB::table('developer_invalidations')->where('run_id', $id)->count());
            $this->assertSame(0, DB::table('developer_results')->where('run_id', $id)->count());
            $this->assertSame(0, DB::table('developer_runs')->where('id', $id)->value('attempt_count'));
        });
    }

    public function test_delivery_only_denied_retry_preserves_valid_original_request(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'analysis.read')->update(['revoked_at' => now()]));
        $this->requestRun($project, $payload)->assertForbidden()->assertDontSee($id)->assertDontSee($payload['snapshot_id']);
        app(TenantContext::class)->run($this->tenant, function () use ($id): void {
            $this->assertSame('current', DB::table('developer_runs')->where('id', $id)->value('applicability'));
            $this->assertSame('queued', DB::table('developer_runs')->where('id', $id)->value('status'));
            $this->assertSame('pending', DB::table('developer_outbox')->where('run_id', $id)->value('status'));
            $this->assertSame(0, DB::table('developer_invalidations')->where('run_id', $id)->count());
            DB::table('access_grants')->where('purpose', 'analysis.read')->update(['revoked_at' => null]);
        });
        $this->requestRun($project, $payload)->assertStatus(202)->assertJsonPath('data.id', $id)->assertJsonPath('data.applicability', 'current');
    }

    public static function unrelatedDeniedRetries(): array
    {
        return [['unknown-key'], ['wrong-project'], ['foreign-tenant'], ['other-requester']];
    }

    #[DataProvider('unrelatedDeniedRetries')]
    public function test_unrelated_denied_retry_cannot_maintain_retained_run(string $case): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        $tenant = $this->tenant;
        app(TenantContext::class)->run($tenant, fn () => DB::table('access_grants')->where('purpose', 'analysis.request')->update(['revoked_at' => now()]));
        $actor = $this->actor();
        $requestedProject = $project;
        $key = 'request-a';
        if ($case === 'unknown-key') {
            $key = 'unknown-key';
        } elseif ($case === 'wrong-project') {
            $requestedProject = $this->project();
            foreach (['analysis.request', 'analysis.source.read'] as $purpose) {
                $this->grant($purpose, $requestedProject);
            }
            // Denial occurs while checking delivery on the retained project's hint.
            app(TenantContext::class)->run($tenant, fn () => DB::table('access_grants')->where('purpose', 'analysis.read')->update(['revoked_at' => now()]));
        } elseif ($case === 'foreign-tenant') {
            foreach (['tenant', 'user', 'employee', 'policy'] as $property) {
                $this->$property = (string) Str::uuid();
            }
            $this->fixture();
            $actor = $this->actor();
        } else {
            $user = (string) Str::uuid();
            app(TenantContext::class)->run($tenant, fn () => DB::table('users')->insert(['id' => $user, 'tenant_id' => $tenant, 'display_name' => 'Other synthetic requester']));
            $actor = new TrustedActor($user, $tenant);
        }
        try {
            app(AnalysisRequestService::class)->request($actor, $requestedProject, $payload, $key);
            $this->fail('An unrelated denied retry was admitted.');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        app(TenantContext::class)->run($tenant, function () use ($id): void {
            $this->assertSame('current', DB::table('developer_runs')->where('id', $id)->value('applicability'));
            $this->assertSame('queued', DB::table('developer_runs')->where('id', $id)->value('status'));
            $this->assertSame('pending', DB::table('developer_outbox')->where('run_id', $id)->value('status'));
            $this->assertSame(0, DB::table('developer_invalidations')->where('run_id', $id)->count());
        });
    }

    public function test_first_retry_observation_returns_cancelled_status_for_closed_project(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('lifecycle_project_state')->insert(['tenant_id' => $this->tenant, 'project_id' => $project, 'generation' => 0, 'is_closed' => true]));
        $this->requestRun($project, $payload)->assertStatus(202)->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.applicability', 'stale');
        app(TenantContext::class)->run($this->tenant, function () use ($id): void {
            $run = DB::table('developer_runs')->where('id', $id)->first();
            $this->assertSame('cancelled', $run->status);
            $this->assertSame('project_closed', $run->reason_code);
            $this->assertSame(1, DB::table('developer_invalidations')->where('run_id', $id)->count());
            $this->assertSame('blocked', DB::table('developer_outbox')->where('run_id', $id)->value('status'));
        });
    }

    public function test_retry_locks_bound_source_before_retained_run(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->requestRun($project, $payload)->assertStatus(202)->assertJsonPath('data.id', $id);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $sourceLock = $runLock = null;
        foreach ($queries as $index => $query) {
            if (str_contains($query['query'], '"developer_source_heads"') && str_contains($query['query'], 'for share')) {
                $sourceLock ??= $index;
            }
            if (str_contains($query['query'], '"developer_runs"') && str_contains($query['query'], 'for update')) {
                $runLock ??= $index;
            }
        }
        $this->assertNotNull($sourceLock);
        $this->assertNotNull($runLock);
        $this->assertLessThan($runLock, $sourceLock);
    }

    public function test_closed_project_rejects_new_request_and_allows_historical_read(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('lifecycle_project_state')->insert(['tenant_id' => $this->tenant, 'project_id' => $project, 'generation' => 0, 'is_closed' => true]));
        $this->requestRun($project, $payload, 'new-key')->assertStatus(409);
        $this->getJson('/api/v1/analysis-runs/'.$id)->assertOk()->assertJsonPath('data.applicability', 'stale');
        $this->requestRun($project, $payload)->assertStatus(202)->assertJsonPath('data.applicability', 'stale');
    }

    public function test_newer_blocked_receipt_prevents_old_pointer_admission_and_current_read(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        $new = $this->mr();
        $new['revision_ordinal'] = 2;
        $new['diff'] = str_replace('+new', '+password=abc', $new['diff']);
        // The fixture content is harmless until a credential assignment is introduced.
        $new['diff'] .= "+api_key=abc\n";
        $receipt = app(SyntheticMrRegistry::class)->register($this->actor(), $project, $new, 1);
        $this->assertFalse($receipt['accepted']);
        $this->requestRun($project, $payload, 'new-key')->assertStatus(409);
        $this->getJson('/api/v1/analysis-runs/'.$id)->assertOk()->assertJsonPath('data.applicability', 'stale');
    }

    public function test_transport_rejects_authority_fields_missing_key_and_object_pins(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $this->requestRun($project, $payload + ['tenant_id' => $this->tenant])->assertStatus(422);
        $this->postJson('/api/v1/projects/'.$project.'/analysis-runs', $payload)->assertStatus(422);
        $payload['criterion_version_ids'] = (object) [];
        $this->requestRun($project, $payload)->assertStatus(422);
    }

    public function test_cli_registers_json_only_with_existing_authenticated_token(): void
    {
        $project = $this->developerProject();
        $fixture = tempnam(sys_get_temp_dir(), 'e04-fixture-');
        $token = tempnam(sys_get_temp_dir(), 'e04-token-');
        try {
            file_put_contents($fixture, json_encode($this->mr(), JSON_THROW_ON_ERROR));
            file_put_contents($token, $this->token);
            $this->artisan('app:developer-fixture', ['project' => $project, 'fixture' => $fixture, '--token-file' => $token, '--expected-revision' => '0'])->assertExitCode(0);
            app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(1, DB::table('developer_snapshots')->count()));
            $raw = json_encode($this->mr(), JSON_THROW_ON_ERROR);
            file_put_contents($fixture, str_replace('"revision_ordinal":1', '"revision_ordinal":2,"revision_ordinal":1', $raw));
            $this->artisan('app:developer-fixture', ['project' => $project, 'fixture' => $fixture, '--token-file' => $token, '--expected-revision' => '1'])->assertExitCode(1);
            file_put_contents($token, 'invalid-token');
            $this->artisan('app:developer-fixture', ['project' => $project, 'fixture' => $fixture, '--token-file' => $token, '--expected-revision' => '1'])->assertExitCode(1);
        } finally {
            unlink($fixture);
            unlink($token);
        }
    }

    public function test_nonexistent_and_foreign_pins_are_404_without_durable_run(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $payload['criterion_version_ids'] = [$this->user];
        $this->requestRun($project, $payload)->assertNotFound();
        $payload['criterion_version_ids'] = [];
        $payload['task_version_ids'] = [$this->user];
        $this->requestRun($project, $payload)->assertNotFound();
    }

    public function test_oversized_body_is_413_even_when_shape_is_invalid(): void
    {
        $project = $this->developerProject();
        $this->payload($project);
        $this->postJson('/api/v1/projects/'.$project.'/analysis-runs', ['noise' => str_repeat('x', 65536)], ['Idempotency-Key' => 'large'])->assertStatus(413);
    }

    public function test_current_approved_criterion_pins_normalize_retry_and_become_stale_on_revision(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        foreach (['requirements.manage', 'requirements.approve'] as $purpose) {
            $this->grant($purpose, $project);
        }
        $data = ['title' => 'Synthetic requirement', 'owner_id' => $this->employee, 'stakeholder' => 'Synthetic stakeholder', 'business_outcome' => 'Synthetic outcome', 'nfr_constraints' => ['Synthetic NFR'], 'dependencies' => [],
            'criteria' => [['key' => 'A', 'text' => 'Check A', 'required_test_keys' => ['test-a']], ['key' => 'B', 'text' => 'Check B', 'required_test_keys' => ['test-b']]]];
        $service = app(RequirementService::class);
        $draft = $service->createDraft($this->actor(), $project, $data);
        $approved = $service->approve($this->actor(), $project, $draft['id'], 1, 0, 'Synthetic approval');
        $ids = array_column($approved['criteria'], 'version_id');
        $payload['criterion_version_ids'] = [$ids[1], strtoupper($ids[0]), $ids[1]];
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        $payload['criterion_version_ids'] = $ids;
        $this->requestRun($project, $payload)->assertStatus(202)->assertJsonPath('data.id', $id);
        $data['criteria'][0]['text'] = 'Changed criterion';
        $service->updateDraft($this->actor(), $project, $draft['id'], $data, 1);
        $service->approve($this->actor(), $project, $draft['id'], 2, 1, 'Synthetic revision');
        $this->requestRun($project, $payload, 'new-key')->assertStatus(409);
        $this->getJson('/api/v1/analysis-runs/'.$id)->assertOk()->assertJsonPath('data.applicability', 'stale')->assertJsonPath('data.output', null);
    }

    public function test_exact_task_version_pin_becomes_stale_on_task_revision(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->grant('requirements.manage', $project);
        $taskData = ['title' => 'Synthetic task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic units', 'remaining_effort' => 5, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => []];
        $task = app(TaskService::class)->create($this->actor(), $project, $taskData, 0);
        $payload['task_version_ids'] = [$task['version_id']];
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        $taskData['status'] = 'in_progress';
        app(TaskService::class)->revise($this->actor(), $project, $task['id'], $taskData, 1, 1);
        $this->requestRun($project, $payload, 'new-key')->assertStatus(409);
        $this->getJson('/api/v1/analysis-runs/'.$id)->assertOk()->assertJsonPath('data.applicability', 'stale');
    }

    public function test_tenant_grants_alone_do_not_confer_any_developer_purpose(): void
    {
        $this->fixture();
        $project = $this->project();
        foreach (DeveloperAuthority::PURPOSES as $purpose) {
            $this->grant($purpose);
            try {
                app(AccessGateway::class)->authorize($this->actor(), $purpose, ['tenant_id' => $this->tenant, 'project_id' => $project]);
                $this->fail('A tenant grant admitted a developer purpose.');
            } catch (AccessDeniedHttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_tenant_isolation_hides_other_run_and_snapshot_even_with_same_key(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        foreach (['tenant', 'user', 'employee', 'policy'] as $property) {
            $this->$property = (string) Str::uuid();
        }
        $other = $this->developerProject();
        $otherPayload = $this->payload($other);
        $this->getJson('/api/v1/analysis-runs/'.$id)->assertNotFound()->assertDontSee($payload['snapshot_id']);
        $this->requestRun($other, $payload, 'foreign-snapshot')->assertNotFound();
        $otherId = $this->requestRun($other, $otherPayload)->assertStatus(202)->json('data.id');
        $this->assertNotSame($id, $otherId);
    }

    public function test_finding_read_hides_immutable_facts_and_output_when_source_changes(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun($project, $payload)->assertStatus(202)->json('data.id');
        $finding = app(TenantContext::class)->run($this->tenant, function () use ($id, $project, $payload): string {
            // A stored result fixture exercises delivery only; publication validation remains R5's gate.
            $run = DB::table('developer_runs')->where('id', $id)->first();
            $result = (string) Str::uuid();
            $finding = (string) Str::uuid();
            $output = DeveloperContract::canonical(['coverage' => 'partial', 'synthetic_fact' => 'Changed lines']);
            DB::table('developer_results')->insert(['id' => $result, 'tenant_id' => $this->tenant, 'project_id' => $project, 'snapshot_id' => $payload['snapshot_id'], 'run_id' => $id,
                'input_hash' => $run->input_hash, 'output' => $output, 'output_hash' => hash('sha256', $output), 'processing_authority' => $run->authority, 'processing_authority_hash' => $run->authority_hash]);
            DB::table('developer_findings')->insert(['id' => $finding, 'tenant_id' => $this->tenant, 'project_id' => $project, 'snapshot_id' => $payload['snapshot_id'], 'run_id' => $id,
                'result_id' => $result, 'kind' => 'composition_fact', 'facts' => '{"changed_lines":2}', 'summary' => 'Synthetic composition']);

            return $finding;
        });
        $this->getJson('/api/v1/findings/'.$finding)->assertOk()->assertJsonPath('data.facts.changed_lines', 2)->assertJsonMissingPath('data.authority')->assertJsonMissingPath('data.input');
        $new = $this->mr();
        $new['revision_ordinal'] = 2;
        $new['head_sha'] = str_repeat('c', 40);
        app(SyntheticMrRegistry::class)->register($this->actor(), $project, $new, 1);
        $this->getJson('/api/v1/findings/'.$finding)->assertOk()->assertJsonPath('data.applicability', 'stale')->assertJsonPath('data.facts', null)->assertJsonPath('data.summary', null);
        $this->getJson('/api/v1/analysis-runs/'.$id)->assertOk()->assertJsonPath('data.output', null);
    }

    public function test_project_admission_is_bounded_to_100_pending_runs(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        for ($i = 0; $i < 100; $i++) {
            $this->requestRun($project, $payload, 'queued-'.$i)->assertStatus(202);
        }
        $this->requestRun($project, $payload, 'saturated')->assertStatus(429)->assertHeader('Retry-After', '5');
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(100, DB::table('developer_outbox')->whereNotNull('run_id')->count()));
    }

    public function test_uuid_route_casing_does_not_change_scoped_idempotency_identity(): void
    {
        $project = $this->developerProject();
        $payload = $this->payload($project);
        $this->ready();
        $id = $this->requestRun(strtoupper($project), $payload)->assertStatus(202)->json('data.id');
        $this->requestRun($project, $payload)->assertStatus(202)->assertJsonPath('data.id', $id);
    }

    public function test_pin_query_count_is_constant_for_representative_and_maximum_current_sets(): void
    {
        $project = $this->developerProject();
        foreach (['requirements.manage', 'requirements.approve'] as $purpose) {
            $this->grant($purpose, $project);
        }
        $data = ['title' => 'Synthetic requirement', 'owner_id' => $this->employee, 'stakeholder' => 'Synthetic stakeholder', 'business_outcome' => 'Synthetic outcome', 'nfr_constraints' => ['Synthetic NFR'], 'dependencies' => [],
            'criteria' => array_map(fn ($i) => ['key' => 'C'.$i, 'text' => 'Synthetic criterion '.$i, 'required_test_keys' => ['test-'.$i]], range(1, 100))];
        $requirements = app(RequirementService::class);
        $draft = $requirements->createDraft($this->actor(), $project, $data);
        $approved = $requirements->approve($this->actor(), $project, $draft['id'], 1, 0, 'Synthetic approval');
        $criterionIds = array_column($approved['criteria'], 'version_id');
        // A later approved requirement may legitimately reuse all unchanged criterion versions.
        $data['title'] = 'Synthetic revised title';
        $requirements->updateDraft($this->actor(), $project, $draft['id'], $data, 1);
        $requirements->approve($this->actor(), $project, $draft['id'], 2, 1, 'Synthetic unchanged criteria');
        $taskData = ['title' => 'Synthetic task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic units', 'remaining_effort' => 5, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => []];
        $taskIds = [];
        for ($i = 0; $i < 100; $i++) {
            $taskIds[] = app(TaskService::class)->create($this->actor(), $project, $taskData, $i + 2)['version_id'];
        }
        app(DeveloperCoordination::class)->run($this->actor(), $project, ['analysis.request', 'analysis.source.read'], true, function () use ($project, $criterionIds, $taskIds): void {
            $service = app(AnalysisPins::class);
            $currentCounts = [];
            $captureCounts = [];
            DB::enableQueryLog();
            try {
                foreach ([10, 100] as $size) {
                    $pins = AnalysisPins::normalize(['criterion_version_ids' => array_slice($criterionIds, 0, $size), 'task_version_ids' => array_slice($taskIds, 0, $size)]);
                    DB::flushQueryLog();
                    $this->assertTrue($service->current($this->actor(), $project, $pins));
                    $currentCounts[] = count(DB::getQueryLog());
                    $this->assertLessThanOrEqual(2, end($currentCounts), 'Current checks must batch both pin types.');
                    DB::flushQueryLog();
                    $this->assertSame($pins, $service->capture($this->actor(), $project, $pins));
                    $captureCounts[] = count(DB::getQueryLog());
                    $this->assertLessThanOrEqual(4, end($captureCounts), 'Capture may add two scoped existence queries.');
                }
                $this->assertSame($currentCounts[0], $currentCounts[1]);
                $this->assertSame($captureCounts[0], $captureCounts[1]);
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        });
    }
}
