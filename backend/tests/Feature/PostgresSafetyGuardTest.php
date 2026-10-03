<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\PostgresTestCase;
use Tests\TestCase;
use Throwable;

class PostgresSafetyGuardTest extends TestCase
{
    public static function unsafeTestEndpoints(): array
    {
        return [
            'runtime remote host' => ['pgsql', 'host', 'untrusted.example'],
            'migration remote host' => ['pgsql_migration', 'host', 'untrusted.example'],
            'runtime URL override' => ['pgsql', 'url', 'pgsql://fixture:synthetic@untrusted.example/monitoring_ai_test'],
            'migration URL override' => ['pgsql_migration', 'url', 'pgsql://fixture:synthetic@untrusted.example/monitoring_ai_test'],
            'runtime read override' => ['pgsql', 'read', ['host' => 'untrusted.example']],
            'migration write override' => ['pgsql_migration', 'write', ['host' => 'untrusted.example']],
        ];
    }

    #[DataProvider('unsafeTestEndpoints')]
    public function test_fixture_setup_refuses_unsafe_endpoint_before_any_database_access(string $connection, string $key, mixed $value): void
    {
        $probe = new EndpointGuardProbe('probeEndpointGuard');
        $probe->override = ["database.connections.$connection.$key" => $value];
        $failure = null;
        try {
            $probe->probeEndpointGuard();
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            $probe->closeProbe();
        }
        $this->assertInstanceOf(AssertionFailedError::class, $failure, 'Fixture setup must refuse the endpoint before database access.');
    }

    public static function unsafeMigrationEndpoints(): array
    {
        return [
            'URL replaces checked host' => ['url', 'pgsql://fixture:synthetic@untrusted.example/monitoring_ai_test'],
            'read replaces checked host' => ['read', ['host' => 'untrusted.example']],
            'write replaces checked host' => ['write', ['host' => 'untrusted.example']],
        ];
    }

    #[DataProvider('unsafeMigrationEndpoints')]
    public function test_migration_refuses_endpoint_overrides_before_any_database_access(string $key, mixed $value): void
    {
        config(["database.connections.pgsql_migration.$key" => $value,
            'database.connections.pgsql_migration.password' => 'synthetic-secret']);
        // Intercept only the dangerous boundary so a regression cannot contact a remote database.
        DB::shouldReceive('connection')->andThrow(new RuntimeException('Database accessed before endpoint guard.'));
        try {
            $this->artisan('app:migrate-local')->expectsOutput('Migration refused: use the dedicated local migration configuration.')
                ->assertFailed();
        } catch (RuntimeException) {
            $this->fail('Migration attempted database access before refusing the endpoint override.');
        }
    }
}

class EndpointGuardProbe extends PostgresTestCase
{
    public array $override = [];

    public function createApplication()
    {
        $application = parent::createApplication();
        config($this->override);
        // The real test setup runs; only DB access is stopped to make the red run safe.
        DB::shouldReceive('scalar', 'connection', 'getSchemaBuilder')
            ->andThrow(new RuntimeException('Database accessed before endpoint guard.'));

        return $application;
    }

    public function probeEndpointGuard(): void
    {
        parent::setUp();
    }

    public function closeProbe(): void
    {
        parent::tearDown();
    }
}
