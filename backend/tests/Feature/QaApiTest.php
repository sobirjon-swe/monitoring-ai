<?php

namespace Tests\Feature;

use App\Domain\Qa\QaContract;
use App\Domain\Qa\QaPlanService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QaTestCase;

class QaApiTest extends QaTestCase
{
    private function startApi(): void
    {
        $this->qaInputs();
        $this->withToken($this->token);
    }

    private function url(string $path): string
    {
        return '/api/v1/projects/'.$this->p.'/qa'.$path;
    }

    private function create(string $path, array $payload, string $key = 'api-new', array $controls = [])
    {
        return $this->postJson($this->url($path), $payload, ['Idempotency-Key' => $key] + $controls);
    }

    private function raw(string $path, string $raw, array $controls = [])
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'HTTP_IDEMPOTENCY_KEY' => 'raw-key'];
        foreach ($controls as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $this->url($path), [], [], [], $server, $raw);
    }

    public function test_http_definition_plan_selection_and_execution_preserve_coverage_and_replay(): void
    {
        $this->startApi();
        $envelope = $this->qaEnvelope('definition', $this->definitionBody(), 'api-definition');
        $definition = $this->create('/fixtures', $envelope, controls: ['Expected-Source-Revision' => '0'])->assertCreated()->json('data.record_id');
        $this->create('/fixtures', $envelope, controls: ['Expected-Source-Revision' => '999'])->assertOk()->assertJsonPath('data.record_id', $definition)->assertJsonPath('data.replayed', true);
        $this->postJson($this->url('/definitions/'.$definition.'/reviews'), ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => 'Synthetic API review'])->assertOk()->assertJsonPath('data.state', 'reviewed');
        $entries = [];
        foreach (range(0, 2) as $index) {
            $tests = $index === 0 ? [['definition_version_id' => $definition, 'source_instance_id' => $this->sourceId(), 'required' => true]] : [];
            $entries[] = $this->create('/plan-entries', $this->entryInput($index, $tests), 'entry-'.$index)->assertCreated()->json('data.id');
        }
        $planInput = ['baseline_id' => $this->baselineId, 'entry_snapshot_ids' => $entries];
        $plan = $this->create('/plans', $planInput, 'api-plan')->assertCreated()->json('data.id');
        $this->create('/plans', $planInput, 'api-plan')->assertOk()->assertJsonPath('data.id', $plan);
        $this->postJson($this->url('/plans/'.$plan.'/reviews'), ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic API plan'])->assertOk();
        $build = $this->qaBuild()['record_id'];
        $environment = $this->qaEnvironment()['record_id'];
        $selection = ['plan_version_id' => $plan, 'build_id' => $build, 'environment_version_id' => $environment, 'expected_revision' => 0, 'reason' => 'Synthetic selection'];
        $this->postJson($this->url('/selections'), $selection)->assertOk()->assertJsonPath('data.state', 'selected');
        $this->postJson($this->url('/selections'), $selection)->assertConflict();
        $this->grant('task.manage', $this->p);
        $body = ['definition_version_id' => $definition, 'build_id' => $build, 'environment_version_id' => $environment, 'attempt_ordinal' => 1, 'outcome' => 'failed', 'task_version_id' => $this->qaTask()['version_id'], 'locator' => 'synthetic.api'];
        $execution = $this->qaEnvelope('execution', $body, 'api-execution');
        $headers = ['Expected-Source-Revision' => '0', 'Expected-Series-Revision' => '0'];
        $result = $this->create('/executions', $execution, 'execution', $headers)->assertCreated()->json('data.record_id');
        $counts = $this->qaCounts();
        $this->create('/executions', $execution, 'execution', $headers)->assertOk()->assertJsonPath('data.record_id', $result);
        $this->assertSame($counts, $this->qaCounts());
        $this->get($this->url('/verification'))->assertOk()->assertJsonPath('data.counts.numerator', 1)->assertJsonPath('data.counts.denominator', 3)->assertJsonPath('data.counts.ratio_basis_points', 3333)->assertJsonPath('data.ai_interpretation', 'unavailable');
    }

    public function test_strict_nested_json_and_utf8_reason_bounds_no_writes(): void
    {
        $this->startApi();
        $envelope = $this->qaEnvelope('definition', $this->definitionBody());
        $json = QaContract::canonical($envelope);
        $bad = [
            str_replace('"release_critical":false', '"release_critical":false,"release_critica\\u006c":true', $json),
            str_replace('"release_critical":false', '"release_critical":0', $json),
            str_replace('"source_revision":1', '"source_revision":true', $json),
            str_replace('"Synthetic', '"\\u0001Synthetic', $json),
            QaContract::canonical($envelope + ['actor_id' => $this->user]),
            QaContract::canonical(array_replace($envelope, ['body' => []])),
            QaContract::canonical(array_replace($envelope, ['operation' => 'fixture.register'])),
            '{"body":'.str_repeat('{"a":', 16).'{}'.str_repeat('}', 16).'}',
            "{\"body\":\"\xff\"}",
        ];
        $counts = $this->qaCounts();
        foreach ($bad as $raw) {
            $this->raw('/fixtures', $raw, ['Expected-Source-Revision' => '0'])->assertUnprocessable();
            $this->assertSame($counts, $this->qaCounts());
        }
        $this->raw('/fixtures', str_repeat(' ', 65537), ['Expected-Source-Revision' => '0'])->assertStatus(413);
        $definition = $this->create('/fixtures', $envelope, controls: ['Expected-Source-Revision' => '0'])->assertCreated()->json('data.record_id');
        $path = '/definitions/'.$definition.'/reviews';
        $this->postJson($this->url($path), ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => str_repeat('é', 1000)])->assertOk();
        $counts = $this->qaCounts();
        foreach ([str_repeat('é', 1000).'x', '   ', 'password=synthetic-secret-value'] as $reason) {
            $this->postJson($this->url($path), ['expected_revision' => 1, 'decision' => 'reviewed', 'reason' => $reason])->assertUnprocessable()->assertDontSee($reason);
            $this->assertSame($counts, $this->qaCounts());
        }
        $this->raw('/plan-entries', '{"tests":{},"baseline_id":"'.$this->baselineId.'"}')->assertUnprocessable();
        $entry = $this->entryInput(0);
        $entry['tests'] = new \stdClass;
        $this->create('/plan-entries', $entry, 'object-tests')->assertUnprocessable();
        $this->postJson($this->url($path), ['expected_revision' => true, 'decision' => 'reviewed', 'reason' => 'Synthetic'])->assertUnprocessable();
    }

    public function test_control_headers_are_typed_required_and_route_specific(): void
    {
        $this->startApi();
        $envelope = $this->qaEnvelope('build', ['logical_key' => 'api-build', 'revision' => 1, 'title' => 'Synthetic', 'content_hash' => str_repeat('a', 64)]);
        $counts = $this->qaCounts();
        foreach ([[], ['Expected-Source-Revision' => '-1'], ['Expected-Source-Revision' => '01'], ['Expected-Source-Revision' => '1.0'], ['Expected-Source-Revision' => '9007199254740992'], ['Expected-Source-Revision' => '0', 'Expected-Series-Revision' => '0']] as $controls) {
            $this->create('/fixtures', $envelope, controls: $controls)->assertUnprocessable();
        }
        $this->postJson($this->url('/fixtures'), $envelope, ['Expected-Source-Revision' => '0'])->assertUnprocessable();
        $this->create('/fixtures?extra=1', $envelope, controls: ['Expected-Source-Revision' => '0'])->assertUnprocessable();
        $this->create('/fixtures', $envelope, 'bad/key', ['Expected-Source-Revision' => '0'])->assertUnprocessable();
        foreach (['Idempotency-Key', 'Expected-Source-Revision', 'Expected-Series-Revision'] as $control) {
            $this->get($this->url('/verification'), [$control => '0'])->assertUnprocessable();
            $this->postJson($this->url('/selections'), [], [$control => '0'])->assertUnprocessable();
        }
        $this->call('GET', $this->url('/verification'), [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'], '{"actor_id":"'.$this->user.'"}')->assertUnprocessable();
        $this->assertSame($counts, $this->qaCounts());
    }

    public function test_handoff_and_defect_use_initial_source_envelopes_and_human_cas_events(): void
    {
        $this->startApi();
        [$body] = $this->executionInputs();
        $report = $this->defectReport($body);
        $defect = $this->create('/defects', $report, 'defect')->assertCreated()->json('data.id');
        $this->create('/defects', $report, 'defect')->assertOk()->assertJsonPath('data.id', $defect);
        $this->postJson($this->url('/defects/'.$defect.'/events'), ['expected_revision' => 1, 'kind' => 'triaged', 'reason' => 'Synthetic triage', 'pins' => new \stdClass])->assertOk()->assertJsonPath('data.state', 'triaged');
        $handoff = $this->handoffData($body);
        $id = $this->create('/handoffs', $handoff, 'handoff')->assertCreated()->json('data.id');
        $this->postJson($this->url('/handoffs/'.$id.'/events'), ['expected_revision' => 1, 'kind' => 'acknowledged', 'reason' => 'Synthetic acknowledgement', 'pins' => new \stdClass])->assertOk()->assertJsonPath('data.state', 'acknowledged');
        $this->postJson($this->url('/handoffs/'.$id.'/events'), ['expected_revision' => 1, 'kind' => 'acknowledged', 'reason' => 'Synthetic retry', 'pins' => new \stdClass])->assertConflict();
        $this->get($this->url('/history/handoff/'.$id))->assertOk()->assertJsonCount(2, 'data.items');
        $this->get($this->url('/queue'))->assertOk()->assertJsonPath('data.items.0.id', $id);
        $report['source_revision'] = 2;
        $this->create('/defects', $report, 'correction')->assertUnprocessable();
        $this->create('/handoffs', $handoff, 'controls', ['Expected-Source-Revision' => '0'])->assertUnprocessable();
    }

    public function test_auth_uuid_head_source_and_project_grants_are_required(): void
    {
        $this->startApi();
        $this->withToken('invalid');
        $this->get($this->url('/verification'))->assertUnauthorized();
        $this->withToken($this->token);
        $this->get('/api/v1/projects/not-uuid/qa/verification')->assertNotFound();
        $this->get($this->url('/history/not-a-kind/'.$this->p))->assertUnprocessable();
        $this->get($this->url('/history/build/'.Str::uuid()))->assertNotFound();
        $this->call('HEAD', $this->url('/verification'), [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token])->assertOk()->assertContent('');
        $this->revokeQaGrant('qa.source.read');
        $this->get($this->url('/verification'))->assertForbidden();
        $this->call('HEAD', $this->url('/verification'), [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token])->assertForbidden()->assertContent('');
        $this->grant('qa.source.read', $this->p);
        $this->revokeQaGrant('qa.read');
        $this->grant('qa.read');
        $this->get($this->url('/verification'))->assertForbidden();
    }

    public function test_cursor_scope_and_revocation_next_page(): void
    {
        $this->startApi();
        [$body] = $this->executionInputs();
        $handoff = $this->create('/handoffs', $this->handoffData($body), 'paged')->assertCreated()->json('data.id');
        $this->postJson($this->url('/handoffs/'.$handoff.'/events'), ['expected_revision' => 1, 'kind' => 'context_added', 'reason' => 'Synthetic context', 'pins' => new \stdClass])->assertOk();
        $path = '/history/handoff/'.$handoff;
        $cursor = $this->get($this->url($path.'?limit=1'))->assertOk()->assertJsonCount(1, 'data.items')->json('data.next_cursor');
        $this->assertNotNull($cursor);
        $this->get($this->url($path.'?limit=1&cursor='.$cursor))->assertOk()->assertJsonPath('data.items.0.revision', 2);
        $this->get($this->url('/queue?cursor='.$cursor))->assertUnprocessable();
        foreach (['limit=101', 'limit=0', 'limit[]=1', 'cursor='.str_repeat('a', 129), 'cursor[]=a', 'extra=1', 'department=not-uuid'] as $query) {
            $this->get($this->url($path.'?'.$query))->assertUnprocessable();
        }
        $this->get($this->url($path.'?limit=100'))->assertOk();
        $this->revokeQaGrant('qa.source.read');
        $this->get($this->url($path.'?limit=1&cursor='.$cursor))->assertForbidden()->assertDontSee($handoff);
    }

    public function test_department_http_checks_and_withheld_global_denominator(): void
    {
        $this->startApi();
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body);
        $department = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($department): void {
            DB::table('departments')->insert(['id' => $department, 'tenant_id' => $this->tenant, 'name' => 'Synthetic API department']);
            DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'employee_id' => $this->employee, 'valid_from' => now()->subDay()]);
        });
        $url = $this->url('/verification?department='.$department);
        $this->get($url)->assertForbidden();
        $this->grant('qa.department.read', department: $department);
        $this->get($url)->assertOk()->assertJsonPath('data.selection', null)->assertJsonPath('data.counts.denominator', null)->assertJsonPath('data.qa_verification.scope_basis', 'department_authorized_entries');
        $this->get($this->url('/queue?department='.$department))->assertOk();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('department_memberships')->where('department_id', $department)->update(['valid_to' => DB::raw('clock_timestamp()'), 'revision' => DB::raw('revision+1')]));
        $this->get($url)->assertForbidden();
        $this->get($this->url('/queue?department='.$department))->assertForbidden();
    }

    public function test_http_history_default_fifty_and_maximum_one_hundred(): void
    {
        $this->startApi();
        [$body] = $this->executionInputs();
        $id = $this->create('/handoffs', $this->handoffData($body), 'default-page')->assertCreated()->json('data.id');
        foreach (range(1, 51) as $revision) {
            $this->postJson($this->url('/handoffs/'.$id.'/events'), ['expected_revision' => $revision, 'kind' => 'context_added', 'reason' => 'Synthetic page '.$revision, 'pins' => new \stdClass])->assertOk();
        }
        $path = '/history/handoff/'.$id;
        $cursor = $this->get($this->url($path))->assertOk()->assertJsonCount(50, 'data.items')->json('data.next_cursor');
        $this->get($this->url($path.'?cursor='.$cursor))->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.revision', 51)->assertJsonPath('data.next_cursor', null);
        $this->get($this->url($path.'?limit=100'))->assertOk()->assertJsonCount(52, 'data.items');
    }

    public function test_admission_503_replay_read_200(): void
    {
        $this->startApi();
        $envelope = $this->qaEnvelope('build', ['logical_key' => 'admission-build', 'revision' => 1, 'title' => 'Synthetic', 'content_hash' => str_repeat('a', 64)], 'admission-build');
        $headers = ['Expected-Source-Revision' => '0'];
        $id = $this->create('/fixtures', $envelope, 'retained', $headers)->assertCreated()->json('data.record_id');
        for ($index = 0; $index < 99; $index++) {
            app(QaPlanService::class)->createEntry($this->actor(), $this->p, $this->entryInput(0), 'admission-entry-'.$index);
        }
        $counts = $this->qaCounts();
        $new = $envelope;
        $new['object_id'] = $new['body']['logical_key'] = 'new-blocked';
        $this->create('/fixtures', $new, 'blocked', $headers)->assertStatus(503);
        $this->assertSame($counts, $this->qaCounts());
        $this->create('/fixtures', $envelope, 'retained', $headers)->assertOk()->assertJsonPath('data.record_id', $id);
        $this->get($this->url('/verification'))->assertOk();
    }

    public function test_guarded_cli_no_self_grants_and_fixture_replay(): void
    {
        $this->startApi();
        $fixture = tempnam(sys_get_temp_dir(), 'qa-fixture-');
        $token = tempnam(sys_get_temp_dir(), 'qa-token-');
        $envelope = $this->qaEnvelope('build', ['logical_key' => 'cli-build', 'revision' => 1, 'title' => 'Synthetic CLI', 'content_hash' => str_repeat('a', 64)], 'cli-build');
        file_put_contents($fixture, QaContract::canonical($envelope));
        file_put_contents($token, $this->token);
        $args = ['project' => $this->p, 'fixture' => $fixture, '--token-file' => $token, '--expected-source-revision' => '0'];
        $config = config('database.connections.pgsql');
        try {
            $grants = app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->count());
            $this->artisan('app:qa-fixture', $args)->assertSuccessful();
            $counts = $this->qaCounts();
            $args['--expected-source-revision'] = '999';
            $this->assertSame(0, Artisan::call('app:qa-fixture', $args));
            $this->assertStringContainsString('replayed=1', Artisan::output());
            $this->assertStringNotContainsString($this->token, Artisan::output());
            $this->assertSame($counts, $this->qaCounts());
            foreach (['port' => '5432', 'host' => 'example.invalid', 'database' => 'other', 'username' => 'monitoring_ai_owner', 'url' => 'postgres://invalid', 'read' => ['host' => 'localhost'], 'write' => ['host' => 'localhost'], 'options' => [1], 'search_path' => 'other'] as $key => $value) {
                config(['database.connections.pgsql' => array_replace($config, [$key => $value])]);
                $this->artisan('app:qa-fixture', $args)->assertFailed();
                $this->artisan('app:qa-drain', ['tenant' => $this->tenant, 'actor' => $this->user, 'project' => $this->p])->assertFailed();
            }
            config(['database.connections.pgsql' => $config]);
            $marker = config('qa.database_marker');
            config(['qa.database_marker' => 'wrong-marker:']);
            $this->artisan('app:qa-fixture', $args)->assertFailed();
            $this->artisan('app:qa-drain', ['tenant' => $this->tenant, 'actor' => $this->user, 'project' => $this->p])->assertFailed();
            config(['qa.database_marker' => $marker]);
            $this->app->instance('env', 'production');
            $this->artisan('app:qa-fixture', $args)->assertFailed();
            $this->artisan('app:qa-drain', ['tenant' => $this->tenant, 'actor' => $this->user, 'project' => $this->p])->assertFailed();
            $this->app->instance('env', 'testing');
            $this->artisan('app:qa-drain', ['tenant' => $this->tenant, 'actor' => $this->user, 'project' => $this->p, '--limit' => 21])->assertFailed();
            $this->artisan('app:qa-drain', ['tenant' => $this->tenant, 'actor' => $this->user, 'project' => $this->p, '--limit' => '1'])->assertSuccessful();
            $this->revokeQaGrant('qa.fixture.manage');
            $this->artisan('app:qa-fixture', $args)->assertFailed();
            $this->revokeQaGrant('qa.process');
            $this->artisan('app:qa-drain', ['tenant' => $this->tenant, 'actor' => $this->user, 'project' => $this->p])->assertFailed();
            $this->assertSame($grants, app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->count()));
            file_put_contents($fixture, str_repeat(' ', 65537));
            $this->artisan('app:qa-fixture', $args)->assertFailed();
        } finally {
            config(['database.connections.pgsql' => $config]);
            $this->app->instance('env', 'testing');
            unlink($fixture);
            unlink($token);
        }
    }
}
