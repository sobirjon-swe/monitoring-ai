<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\ManagementTestCase;

class ManagementLocalGuardTest extends ManagementTestCase
{
    public function test_invalid_configuration_is_rejected_before_any_bearer_database_query(): void
    {
        $this->managementInputs();
        $this->withToken($this->token);
        $original = config('database.connections.pgsql');
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        try {
            foreach (['host' => 'example.invalid', 'port' => '5432', 'url' => 'postgres://invalid', 'read' => ['host' => 'localhost'], 'write' => ['host' => 'localhost'], 'options' => [1], 'username' => 'monitoring_ai_owner', 'database' => 'other', 'search_path' => 'other'] as $field => $value) {
                config(['database.connections.pgsql' => array_replace($original, [$field => $value])]);
                $queries = [];
                foreach (['/api/v1/projects/'.$this->p.'/management/queue', '/api/v1/projects/invalid/management/queue?unknown=x', '/api/v1/projects/'.$this->p.'/%6danagement/queue', '/%61pi/v1/%70rojects/'.$this->p.'/management/queue'] as $path) {
                    foreach (['GET', 'HEAD', 'POST'] as $method) {
                        $response = $this->call($method, $path);
                        $response->assertStatus(503)->assertDontSee('example.invalid');
                        if ($method === 'HEAD') {
                            $response->assertContent('');
                        }
                        $this->assertSame([], $queries, $field.' reached database before rejecting config');
                    }
                }
            }
        } finally {
            config(['database.connections.pgsql' => $original]);
        }
        $this->assertSame(0, app(TenantContext::class)->run($this->tenant, fn () => DB::table('management_actions')->count()));
    }

    public function test_actual_marker_and_environment_failures_are_safe_and_head_empty(): void
    {
        $this->managementInputs();
        $this->withToken($this->token);
        $marker = config('qa.database_marker');
        try {
            config(['qa.database_marker' => 'synthetic-wrong-marker:']);
            $this->get('/api/v1/projects/'.$this->p.'/management/queue')->assertStatus(503);
            $this->call('HEAD', '/api/v1/projects/'.$this->p.'/management/queue')->assertStatus(503)->assertContent('');
            config(['qa.database_marker' => $marker]);
            app()->instance('env', 'production');
            $this->get('/api/v1/projects/'.$this->p.'/management/queue')->assertStatus(503);
        } finally {
            config(['qa.database_marker' => $marker]);
            app()->instance('env', 'testing');
        }
        $this->withoutHeader('Authorization');
        $this->get('/api/v1/me')->assertUnauthorized();
        $this->get('/api/v1/projects/'.$this->p.'/ba/queue')->assertUnauthorized();
        $this->get('/api/v1/projects/'.$this->p.'/management/queue')->assertUnauthorized();
    }
}
