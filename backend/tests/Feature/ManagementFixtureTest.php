<?php

namespace Tests\Feature;

use App\Console\Commands\RegisterLocalManagementFixture;
use App\Domain\Management\ManagementContract;
use App\Domain\Management\ManagementFixtureService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\ManagementTestCase;

class ManagementFixtureTest extends ManagementTestCase
{
    public function test_local_path_admission_rejects_remote_and_windows_network_names_without_io(): void
    {
        $command = app(RegisterLocalManagementFixture::class);
        $method = new \ReflectionMethod($command, 'plainLocalPath');
        foreach (['ftp://host/file', 'http://host/file', 'https://host/file', 'file:///C:/file', 'php://memory', 'data:text/plain,content', 'phar://archive/file', 'custom://file', 'custom:resource', '\\\\host\\share\\file', '//host/share/file', '\\/host/share/file', '/\\host/share/file', '\\\\?\\UNC\\host\\share\\file', '\\\\?\\C:\\file', '\\\\.\\device', '\\??\\UNC\\host\\share\\file', 'C:\\file:stream', 'NUL', 'con.txt', 'C:\\temp\\COM1', "local\0file", "local\nfile", '', null, []] as $path) {
            $this->assertFalse($method->invoke($command, $path));
        }
        foreach (['COM¹', 'COM².txt', 'COM³', 'LPT¹', 'LPT².txt', 'LPT³', 'CONIN$', 'CONOUT$', 'C:\\local\\CONIN$.txt', 'NUL.', 'CON ', 'AUX... ', 'C:\\local\\COM1 .txt', 'folder\\LPT²... \\file', 'C:\\local\\CONOUT$ .log'] as $path) {
            $this->assertFalse($method->invoke($command, $path));
        }
        foreach (['fixture.json', './fixture with spaces.json', '../fixtures/token file.txt', 'C:\\local folder\\fixture.json', 'C:/local folder/token.txt', 'D:relative file.txt', '\\local folder\\file', '/local/file', 'company.txt', 'COM10.txt', 'LPT10', 'console.txt'] as $path) {
            $this->assertTrue($method->invoke($command, $path));
        }
    }

    public function test_fixture_permission_expiring_after_receipt_capture_rolls_back_every_fact(): void
    {
        $this->managementInputs();
        $grant = $this->grant('management.fixture.manage', $this->p);
        $envelope = $this->managementEnvelope('action.open', $this->actionInput(), 'expiry');
        $tables = ['management_project_state', 'management_actions', 'management_action_versions', 'management_receipts', 'management_events', 'management_request_bindings', 'management_source_heads', 'management_reference_sets', 'management_references', 'audit_events'];
        $snapshot = fn () => app(TenantContext::class)->run($this->tenant, fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->all(), $tables));
        $before = $snapshot();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['valid_to' => DB::raw("clock_timestamp()+interval '2 seconds'")]));
        $delayed = false;
        DB::listen(function ($query) use (&$delayed): void {
            if (! $delayed && str_starts_with($query->sql, 'insert into "management_receipts"')) {
                $delayed = true;
                DB::select('select pg_sleep(2.1)');
            }
        });
        try {
            app(ManagementFixtureService::class)->register($this->actor(), $this->p, $envelope, 'expiry', 0);
            $this->fail('Expired fixture permission committed a management action.');
        } catch (AccessDeniedHttpException) {
            $this->assertTrue($delayed);
        }
        $this->assertEquals($before, $snapshot());
        app(TenantContext::class)->run($this->tenant, function (): void {
            foreach (['management_actions', 'management_receipts', 'management_events', 'management_request_bindings'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
        });
    }

    public function test_cli_requires_fixture_purpose_and_uses_only_bounded_local_files(): void
    {
        $this->managementInputs();
        $token = tempnam(sys_get_temp_dir(), 'management token-');
        $fixture = tempnam(sys_get_temp_dir(), 'management fixture-');
        try {
            file_put_contents($token, $this->token);
            $raw = ManagementContract::canonical($this->managementEnvelope('action.open', $this->actionInput()));
            file_put_contents($fixture, $raw);
            $args = ['project' => $this->p, 'fixture' => $fixture, '--token-file' => $token, '--expected-source-revision' => '0', '--idempotency-key' => 'fixture'];
            $this->assertSame(1, Artisan::call('app:register-local-management-fixture', $args));
            $this->grant('management.fixture.manage', $this->p);
            $this->assertSame(0, Artisan::call('app:register-local-management-fixture', $args));
            $this->assertSame(0, Artisan::call('app:register-local-management-fixture', $args));
            $this->assertStringContainsString('replayed=1', Artisan::output());
            $this->assertStringNotContainsString($this->token, Artisan::output());
            $relative = array_replace($args, ['--token-file' => basename($token), 'fixture' => basename($fixture)]);
            $cwd = getcwd();
            try {
                $this->assertTrue(chdir(dirname($token)));
                $this->assertSame(0, Artisan::call('app:register-local-management-fixture', $relative));
            } finally {
                chdir($cwd);
            }
            file_put_contents($token, $this->token.str_repeat(' ', 513 - strlen($this->token)));
            $this->assertSame(1, Artisan::call('app:register-local-management-fixture', $args));
            file_put_contents($token, $this->token);
            file_put_contents($fixture, $raw.str_repeat(' ', 65537 - strlen($raw)));
            $this->assertSame(1, Artisan::call('app:register-local-management-fixture', $args));
            file_put_contents($fixture, $raw);
            foreach (['--expected-source-revision' => '01', '--idempotency-key' => 'bad/key', 'project' => 'invalid'] as $option => $invalid) {
                $this->assertSame(1, Artisan::call('app:register-local-management-fixture', array_replace($args, [$option => $invalid])));
            }
            $grants = app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->count());
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'management.fixture.manage')->update(['revoked_at' => now()]));
            $this->assertSame(1, Artisan::call('app:register-local-management-fixture', $args));
            $this->assertSame($grants, app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->count()));
        } finally {
            unlink($token);
            unlink($fixture);
        }
        $this->assertFileDoesNotExist($token);
        $this->assertFileDoesNotExist($fixture);
    }
}
