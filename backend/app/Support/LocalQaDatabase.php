<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

final class LocalQaDatabase
{
    public function assertRuntime(): void
    {
        $environment = app()->environment();
        $expected = config('qa.local_databases.'.$environment);
        $config = config('database.connections.pgsql');
        $role = config('qa.runtime_role');
        if (! in_array($environment, ['local', 'testing'], true) || ! is_string($expected)
            || config('database.default') !== 'pgsql' || ($config['driver'] ?? null) !== 'pgsql'
            || ! in_array($config['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            || (string) ($config['port'] ?? '') !== (string) config('qa.local_port')
            || ! in_array($config['url'] ?? null, [null, ''], true) || ! empty($config['read']) || ! empty($config['write'])
            || ($config['database'] ?? null) !== $expected || ($config['username'] ?? null) !== $role
            || ($config['search_path'] ?? '') !== 'public' || ! empty($config['options'])) {
            throw new LogicException('Local QA runtime refused.');
        }
        $identity = DB::selectOne("select current_database() as database, session_user as login, current_user as role, inet_server_port() as port, r.rolsuper, r.rolbypassrls, shobj_description(d.oid,'pg_database') as marker from pg_database d join pg_roles r on r.rolname=current_user where d.datname=current_database()");
        if ($identity->database !== $expected || $identity->login !== $role || $identity->role !== $role
            || (int) $identity->port !== config('qa.local_port') || $identity->rolsuper || $identity->rolbypassrls
            || ! str_starts_with($identity->marker ?? '', config('qa.database_marker'))
            || DB::scalar("select exists(select 1 from pg_class c join pg_namespace n on n.oid=c.relnamespace join pg_roles r on r.oid=c.relowner where n.nspname='public' and c.relkind in ('r','p') and left(c.relname,3)='qa_' and r.rolname=current_user)")) {
            throw new LogicException('Local QA runtime refused.');
        }
    }
}
