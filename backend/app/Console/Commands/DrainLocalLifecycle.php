<?php

namespace App\Console\Commands;

use App\Domain\Access\TrustedActor;
use App\Domain\Lifecycle\LifecycleInvalidationProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DrainLocalLifecycle extends Command
{
    protected $signature = 'app:lifecycle-drain {tenant} {actor} {project} {--limit=20}';

    protected $description = 'Explicit local operator drain of synthetic lifecycle outbox';

    public function handle(LifecycleInvalidationProcessor $processor): int
    {
        $config = config('database.connections.pgsql');
        $expected = app()->environment('testing') ? 'monitoring_ai_test' : 'monitoring_ai_dev';
        $limit = (string) $this->option('limit');
        if (! app()->environment(['local', 'testing']) || config('database.default') !== 'pgsql'
            || ($config['driver'] ?? null) !== 'pgsql'
            || ! in_array($config['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            || ! in_array($config['url'] ?? null, [null, ''], true) || ! empty($config['read']) || ! empty($config['write'])
            || ($config['database'] ?? null) !== $expected || ($config['username'] ?? null) !== 'monitoring_ai_app'
            || ! preg_match('/^[1-9][0-9]{0,2}$/D', $limit) || (int) $limit > 100
            || ! Str::isUuid($this->argument('tenant')) || ! Str::isUuid($this->argument('actor')) || ! Str::isUuid($this->argument('project'))) {
            $this->error('Local lifecycle drain refused.');

            return self::FAILURE;
        }
        try {
            $identity = DB::selectOne("select current_database() as database, session_user as login, current_user as role, r.rolsuper, r.rolbypassrls, shobj_description(d.oid, 'pg_database') as marker from pg_database d join pg_roles r on r.rolname=current_user where d.datname=current_database()");
            if ($identity->database !== $expected || $identity->login !== 'monitoring_ai_app' || $identity->role !== 'monitoring_ai_app'
                || $identity->rolsuper || $identity->rolbypassrls || ! str_starts_with($identity->marker ?? '', 'monitoring-ai-local-v1:')
                || DB::scalar("select exists(select 1 from pg_class c join pg_roles r on r.oid=c.relowner where c.relname in ('lifecycle_changes','lifecycle_outbox','acceptance_applicability') and r.rolname=current_user)")) {
                $this->error('Local lifecycle drain refused.');

                return self::FAILURE;
            }
            $counts = $processor->drain(new TrustedActor(strtolower($this->argument('actor')), strtolower($this->argument('tenant'))), strtolower($this->argument('project')), (int) $limit);
            $this->line(implode(' ', array_map(fn ($key, $value) => $key.'='.$value, array_keys($counts), array_values($counts))));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Local lifecycle drain failed.');

            return self::FAILURE;
        }
    }
}
