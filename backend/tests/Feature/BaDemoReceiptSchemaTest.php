<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\AccessTestCase;

final class BaDemoReceiptSchemaTest extends AccessTestCase
{
    public function test_runtime_cannot_mutate_immutable_receipts(): void
    {
        foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
            $this->assertFalse((bool) DB::scalar('select has_table_privilege(current_user, ?, ?)', ['public.ba_demo_receipts', $privilege]), $privilege);
        }
    }
}
