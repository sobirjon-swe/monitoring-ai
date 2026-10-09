<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

final class LocalBaDatabase
{
    public function assertRuntime(): void
    {
        app(LocalQaDatabase::class)->assertRuntime();
        if (DB::scalar("select exists(select 1 from pg_class c join pg_namespace n on n.oid=c.relnamespace join pg_roles r on r.oid=c.relowner where n.nspname='public' and c.relkind in ('r','p') and left(c.relname,3)='ba_' and r.rolname=current_user)")) {
            throw new LogicException('Local BA runtime refused.');
        }
    }
}
