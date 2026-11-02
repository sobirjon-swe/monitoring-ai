<?php

namespace Tests\Feature;

use App\Domain\PeopleRoles\PeopleRolesService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\BrowserTestCase;

final class PeopleRolesConcurrencyTest extends BrowserTestCase
{
    public static function modes(): array
    {
        return [['duplicate'], ['revoke'], ['archive']];
    }

    #[DataProvider('modes')]
    public function test_real_waiting_assignments_recheck_locked_authority_and_catalog(string $mode): void
    {
        $f = $this->browserInputs(['project.read', 'employees.read', 'roles.manage']);
        $this->grant('role_catalog.manage');
        $role = app(PeopleRolesService::class)->create($f['actor'], ['name' => 'Concurrent synthetic role', 'request_id' => (string) Str::uuid()])['item'];
        // Retained immutable synthetic fixture follows the existing QA concurrency convention.
        DB::commit();
        DB::beginTransaction();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('tenants')->where('id', $this->tenant)->lockForUpdate()->first());
        $children = [];
        $pids = [];
        try {
            foreach (range(1, 2) as $number) {
                $input = new InputStream;
                $pins = ['employee_id' => $this->employee, 'role_catalog_id' => $role['id'], 'expected_role_revision' => 1, 'request_id' => (string) Str::uuid()];
                $child = new Process([PHP_BINARY, base_path('tests/Support/people-roles-child.php'), $this->tenant, $this->user, $f['project'], json_encode($pins, JSON_THROW_ON_ERROR)], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'monitoring_ai_test', 'DB_URL' => '', 'DB_PORT' => (string) config('database.connections.pgsql.port'), 'QA_LOCAL_PORT' => (string) config('database.connections.pgsql.port')], null, 20);
                $child->setInput($input);
                $child->start();
                $children[] = [$child, $input];
            }
            foreach ($children as [$child,$input]) {
                $deadline = microtime(true) + 8;
                while (! str_contains($child->getOutput(), 'ready') && $child->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready', $child->getOutput(), $child->getErrorOutput());
                $this->assertSame(1, preg_match('/ready pid=([0-9]+)/', $child->getOutput(), $pid));
                $pids[] = (int) $pid[1];
                $input->write("go\n");
                $input->close();
            }
            $this->assertCount(2, array_unique($pids));
            $deadline = microtime(true) + 5;
            do {
                foreach ($children as [$child]) {
                    $child->isRunning();
                }
                DB::select('select pg_stat_clear_snapshot()');
                $blocked = (int) DB::scalar("select count(*) from pg_stat_activity where datname=current_database() and usename=current_user and pid in (?,?) and wait_event_type='Lock'", $pids);
                if ($blocked === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both distinct runtime backends must overlap at the real authorization lock.');
            app(TenantContext::class)->run($this->tenant, function () use ($mode, $role, $f) {
                if ($mode === 'revoke') {
                    DB::table('access_grants')->where('purpose', 'roles.manage')->where('project_id', $f['project'])->update(['revoked_at' => DB::raw('clock_timestamp()')]);
                }
                if ($mode === 'archive') {
                    DB::table('role_catalog')->where('id', $role['id'])->update(['status' => 'archived', 'revision' => 2]);
                }
            });
            DB::commit();
            $outcomes = [];
            foreach ($children as [$child]) {
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                $this->assertSame(1, preg_match('/status=([0-9]+)/', $child->getOutput(), $match));
                $outcomes[] = (int) $match[1];
            }
            sort($outcomes);
            $this->assertSame(match ($mode) {
                'duplicate' => [201, 409],'revoke' => [403, 403],'archive' => [409, 409]
            }, $outcomes);
            app(TenantContext::class)->run($this->tenant, function () use ($mode, $f) {
                $this->assertSame($mode === 'duplicate' ? 1 : 0, DB::table('project_catalog_role_assignments')->where('project_id', $f['project'])->count());
                $this->assertSame($mode === 'duplicate' ? 1 : 0, DB::table('people_role_requests')->where('project_id', $f['project'])->count());
            });
            file_put_contents(base_path('../.local/people-roles-concurrency.jsonl'), json_encode(['tenant_id' => $this->tenant, 'project_id' => $f['project'], 'mode' => $mode, 'pg_pids' => $pids, 'outcomes' => $outcomes], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        } finally {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($children as [$child,$input]) {
                $input->close();
                if ($child->isRunning()) {
                    $child->stop(0);
                }
            }
            DB::beginTransaction();
        }
    }
}
