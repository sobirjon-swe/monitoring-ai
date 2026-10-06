<?php

namespace App\Console\Commands;

use App\Domain\DeveloperAnalysis\AnalysisProcessor;
use App\Domain\Identity\LocalTokenAuthenticator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DrainLocalDeveloperAnalysis extends Command
{
    protected $signature = 'app:drain-developer-analysis {project} {--token-file= : Explicit existing local bearer token file} {--limit=20 : Maximum execution batch, 1 to 20}';

    protected $description = 'Drain durable synthetic developer analysis through an existing authorized operator';

    public function handle(LocalTokenAuthenticator $auth, AnalysisProcessor $processor): int
    {
        $config = config('database.connections.pgsql');
        $expected = app()->environment('testing') ? 'monitoring_ai_test' : 'monitoring_ai_dev';
        $limit = (string) $this->option('limit');
        if (! app()->environment(['local', 'testing']) || config('database.default') !== 'pgsql' || ($config['driver'] ?? null) !== 'pgsql'
            || ! in_array($config['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true) || ! in_array($config['url'] ?? null, [null, ''], true)
            || ! empty($config['read']) || ! empty($config['write']) || ($config['database'] ?? null) !== $expected || ($config['username'] ?? null) !== 'monitoring_ai_app'
            || ! Str::isUuid($this->argument('project')) || ! preg_match('/\A(?:[1-9]|1[0-9]|20)\z/D', $limit)
            || ! is_string($this->option('token-file')) || ! is_file($this->option('token-file'))) {
            $this->error('Local analysis drain refused.');

            return self::FAILURE;
        }
        try {
            $identity = DB::selectOne("select current_database() as database, session_user as login, current_user as role, r.rolsuper, r.rolbypassrls, shobj_description(d.oid, 'pg_database') as marker from pg_database d join pg_roles r on r.rolname=current_user where d.datname=current_database()");
            if ($identity->database !== $expected || $identity->login !== 'monitoring_ai_app' || $identity->role !== 'monitoring_ai_app' || $identity->rolsuper || $identity->rolbypassrls
                || ! str_starts_with($identity->marker ?? '', 'monitoring-ai-local-v1:') || DB::scalar("select exists(select 1 from pg_class c join pg_roles r on r.oid=c.relowner where c.relname in ('developer_runs','developer_results','developer_outbox') and r.rolname=current_user)")) {
                throw new \LogicException;
            }
            $token = file_get_contents($this->option('token-file'), false, null, 0, 513);
            $actor = $auth->authenticate(trim($token));
            if (! $actor || strlen($token) > 512) {
                throw new \LogicException;
            }
            $result = $processor->drain($actor, strtolower($this->argument('project')), (int) $limit);
            $this->line('processed='.$result['processed']);

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Local analysis drain failed.');

            return self::FAILURE;
        }
    }
}
