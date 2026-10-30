<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class PostgresTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['logging.default' => 'null']);

        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSafeDatabaseEndpoints();
        $this->assertSame('monitoring_ai_test', config('database.connections.pgsql.database'));
        $this->assertSame('monitoring_ai_test', DB::scalar('select current_database()'));
        $this->assertSame('monitoring_ai_app', DB::scalar('select current_user'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('tenants'), 'Tenant foundation must be migrated in the dedicated test database.');
        // Retained concurrency evidence lives in ignored, checkout-local folders.
        // Fresh checkouts do not contain these generated directories.
        foreach ([
            '../.local',
            '../.superpowers/sdd/2026-10-06-e05-qa-handoff-plan',
            '../.superpowers/sdd/2026-10-07-e05-ba-clarification-plan',
            '../.superpowers/sdd/2026-10-09-e05-management-support-plan',
        ] as $directory) {
            app('files')->ensureDirectoryExists(base_path($directory));
        }
    }

    protected function ownerConnection(): Connection
    {
        $this->assertSafeDatabaseEndpoints();
        $this->assertSame('monitoring_ai_test', config('database.connections.pgsql_migration.database'));
        $connection = DB::connection('pgsql_migration');
        $this->assertSame('monitoring_ai_test', $connection->scalar('select current_database()'));
        $this->assertSame('monitoring_ai_migrator', $connection->scalar('select session_user'));
        $connection->statement('SET ROLE monitoring_ai_owner');

        return $connection;
    }

    private function assertSafeDatabaseEndpoints(): void
    {
        // Validate both connections before fixture reads/writes; URLs and split hosts override host.
        foreach (['pgsql', 'pgsql_migration'] as $name) {
            $config = config("database.connections.$name");
            $this->assertSame('pgsql', $config['driver'] ?? null);
            $this->assertTrue(in_array($config['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true), 'Test database endpoints must be loopback.');
            $this->assertTrue(in_array($config['url'] ?? null, [null, ''], true), 'Test database URL overrides are prohibited.');
            $this->assertEmpty($config['read'] ?? null, 'Test database read endpoint overrides are prohibited.');
            $this->assertEmpty($config['write'] ?? null, 'Test database write endpoint overrides are prohibited.');
        }
    }
}
