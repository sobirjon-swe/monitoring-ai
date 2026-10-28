<?php

namespace Tests\Feature;

use App\Domain\Browser\BaDemo\BaDemoSetupRoleGuard;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessTestCase;

final class BaDemoSetupRoleGuardTest extends AccessTestCase
{
    public function test_application_runtime_is_never_a_setup_role(): void
    {
        $this->assertTrue(class_exists(BaDemoSetupRoleGuard::class), 'Setup role guard missing.');
        $this->expectException(\LogicException::class);
        app(BaDemoSetupRoleGuard::class)->assertSetupConnection(DB::connection('pgsql'));
    }
}
