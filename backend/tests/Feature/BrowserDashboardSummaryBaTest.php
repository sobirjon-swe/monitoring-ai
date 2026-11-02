<?php

namespace Tests\Feature;

use App\Domain\Browser\DashboardSummaryReader;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\BaTestCase;

class BrowserDashboardSummaryBaTest extends BaTestCase
{
    public function test_native_ba_dependency_and_source_permission_revocation(): void
    {
        $this->baInputs();
        $this->baRole();
        foreach (['project.read', 'progress.read'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
        $this->openRequest();
        $reader = app(DashboardSummaryReader::class);
        $result = $reader->read($this->actor(), $this->p)['dependencies']['ba'];
        $this->assertSame('complete', $result['coverage_status']);
        $this->assertSame(['open' => 1, 'total' => 1], $result['counts']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'ba.source.read')->where('project_id', $this->p)->update(['revoked_at' => now()]));
        $result = $reader->read($this->actor(), $this->p)['dependencies']['ba'];
        $this->assertSame('unavailable', $result['coverage_status']);
        $this->assertNull($result['counts']);
    }
}
