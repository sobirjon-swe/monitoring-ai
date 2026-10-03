<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class MigrateLocal extends Command
{
    protected $signature = 'app:migrate-local';

    protected $description = 'Incrementally migrate the dedicated local database with the separate owner connection';

    public function handle(): int
    {
        $config = config('database.connections.pgsql_migration');
        $expectedDatabase = app()->environment('testing') ? 'monitoring_ai_test' : 'monitoring_ai_dev';
        if (! app()->environment(['local', 'testing'])
            || ($config['driver'] ?? null) !== 'pgsql'
            || ! in_array($config['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            || ! in_array($config['url'] ?? null, [null, ''], true)
            || ! empty($config['read']) || ! empty($config['write'])
            || ($config['database'] ?? null) !== $expectedDatabase
            || ($config['username'] ?? null) !== 'monitoring_ai_migrator'
            || empty($config['password'])) {
            $this->error('Migration refused: use the dedicated local migration configuration.');

            return self::FAILURE;
        }

        $connection = DB::connection('pgsql_migration');
        try {
            $identity = $connection->selectOne("select current_database() as database, session_user as login, shobj_description(oid, 'pg_database') as marker from pg_database where datname = current_database()");
            if ($identity->database !== $expectedDatabase || $identity->login !== 'monitoring_ai_migrator'
                || ! str_starts_with($identity->marker ?? '', 'monitoring-ai-local-v1:')) {
                throw new RuntimeException('Unexpected local database identity.');
            }
            // The same PDO connection owns all DDL and the migration history table.
            $connection->statement('SET ROLE monitoring_ai_owner');
            $result = $this->call('migrate', ['--database' => 'pgsql_migration', '--force' => true]);
            $connection->statement('REVOKE ALL ON TABLE public.migrations FROM monitoring_ai_app');

            return $result;
        } catch (Throwable) {
            // SQL exceptions may contain credentials, SQL, or bindings: emit only a safe failure.
            $this->error('Local migration failed. Check the dedicated database configuration.');

            return self::FAILURE;
        } finally {
            // Discard the owner session even after failed DDL; runtime always uses another connection.
            DB::purge('pgsql_migration');
        }
    }
}
