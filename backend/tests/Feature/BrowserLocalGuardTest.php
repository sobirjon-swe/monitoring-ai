<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrowserLocalGuardTest extends TestCase
{
    public function test_each_unsafe_override_refuses_before_any_database_acquisition(): void
    {
        config(['logging.default' => 'null']);
        app('url')->forceRootUrl('http://127.0.0.1:18080');
        $this->withServerVariables(['HTTP_HOST' => '127.0.0.1:18080', 'SERVER_PORT' => 18080]);
        $acquired = false;
        DB::purge('pgsql');
        DB::extend('pgsql', function () use (&$acquired): never {
            $acquired = true;
            throw new \LogicException('Unexpected connection.');
        });
        $overrides = ['session.driver' => 'cookie', 'session.domain' => '.example.test', 'session.secure' => true,
            'session.http_only' => false, 'session.same_site' => 'none', 'session.path' => '/app',
            'session.partitioned' => true, 'session.lifetime' => 121, 'session.files' => '/tmp',
            'session.serialization' => 'php', 'browser.origin' => 'http://remote.invalid',
            'database.connections.pgsql.host' => '203.0.113.1', 'database.connections.pgsql.url' => 'invalid',
            'database.connections.pgsql.read' => ['host' => 'remote.invalid'], 'database.connections.pgsql.options' => [1 => 'unsafe']];
        foreach ($overrides as $key => $value) {
            $original = config($key);
            config([$key => $value]);
            $this->getJson('/browser/v1/session')->assertStatus(503);
            $this->assertFalse($acquired, 'Unsafe override acquired a connection.');
            config([$key => $original]);
        }
        foreach (['Forwarded', 'X-Forwarded-Host', 'X-Forwarded-For', 'X-Forwarded-Proto'] as $header) {
            $this->withHeader($header, 'forged')->getJson('/browser/v1/session')->assertStatus(503);
            $this->assertFalse($acquired, 'Forwarded request acquired a connection.');
            $this->flushHeaders();
        }
        config(['browser.client' => '203.0.113.1']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->getJson('/browser/v1/session')->assertStatus(503);
        $this->assertFalse($acquired, 'Configured nonlocal client acquired a connection.');
        app('url')->forceRootUrl('http://remote.invalid');
        $this->getJson('/browser/v1/session')->assertStatus(503);
        $this->assertFalse($acquired);
        app()->instance('env', 'production');
        $this->getJson('/browser/v1/session')->assertStatus(503);
        $this->assertFalse($acquired);
    }

    public function test_unsafe_configuration_and_host_are_rejected_before_connection(): void
    {
        config(['logging.default' => 'null']);
        $acquired = false;
        DB::extend('browser_sentinel', function () use (&$acquired): never {
            $acquired = true;
            throw new \LogicException('Unexpected connection.');
        });
        config(['database.default' => 'browser_sentinel', 'database.connections.browser_sentinel' => ['driver' => 'browser_sentinel']]);
        foreach (['/browser/v1/session', '/%62rowser/v1/session', '/browser%2fv1/session', '/browser/v1/session/', '/browser/v1/%73ession'] as $path) {
            $this->getJson($path)->assertStatus(503);
            $this->head($path)->assertStatus(503)->assertContent('');
        }
        $this->assertFalse($acquired, 'Unsafe runtime acquired a connection.');
    }
}
