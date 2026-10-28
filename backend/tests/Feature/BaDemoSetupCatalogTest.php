<?php

namespace Tests\Feature;

use App\Domain\Browser\BaDemo\BaDemoSetupCatalog;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessTestCase;

final class BaDemoSetupCatalogTest extends AccessTestCase
{
    public function test_inspects_the_actual_runtime_connection_without_authorizing_it(): void
    {
        $this->assertTrue(class_exists(BaDemoSetupCatalog::class), 'Setup catalog inspector missing.');
        $facts = app(BaDemoSetupCatalog::class)->inspect(DB::connection('pgsql'));
        $this->assertSame('monitoring_ai_test', $facts['database']);
        $this->assertSame('monitoring_ai_app', $facts['session_role']);
        $this->assertSame('monitoring_ai_app', $facts['current_role']);
        $this->assertFalse($facts['superuser']);
        $this->assertFalse($facts['bypass_rls']);
        $this->assertFalse($facts['receipt_insert']);
    }
}
