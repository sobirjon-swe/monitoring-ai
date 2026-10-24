<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementActionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\ManagementTestCase;

class BrowserReadTransportTest extends ManagementTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'array', 'session.secure' => false]);
        app('url')->forceRootUrl('http://127.0.0.1:18080');
        $this->withServerVariables(['HTTP_HOST' => '127.0.0.1:18080', 'SERVER_PORT' => 18080]);
    }

    private function browserInputs(array $purposes): array
    {
        $this->fixture();
        $project = $this->project();
        foreach ($purposes as $purpose) {
            $this->grant($purpose, $project);
        }

        return ['project' => $project];
    }

    public function test_read_aliases_match_authoritative_dtos_and_forbid_business_writes(): void
    {
        $f = $this->browserInputs(['project.read', 'requirements.read', 'progress.read', 'qa.read', 'qa.source.read', 'ba.read', 'ba.source.read', 'management.read', 'management.source.read']);
        $paths = ['requirements', 'lifecycle-tasks', 'progress', 'qa/verification', 'qa/queue', 'ba/queue', 'management/queue'];
        $expected = [];
        foreach ($paths as $path) {
            $expected[$path] = $this->withToken($this->token)->get('/api/v1/projects/'.$f['project'].'/'.$path)->assertOk()->json('data');
        }
        $this->flushHeaders();
        $this->postJson('/browser/v1/login', ['token' => $this->token])->assertOk();
        $this->get('/browser/v1/projects')->assertOk()->assertJsonPath('data.items.0.id', $f['project']);
        foreach ($paths as $path) {
            $url = '/browser/v1/projects/'.$f['project'].'/'.$path;
            $this->assertSame($expected[$path], $this->get($url)->assertOk()->json('data'));
            $this->postJson($url, [])->assertStatus(405);
            $this->head($url)->assertOk()->assertContent('');
        }
        $this->get('/browser/v1/projects/'.$f['project'].'/capabilities')->assertOk()->assertJsonPath('data.purposes', ['project.read', 'requirements.read', 'progress.read', 'qa.read', 'qa.source.read', 'ba.read', 'ba.source.read', 'management.read', 'management.source.read']);
        $this->get('/api/v1/projects/'.$f['project'].'/requirements')->assertUnauthorized();
    }

    public function test_read_authentication_forbidden_and_query_validation(): void
    {
        $f = $this->browserInputs(['project.read']);
        $url = '/browser/v1/projects/'.$f['project'];
        $this->get($url.'/requirements')->assertUnauthorized();
        $this->postJson('/browser/v1/login', ['token' => $this->token])->assertOk();
        $this->get($url.'/requirements')->assertForbidden();
        $this->get('/browser/v1/projects?cursor=invalid')->assertUnprocessable();
        $this->get('/browser/v1/projects?limit=99')->assertUnprocessable();
        $this->grant('ba.read', $f['project']);
        $this->grant('management.read', $f['project']);
        $this->get($url.'/ba/queue?department_id='.$this->employee)->assertUnprocessable();
        $this->get($url.'/management/queue?department='.$this->employee)->assertUnprocessable();
    }

    public function test_populated_reads_preserve_partial_null_private_and_history_dtos_and_source_revocation(): void
    {
        $this->managementInputs();
        $ba = $this->managementBaFixture();
        $qa = $this->managementQaExecutionFixture();
        $action = app(ManagementActionService::class)->open($this->actor(), $this->p, $this->managementEnvelope('action.open', $this->actionInput()), 'browser-action', 0);
        $paths = ['requirements', 'lifecycle-tasks', 'progress', 'qa/verification', 'qa/queue', 'qa/history/defect/'.$qa['defect']['id'], 'ba/queue', 'ba/history/'.$ba['clarification_id'], 'management/queue', 'management/history/'.$action['action_id']];
        $this->postJson('/browser/v1/login', ['token' => $this->token])->assertOk();
        foreach ($paths as $path) {
            $api = $this->withToken($this->token)->get('/api/v1/projects/'.$this->p.'/'.$path)->assertOk();
            $this->flushHeaders();
            $browser = $this->get('/browser/v1/projects/'.$this->p.'/'.$path)->assertOk();
            if (str_starts_with($path, 'management/') || $path === 'ba/queue') {
                $apiData = $api->json();
                $browserData = $browser->json();
                foreach ($apiData['data']['items'] as $index => &$item) {
                    // Each response computes age using PostgreSQL's actual delivery clock.
                    $ageFields = $path === 'ba/queue' ? ['elapsed_seconds'] : ['elapsed_seconds', 'episode_elapsed_seconds'];
                    foreach ($ageFields as $field) {
                        $age = $browserData['data']['items'][$index][$field];
                        $this->assertIsInt($age);
                        $this->assertGreaterThanOrEqual($item[$field], $age);
                        $this->assertLessThanOrEqual($item[$field] + 10, $age);
                        $item[$field] = $age;
                    }
                    $age = $browserData['data']['items'][$index]['elapsed_seconds'];
                    $this->assertSame($path === 'ba/queue' ? $age : ['seconds' => $age], $browserData['data']['items'][$index]['elapsed_age']);
                    $item['elapsed_age'] = $browserData['data']['items'][$index]['elapsed_age'];
                }
                unset($item);
                $this->assertSame($apiData, $browserData, $path);
            } else {
                $this->assertSame($api->getContent(), $browser->getContent(), $path);
            }
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_connectors')->where('source_instance_id', $this->managementSource)->update(['status' => 'disabled', 'revision' => 2]));
        $api = $this->withToken($this->token)->get('/api/v1/projects/'.$this->p.'/management/queue')->assertOk();
        $this->flushHeaders();
        $browser = $this->get('/browser/v1/projects/'.$this->p.'/management/queue')->assertOk()->assertJsonPath('data.visible_record_count', 0)->assertJsonPath('data.items', []);
        $this->assertSame($api->getContent(), $browser->getContent());
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('lifecycle_project_state')->where('project_id', $this->p)->update(['is_closed' => true]));
        foreach (['ba', 'management'] as $module) {
            $api = $this->withToken($this->token)->get('/api/v1/projects/'.$this->p.'/'.$module.'/queue')->assertOk();
            $this->flushHeaders();
            $browser = $this->get('/browser/v1/projects/'.$this->p.'/'.$module.'/queue')->assertOk()->assertJsonPath('data.visible_record_count', null)->assertJsonPath('data.coverage_status', 'unavailable');
            $this->assertSame($api->getContent(), $browser->getContent());
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'management.source.read')->update(['revoked_at' => now()]));
        $this->get('/browser/v1/projects/'.$this->p.'/management/queue')->assertForbidden();
    }

    public function test_encoded_browser_management_guard_rejects_before_database_and_preserves_head(): void
    {
        $f = $this->browserInputs([]);
        $original = config('database.connections.pgsql');
        try {
            config(['database.connections.pgsql.host' => 'example.invalid']);
            DB::enableQueryLog();
            DB::flushQueryLog();
            foreach (['/browser/v1/projects/'.$f['project'].'/management/queue', '/%62rowser/v1/%70rojects/'.$f['project'].'/%6danagement/queue'] as $path) {
                $this->get($path)->assertStatus(503);
                $this->head($path)->assertStatus(503)->assertContent('');
            }
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            config(['database.connections.pgsql' => $original]);
        }
    }
}
