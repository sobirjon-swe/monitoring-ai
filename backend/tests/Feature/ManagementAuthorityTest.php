<?php

namespace Tests\Feature;

use App\Domain\Access\AccessGateway;
use App\Domain\Ba\BaAuthority;
use App\Domain\Management\ManagementAuthority;
use App\Domain\Management\ManagementCoordination;
use App\Domain\Management\ManagementReviewService;
use App\Domain\Management\ManagementSupportService;
use App\Domain\Qa\QaAuthority;
use App\Support\LocalManagementDatabase;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ManagementTestCase;

class ManagementAuthorityTest extends ManagementTestCase
{
    public function test_original_department_scope_survives_distinct_current_caller_scope(): void
    {
        $f = $this->workflowAssigned();
        $support = app(ManagementSupportService::class)->record($f['helper'], $this->p, $f['open']['action_id'], $this->managementEnvelope('support.record', $this->workflowSupportBody($f), 'scope-support'), 'scope-support', 0);
        $this->assertSame(3, $support['revision']);
        $this->grant('management.outcome.review', $this->p);
        $review = app(ManagementReviewService::class)->review($this->actor(), $this->p, $f['open']['action_id'], $this->workflowReviewBody($support), 'scope-review');
        $this->assertSame('completed', $review['current_outcome']);
    }

    public function test_same_employee_originals_keep_two_named_departments_and_mixed_source_scopes(): void
    {
        $this->managementInputs();
        $departments = [(string) Str::uuid(), (string) Str::uuid()];
        app(TenantContext::class)->run($this->tenant, function () use ($departments) {
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => 'department_head', 'valid_from' => now()->subDay()]);
            foreach ($departments as $department) {
                DB::table('departments')->insert(['id' => $department, 'tenant_id' => $this->tenant, 'name' => 'Synthetic independent scope']);
                DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'employee_id' => $this->employee, 'valid_from' => now()->subDay()]);
                foreach (['management.department.read', 'qa.department.read', 'ba.department.read'] as $purpose) {
                    DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'user_id' => $this->user, 'department_id' => $department, 'policy_version_id' => $this->policy, 'purpose' => $purpose, 'scope_type' => 'department', 'valid_from' => now()->subDay()]);
                }
            }
        });
        $slots = [];
        foreach ($departments as $department) {
            $proof = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read', 'management.department.read'], false, ['coverage' => 'complete', 'current_requests' => [['key' => 'current:named-origin', 'actor_id' => $this->user, 'purposes' => ['management.read'], 'required' => true, 'owner_refs' => [$this->employee]]]], fn ($proofs) => $proofs['current:named-origin']['proof'], $department);
            $this->assertNull($proof['department_id']);
            $this->assertSame($department, $proof['named'][0]['department_membership']['department_id']);
            $slots[] = $this->managementProofSlot($proof, 'management_receipts', (string) Str::uuid(), true);
            foreach (['qa', 'ba'] as $family) {
                $source = app(TenantContext::class)->run($this->tenant, fn () => app($family === 'qa' ? QaAuthority::class : BaAuthority::class)->captureBatch($this->actor(), $this->p, [['key' => 'capture', 'actor_id' => $this->user, 'purposes' => [$family.'.department.read'], 'required' => true]], $department)['capture']['proof']);
                DB::select("select set_config('app.qa_responsibility','',true)");
                $slots[] = $this->managementProofSlot($source, $family.'_receipts', (string) Str::uuid(), true);
            }
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, ['coverage' => 'complete', 'proof_slots' => $slots], function ($proofs) use ($slots) {
            foreach ($slots as $slot) {
                $this->assertTrue($proofs[$slot['key']]['valid']);
            }
        });
        $count = count(DB::getQueryLog());
        $this->assertLessThan(200, $count);
        file_put_contents(base_path('../.local/management-t3-mixed-scope-query-counts.json'), json_encode(['management_originals' => 2, 'source_originals' => 4, 'source_format_scope_groups' => 4, 'queries' => $count], JSON_THROW_ON_ERROR));
        DB::disableQueryLog();
    }

    public function test_role_and_exact_project_grant_are_required(): void
    {
        $this->managementInputs();
        $this->managementRun(fn ($proofs) => $this->assertTrue($proofs['current']['valid']));
        $this->grant('management.support.assign');
        $this->expectException(AccessDeniedHttpException::class);
        app(AccessGateway::class)->authorize($this->actor(), 'management.support.assign', ['tenant_id' => $this->tenant, 'project_id' => $this->p]);
    }

    public function test_same_actor_distinct_originals_and_delivery_expiry(): void
    {
        $this->managementInputs();
        $original = $this->managementRun(fn ($proofs) => $proofs['current']['proof']);
        app(TenantContext::class)->run($this->tenant, function () use ($original): void {
            $grant = collect($original['grants'])->firstWhere('purpose', 'management.read');
            DB::table('access_grants')->where('id', $grant['id'])->update(['valid_to' => DB::raw("clock_timestamp()+interval '3 seconds'")]);
        });
        $fresh = $this->managementRun(fn ($proofs) => $proofs['current']['proof']);
        $requests = [];
        $keys = [];
        $subject = (string) Str::uuid();
        foreach (['old' => $original, 'fresh' => $fresh] as $key => $pin) {
            $slot = $this->managementProofSlot($pin, 'management_receipts', $subject);
            $requests[] = $slot;
            $keys[$key] = $slot['key'];
        }
        app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, ['coverage' => 'complete', 'proof_slots' => $requests], function ($proofs) use ($keys): void {
            $this->assertFalse($proofs[$keys['old']]['valid']);
            $this->assertTrue($proofs[$keys['fresh']]['valid']);
            app(ManagementAuthority::class)->requireDeliveryLocked([$keys['fresh']]);
            $this->expectException(AccessDeniedHttpException::class);
            app(ManagementAuthority::class)->assertEffective($proofs, DB::scalar("select clock_timestamp()+interval '10 seconds'"));
        });
    }

    public function test_success_and_callback_failure_restore_transaction_local_settings(): void
    {
        $this->managementInputs();
        $this->managementRun(fn () => true);
        try {
            $this->managementRun(fn () => throw new RuntimeException('Synthetic callback'));
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        foreach (['app.tenant_id', 'app.management_authority_closed', 'app.management_proofs', 'app.management_required_keys', 'app.qa_responsibility'] as $setting) {
            $this->assertNull(DB::scalar('select nullif(current_setting(?,true),\'\')', [$setting]));
        }
    }

    public function test_mixed_original_formats_keep_independent_keys_and_restore_qa_settings(): void
    {
        $this->managementInputs();
        foreach (['qa.read', 'ba.read'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
        [$qa, $ba] = app(TenantContext::class)->run($this->tenant, function () {
            $qa = app(QaAuthority::class)->captureBatch($this->actor(), $this->p, [['key' => 'qa-capture', 'actor_id' => $this->user, 'purposes' => ['qa.read'], 'required' => true]])['qa-capture']['proof'];
            $ba = app(BaAuthority::class)->captureBatch($this->actor(), $this->p, [['key' => 'ba-capture', 'actor_id' => $this->user, 'purposes' => ['ba.read'], 'required' => true]])['ba-capture']['proof'];
            DB::select("select set_config('app.qa_responsibility','',true)");

            return [$qa, $ba];
        });
        $mgmt = $this->managementRun(fn ($r) => $r['current']['proof']);
        $slots = [];
        $keys = [];
        foreach (['management-original' => $mgmt, 'qa-original' => $qa, 'ba-original' => $ba] as $key => $pin) {
            $kind = match ($key) {
                'qa-original' => 'qa_receipts', 'ba-original' => 'ba_receipts', default => 'management_receipts'
            };
            $slot = $this->managementProofSlot($pin, $kind, (string) Str::uuid());
            $slots[] = $slot;
            $keys[$key] = $slot['key'];
        }
        DB::select("select set_config('app.qa_responsibility','prior-sentinel',true)");
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run = fn ($callback) => app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, ['coverage' => 'complete', 'proof_slots' => $slots], $callback);
        $run(function ($proofs) use ($keys): void {
            foreach (['management-original' => 'management-authority-v1', 'qa-original' => 'qa-authority-v1', 'ba-original' => 'ba-authority-v1'] as $key => $format) {
                $this->assertTrue($proofs[$keys[$key]]['valid']);
                $this->assertSame($format, $proofs[$keys[$key]]['proof']['version']);
            }
            $this->assertArrayNotHasKey('roles', $proofs[$keys['qa-original']]['proof']);
            app(ManagementAuthority::class)->requireDeliveryLocked(array_values($keys));
            $this->assertSame('prior-sentinel', DB::scalar("select current_setting('app.qa_responsibility')"));
        });
        try {
            $run(fn () => throw new RuntimeException('Synthetic mixed failure'));
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('prior-sentinel', DB::scalar("select current_setting('app.qa_responsibility')"));
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert\s+into|update|delete\s+from|truncate)\s+["\s]*(?:qa_|ba_)/i', $query['query']);
        }
        DB::disableQueryLog();
        DB::select("select set_config('app.qa_responsibility','',true)");
    }

    public function test_department_grant_never_substitutes_for_project_grant(): void
    {
        $this->managementInputs();
        $department = $this->seedManagementBoundary()['department'];
        $this->grant('management.read', null, $department);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $this->user)->where('purpose', 'management.read')->where('scope_type', 'project')->update(['revoked_at' => now()]));
        $this->expectException(AccessDeniedHttpException::class);
        $this->managementRun(fn () => true);
    }

    public function test_mixed_formats_overlap_on_shared_rows_in_two_processes(): void
    {
        $this->managementInputs();
        foreach (['qa.read', 'ba.read'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
        $proofs = app(TenantContext::class)->run($this->tenant, function () {
            $request = fn ($purpose) => [['key' => 'capture', 'actor_id' => $this->user, 'purposes' => [$purpose], 'required' => true]];
            $qa = app(QaAuthority::class)->captureBatch($this->actor(), $this->p, $request('qa.read'))['capture']['proof'];
            $ba = app(BaAuthority::class)->captureBatch($this->actor(), $this->p, $request('ba.read'))['capture']['proof'];
            DB::select("select set_config('app.qa_responsibility','',true)");

            return [$qa, $ba];
        });
        $proofs[] = $this->managementRun(fn ($p) => $p['current']['proof']);
        $slots = [];
        foreach ($proofs as $proof) {
            $kind = match ($proof['version']) {
                'qa-authority-v1' => 'qa_receipts', 'ba-authority-v1' => 'ba_receipts', default => 'management_receipts'
            };
            $slots[] = $this->managementProofSlot($proof, $kind, (string) Str::uuid(), true);
        }
        app(LocalManagementDatabase::class)->assertRuntime();
        $migration = $this->ownerConnection();
        $identity = $migration->selectOne("select current_database() as db,session_user as login,current_user as role,inet_server_port() as port,r.rolsuper,r.rolbypassrls,shobj_description(d.oid,'pg_database') as marker from pg_database d join pg_roles r on r.rolname=current_user where d.datname=current_database()");
        $this->assertSame('monitoring_ai_test', $identity->db);
        $this->assertSame('monitoring_ai_migrator', $identity->login);
        $this->assertSame('monitoring_ai_owner', $identity->role);
        $this->assertSame((int) config('qa.local_port'), (int) $identity->port);
        $this->assertFalse($identity->rolsuper || $identity->rolbypassrls);
        $this->assertStringStartsWith('monitoring-ai-local-v1:', $identity->marker);
        $this->assertTrue($migration->table('migrations')->where('migration', '2026_10_09_000038_enforce_management_atomic_lineage')->exists());
        DB::purge('pgsql_migration');
        $fixtureIds = app(TenantContext::class)->run($this->tenant, function () {
            $ids = [];
            foreach (['users', 'employees', 'policy_versions', 'access_grants', 'project_memberships', 'role_assignments', 'source_instances', 'ingestion_connectors', 'requirement_versions', 'criterion_versions', 'task_versions'] as $table) {
                $ids[$table] = DB::table($table)->orderBy('id')->pluck('id')->all();
            }

            return $ids;
        });
        DB::commit();
        DB::beginTransaction();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('projects')->where('id', $this->p)->lockForUpdate()->first());
        $children = $pids = $outcomes = [];
        try {
            foreach ([$slots, array_reverse($slots)] as $order) {
                $stream = new InputStream;
                $child = new Process([PHP_BINARY, base_path('tests/Support/management-authority-child.php')], base_path(), null, null, 20);
                $child->setInput($stream);
                $child->start();
                $children[] = [$child, $stream];
                $stream->write(json_encode(['tenant_id' => $this->tenant, 'project_id' => $this->p, 'actor_id' => $this->user, 'graph' => ['coverage' => 'complete', 'proof_slots' => $order]], JSON_THROW_ON_ERROR)."\n");
            }
            foreach ($children as [$child, $stream]) {
                $deadline = microtime(true) + 8;
                while (! str_contains($child->getOutput(), 'ready') && $child->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready pid=', $child->getOutput(), $child->getErrorOutput());
                preg_match('/ready pid=([0-9]+)/', $child->getOutput(), $match);
                $pids[] = (int) $match[1];
                $stream->write("go\n");
                $stream->close();
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
            $this->assertSame(2, $blocked, 'Both children must overlap on the exact shared project rows.');
            DB::commit();
            foreach ($children as [$child]) {
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                $this->assertStringContainsString('status=ok mixed=3', $child->getOutput());
                $outcomes[] = 'ok';
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($children as [$child, $stream]) {
                $stream->close();
                if ($child->isRunning()) {
                    $child->stop(0);
                }
            }
            file_put_contents(base_path('../.superpowers/sdd/2026-10-09-e05-management-support-plan/retained-fixtures.jsonl'), json_encode(['tenant_id' => $this->tenant, 'project_id' => $this->p, 'mode' => 'mixed-authority-shared-rows', 'fixture_ids' => $fixtureIds, 'pg_pids' => $pids, 'outcomes' => $outcomes, 'children_rollback_only' => true], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
            DB::beginTransaction();
        }
        $this->managementRun(function (): void {
            foreach (['management_receipts', 'management_events', 'qa_receipts', 'ba_receipts'] as $table) {
                $this->assertSame(0, DB::table($table)->count());
            }
        });
    }

    public function test_graph_rejects_forged_hash_key_and_format_before_callback(): void
    {
        $this->managementInputs();
        $proof = $this->managementRun(fn ($p) => $p['current']['proof']);
        $slot = $this->managementProofSlot($proof, 'management_receipts', (string) Str::uuid());
        foreach ([array_replace($slot, ['proof_hash' => str_repeat('0', 64)]), array_replace($slot, ['key' => 'original:'.str_repeat('0', 64)]), array_replace($slot, ['format' => 'qa-authority-v1']), $slot + ['unknown' => true]] as $bad) {
            $called = false;
            try {
                app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, ['coverage' => 'complete', 'proof_slots' => [$bad]], function () use (&$called): void {
                    $called = true;
                });
                $this->fail('Invalid original graph slot admitted.');
            } catch (\LogicException) {
                $this->assertFalse($called);
            }
        }
    }
}
