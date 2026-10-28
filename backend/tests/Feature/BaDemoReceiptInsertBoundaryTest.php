<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\AccessTestCase;

final class BaDemoReceiptInsertBoundaryTest extends AccessTestCase
{
    public function test_application_role_cannot_insert_receipts(): void
    {
        $this->assertFalse((bool) DB::scalar("select has_table_privilege(current_user, 'public.ba_demo_receipts', 'INSERT')"));
        $this->assertFalse((bool) DB::scalar("select exists(select 1 from pg_class c cross join lateral aclexplode(c.relacl) a where c.oid = 'public.ba_demo_receipts'::regclass and a.grantee = 0 and a.privilege_type = 'INSERT')"));
    }
}
