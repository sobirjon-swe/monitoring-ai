<?php

namespace App\Console\Commands;

use App\Domain\DeveloperAnalysis\SyntheticMrRegistry;
use App\Domain\Identity\LocalTokenAuthenticator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RegisterLocalDeveloperFixture extends Command
{
    protected $signature = 'app:developer-fixture {project} {fixture : Local JSON fixture file} {--token-file= : Explicit local bearer token file} {--expected-revision= : Current source pointer CAS revision}';

    protected $description = 'Register a synthetic developer fixture through an existing local authenticated subject';

    public function handle(LocalTokenAuthenticator $auth, SyntheticMrRegistry $registry): int
    {
        $config = config('database.connections.pgsql');
        $expected = app()->environment('testing') ? 'monitoring_ai_test' : 'monitoring_ai_dev';
        $revision = (string) $this->option('expected-revision');
        if (! app()->environment(['local', 'testing']) || config('database.default') !== 'pgsql' || ($config['driver'] ?? null) !== 'pgsql'
            || ! in_array($config['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true) || ! in_array($config['url'] ?? null, [null, ''], true)
            || ! empty($config['read']) || ! empty($config['write']) || ($config['database'] ?? null) !== $expected || ($config['username'] ?? null) !== 'monitoring_ai_app'
            || ! Str::isUuid($this->argument('project')) || ! preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $revision) || (int) $revision > 2147483647
            || ! is_string($this->option('token-file')) || ! is_file($this->option('token-file')) || ! is_file($this->argument('fixture'))) {
            $this->error('Local fixture registration refused.');

            return self::FAILURE;
        }
        try {
            $identity = DB::selectOne("select current_database() as database, session_user as login, current_user as role, r.rolsuper, r.rolbypassrls, shobj_description(d.oid, 'pg_database') as marker from pg_database d join pg_roles r on r.rolname=current_user where d.datname=current_database()");
            if ($identity->database !== $expected || $identity->login !== 'monitoring_ai_app' || $identity->role !== 'monitoring_ai_app'
                || $identity->rolsuper || $identity->rolbypassrls || ! str_starts_with($identity->marker ?? '', 'monitoring-ai-local-v1:')
                || DB::scalar("select exists(select 1 from pg_class c join pg_roles r on r.oid=c.relowner where c.relname in ('developer_snapshots','developer_runs','developer_outbox') and r.rolname=current_user)")) {
                throw new \LogicException;
            }
            // Credentials are never put in argv, echoed or passed to the parser.
            $token = file_get_contents($this->option('token-file'), false, null, 0, 513);
            $actor = $auth->authenticate(trim($token));
            if (! $actor || strlen($token) > 512) {
                throw new \LogicException;
            }
            $raw = file_get_contents($this->argument('fixture'), false, null, 0, 1048577);
            if (strlen($raw) > 1048576) {
                throw new \LogicException;
            }
            $object = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
            if (! is_object($object)) {
                throw new \LogicException;
            }
            preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\\[\\]:,]|[^\\s{}\\[\\]:,]+/s', $raw, $matches);
            $keys = [];
            foreach ($matches[0] as $index => $value) {
                if (($matches[0][$index + 1] ?? null) === ':') {
                    $key = json_decode($value, true, 2, JSON_THROW_ON_ERROR);
                    if (! is_string($key) || isset($keys[$key])) {
                        throw new \LogicException;
                    }
                    $keys[$key] = true;
                }
            }
            $result = $registry->register($actor, strtolower($this->argument('project')), json_decode($raw, true, 16, JSON_THROW_ON_ERROR), (int) $revision);
            $this->line('accepted='.($result['accepted'] ? '1' : '0').' event='.$result['event_id'].' revision='.$result['revision'].' reason='.$result['reason_code']);

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Local fixture registration failed.');

            return self::FAILURE;
        }
    }
}
