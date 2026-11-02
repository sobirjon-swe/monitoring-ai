<?php

namespace Tests\Feature;

use App\Domain\Access\TrustedActor;
use App\Domain\Browser\UzbekDemo\UzbekDemoManifest;
use App\Domain\Browser\UzbekDemo\UzbekDemoWorkspaceService;
use App\Domain\Identity\LocalTokenAuthenticator;
use App\Domain\Lifecycle\ProgressCalculator;
use App\Domain\PeopleRoles\PeopleRolesService;
use App\Domain\Qa\QaReadService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresTestCase;

class UzbekDemoSetupTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_native_populated_scenario_replay_authority_and_unknown_baseline(): void
    {
        $m = UzbekDemoManifest::fromFile(base_path('tests/Fixtures/uzbek-demo-v1.json'));
        $path = $this->protectedDirectory().'\\workspace.json';
        $service = app(UzbekDemoWorkspaceService::class);
        $result = $service->prepare($m, $path);
        $this->assertSame('published', $result['status']);
        $journal = json_decode(file_get_contents($path), true);
        $pm = new TrustedActor($m->data['interactive'][0]['user_id'], $m->data['tenant_id']);
        foreach ($journal['credentials'] as $c) {
            $this->assertStringNotContainsString($c['token'], json_encode($result));
        }
        app(TenantContext::class)->run($m->data['tenant_id'], function () use ($m, $journal): void {
            $this->assertSame(12, DB::table('employees')->count());
            $this->assertSame(array_column($m->data['people'], 'name'), DB::table('employees')->orderBy('id')->pluck('display_name')->all());
            $this->assertSame(5, DB::table('users')->count());
            $this->assertSame(2, DB::table('auth_tokens')->count());
            $this->assertSame(3, DB::table('projects')->count());
            $this->assertSame(36, DB::table('project_catalog_role_assignments')->count());
            $this->assertSame(12, DB::table('lifecycle_tasks')->count());
            $this->assertSame(2, DB::table('human_acceptance_decisions')->count());
            $this->assertSame(3, DB::table('qa_handoffs')->count());
            $this->assertSame(3, DB::table('ba_clarifications')->count());
            $this->assertSame(3, DB::table('management_support_versions')->count());
            $interactive = DB::table('access_grants')->whereIn('user_id', array_column($m->data['interactive'], 'user_id'))->get();
            $this->assertTrue($interactive->every(fn ($g) => $g->valid_to !== null && CarbonImmutable::parse($g->valid_to)->equalTo(CarbonImmutable::parse($journal['expires_at']))));
            $this->assertSame(0, $interactive->where('purpose', 'ba.request.manage')->count());
            $this->assertSame(0, $interactive->where('user_id', $m->data['interactive'][0]['user_id'])->where('purpose', 'role_catalog.manage')->count());
            $this->assertSame(9, DB::table('access_grants')->where('user_id', UzbekDemoWorkspaceService::SOURCE)->whereNotNull('revoked_at')->count());
        });
        foreach (array_slice($m->data['projects'], 0, 2) as $p) {
            $progress = app(ProgressCalculator::class)->calculate($pm, $p['id']);
            $this->assertSame(40, $progress['accepted_weight']);
            $this->assertSame(100, $progress['total_weight']);
            $qa = app(QaReadService::class)->verification($pm, $p['id']);
            $this->assertSame(2, $qa['counts']['numerator']);
            $this->assertSame(3, $qa['counts']['denominator']);
            $this->assertSame(1, $qa['counts']['all_required_passed_criterion_count']);
        }
        $unknown = app(ProgressCalculator::class)->calculate($pm, $m->data['projects'][2]['id']);
        $this->assertSame('unavailable', $unknown['status']);
        $this->assertNull($unknown['percentage_basis_points']);
        $this->assertSame('replayed', $service->prepare($m, $path)['status']);
        $this->assertSame($journal, json_decode(file_get_contents($path), true));
        app(TenantContext::class)->run($m->data['tenant_id'], function () use ($m, $pm): void {
            try {
                app(PeopleRolesService::class)->create($pm, ['request_id' => 'f1200000-0000-4000-8000-000000009999', 'name' => 'Forbidden', 'description' => null]);
                $this->fail('PM created catalog role');
            } catch (AccessDeniedHttpException) {
                $this->assertTrue(true);
            }
            DB::table('projects')->where('id', $m->data['projects'][0]['id'])->update(['name' => 'DEMO conflicting mutation']);
        });
        $this->expectException(\LogicException::class);
        $service->prepare($m, $path);
    }

    public function test_command_refuses_test_and_production_without_writes(): void
    {
        $this->artisan('app:setup-local-uzbek-demo', ['--manifest' => base_path('tests/Fixtures/uzbek-demo-v1.json'), '--credentials-file' => 'C:\\NUL'])->assertExitCode(1);
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\LogicException::class);
        app(UzbekDemoWorkspaceService::class)->prepare(UzbekDemoManifest::fromFile(base_path('tests/Fixtures/uzbek-demo-v1.json')), 'C:\\NUL');
    }

    public function test_expired_replay_does_not_renew_and_original_review_revocation_removes_acceptance(): void
    {
        $m = UzbekDemoManifest::fromFile(base_path('tests/Fixtures/uzbek-demo-v1.json'));
        $path = $this->protectedDirectory().'\\expiry.json';
        $service = app(UzbekDemoWorkspaceService::class);
        $service->prepare($m, $path);
        $bytes = file_get_contents($path);
        $journal = json_decode($bytes, true);
        foreach ($journal['credentials'] as $credential) {
            $this->assertNotNull(app(LocalTokenAuthenticator::class)->authenticate($credential['token']));
        }
        $pm = new TrustedActor($m->data['interactive'][0]['user_id'], $m->data['tenant_id']);
        $project = $m->data['projects'][0]['id'];
        app(TenantContext::class)->run($m->data['tenant_id'], fn () => DB::table('access_grants')->where('user_id', UzbekDemoWorkspaceService::REVIEWER)->where('purpose', 'acceptance.review')->where('project_id', $project)->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => 2]));
        $progress = app(ProgressCalculator::class)->calculate($pm, $project);
        $this->assertSame(0, $progress['accepted_weight']);
        $this->assertContains('acceptance_author_access_revoked', $progress['coverage_reason_codes']);
        $this->assertSame(0, DB::table('employees')->where('tenant_id', $m->data['tenant_id'])->count(), 'RLS must hide the new tenant without context.');
        $foreign = new TrustedActor($pm->id, UzbekDemoWorkspaceService::OLD_TENANT);
        try {
            app(ProgressCalculator::class)->calculate($foreign, $project);
            $this->fail('Cross tenant actor read progress');
        } catch (AccessDeniedHttpException) {
            $this->assertTrue(true);
        }
        CarbonImmutable::setTestNow(CarbonImmutable::parse($journal['expires_at'])->addSecond());
        try {
            $service->prepare($m, $path);
            $this->fail('Expired replay renewed authority');
        } catch (\LogicException) {
            $this->assertSame($bytes, file_get_contents($path));
        }
        app(TenantContext::class)->run($m->data['tenant_id'], function () use ($journal): void {
            $this->assertTrue(DB::table('auth_tokens')->get()->every(fn ($t) => CarbonImmutable::parse($t->expires_at)->equalTo(CarbonImmutable::parse($journal['expires_at']))));
        });
    }

    public function test_conflicting_tenant_refused_and_original_f01_rows_preserved(): void
    {
        $m = UzbekDemoManifest::fromFile(base_path('tests/Fixtures/uzbek-demo-v1.json'));
        $old = app(TenantContext::class)->run(UzbekDemoWorkspaceService::OLD_TENANT, function (): array {
            DB::table('tenants')->insertOrIgnore(['id' => UzbekDemoWorkspaceService::OLD_TENANT, 'name' => 'Existing fixture sentinel']);

            return (array) DB::table('tenants')->where('id', UzbekDemoWorkspaceService::OLD_TENANT)->first();
        });
        app(TenantContext::class)->run($m->data['tenant_id'], fn () => DB::table('tenants')->insert(['id' => $m->data['tenant_id'], 'name' => 'Conflicting tenant']));
        try {
            app(UzbekDemoWorkspaceService::class)->prepare($m, $this->protectedDirectory().'\\conflict.json');
            $this->fail('Conflicting tenant was adopted');
        } catch (\LogicException) {
            $this->assertSame($old, app(TenantContext::class)->run(UzbekDemoWorkspaceService::OLD_TENANT, fn () => (array) DB::table('tenants')->where('id', UzbekDemoWorkspaceService::OLD_TENANT)->first()));
            $this->assertSame(0, app(TenantContext::class)->run($m->data['tenant_id'], fn () => DB::table('employees')->count()));
        }
    }

    public function test_empty_database_marker_is_refused_before_credential_reservation(): void
    {
        $m = UzbekDemoManifest::fromFile(base_path('tests/Fixtures/uzbek-demo-v1.json'));
        $path = $this->protectedDirectory().'\\marker.json';
        config(['qa.database_marker' => '']);
        try {
            app(UzbekDemoWorkspaceService::class)->prepare($m, $path);
            $this->fail('Empty database marker was accepted');
        } catch (\LogicException) {
            $this->assertFileDoesNotExist($path.'.pending');
        }
    }

    private function protectedDirectory(): string
    {
        $directory = base_path('../.local/demo-output-test-'.bin2hex(random_bytes(8)));
        mkdir($directory);
        $script = <<<'PS'
$ErrorActionPreference='Stop'
$p=$env:DEMO_TEST_DIRECTORY
$s=[System.Security.Principal.WindowsIdentity]::GetCurrent().User
$a=Get-Acl -LiteralPath $p
$a.SetOwner($s)
$a.SetAccessRuleProtection($true,$false)
foreach($r in @($a.Access)){[void]$a.RemoveAccessRuleSpecific($r)}
$a.AddAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new($s,'FullControl','ContainerInherit,ObjectInherit','None','Allow'))
Set-Acl -LiteralPath $p -AclObject $a
PS;
        $process = new Process(['C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script], null, ['DEMO_TEST_DIRECTORY' => $directory, 'PSModulePath' => 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\Modules']);
        $exit = $process->run();
        $this->assertSame(0, $exit, 'TEST directory ACL: '.$process->getErrorOutput());

        return realpath($directory);
    }
}
