<?php

namespace Tests\Feature;

use App\Domain\Browser\BaDemo\BaDemoFixtureService;
use App\Domain\Browser\BaDemo\BaDemoManifest;
use App\Domain\Browser\BaDemo\BaDemoSnapshot;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccessTestCase;

final class BaDemoSnapshotTest extends AccessTestCase
{
    public function test_inventory_rejects_policy_drift(): void
    {
        $row = DB::selectOne("select c.relrowsecurity as enabled, c.relforcerowsecurity as forced, p.polname as name, p.polpermissive as permissive, p.polcmd as command, p.polroles = array[0::oid] as public_role, pg_get_expr(p.polqual,p.polrelid) as using_expr, pg_get_expr(p.polwithcheck,p.polrelid) as check_expr from pg_class c join pg_namespace n on n.oid=c.relnamespace join pg_policy p on p.polrelid=c.oid where n.nspname='public' and c.relname='projects'");
        app(BaDemoSnapshot::class)->assertTenantPolicy([$row], false);
        $row->using_expr = 'false';
        $this->expectExceptionMessage('Synthetic snapshot policy refused.');
        app(BaDemoSnapshot::class)->assertTenantPolicy([$row], false);
    }

    public function test_inventory_rejects_extra_policy(): void
    {
        $row = DB::selectOne("select c.relrowsecurity as enabled, c.relforcerowsecurity as forced, p.polname as name, p.polpermissive as permissive, p.polcmd as command, p.polroles = array[0::oid] as public_role, pg_get_expr(p.polqual,p.polrelid) as using_expr, pg_get_expr(p.polwithcheck,p.polrelid) as check_expr from pg_class c join pg_namespace n on n.oid=c.relnamespace join pg_policy p on p.polrelid=c.oid where n.nspname='public' and c.relname='projects'");
        $this->expectExceptionMessage('Synthetic snapshot policy refused.');
        app(BaDemoSnapshot::class)->assertTenantPolicy([$row, clone $row], false);
    }

    public function test_test_runtime_cannot_create_temporary_tables(): void
    {
        $this->assertFalse(DB::scalar("select has_database_privilege(current_user, current_database(), 'TEMP')"));
    }

    public function test_inventory_rejects_missing_catalog_relation(): void
    {
        $rows = DB::select("select c.relname as name, c.relkind as kind, has_table_privilege(c.oid, 'SELECT') as readable from pg_class c join pg_namespace n on n.oid=c.relnamespace join pg_attribute a on a.attrelid=c.oid where n.nspname='public' and c.relkind in ('r','p') and a.attname='tenant_id' and a.attnum>0 and not a.attisdropped order by c.relname");
        app(BaDemoSnapshot::class)->assertInventory($rows);
        array_pop($rows);
        $this->expectExceptionMessage('Synthetic snapshot inventory refused.');
        app(BaDemoSnapshot::class)->assertInventory($rows);
    }

    public function test_inventory_rejects_unreadable_or_extra_relation(): void
    {
        $rows = DB::select("select c.relname as name, c.relkind as kind, has_table_privilege(c.oid, 'SELECT') as readable from pg_class c join pg_namespace n on n.oid=c.relnamespace join pg_attribute a on a.attrelid=c.oid where n.nspname='public' and c.relkind in ('r','p') and a.attname='tenant_id' and a.attnum>0 and not a.attisdropped order by c.relname");
        $rows[0]->readable = false;
        try {
            app(BaDemoSnapshot::class)->assertInventory($rows);
            $this->fail('Unreadable relation was accepted');
        } catch (\LogicException $e) {
            $this->assertSame('Synthetic snapshot inventory refused.', $e->getMessage());
        }
        $rows[0]->readable = true;
        $rows[] = (object) ['name' => 'unexpected_table', 'kind' => 'r', 'readable' => true];
        $this->expectExceptionMessage('Synthetic snapshot inventory refused.');
        app(BaDemoSnapshot::class)->assertInventory($rows);
    }

    public function test_candidate_is_stable_and_changes_when_fixture_proof_changes(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            $snapshot = app(BaDemoSnapshot::class);
            $before = $snapshot->candidate($manifest, str_repeat('a', 64));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $before);
            $this->assertSame($before, $snapshot->candidate($manifest, str_repeat('a', 64)));
            DB::table('source_instances')->where('id', $manifest->data['source_id'])->update(['label' => 'changed']);
            $this->assertNotSame($before, $snapshot->candidate($manifest, str_repeat('a', 64)));
        });
    }

    public function test_expired_token_is_refused(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            DB::table('auth_tokens')->where('id', $manifest->data['writer']['token_id'])->update(['expires_at' => now()->subSecond()]);
            $this->expectException(\LogicException::class);
            app(BaDemoSnapshot::class)->candidate($manifest, str_repeat('a', 64));
        });
    }

    public function test_inactive_policy_is_refused(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            DB::table('policy_versions')->where('id', $manifest->data['policy_id'])->update(['status' => 'retired']);
            $this->expectException(\LogicException::class);
            app(BaDemoSnapshot::class)->candidate($manifest, str_repeat('a', 64));
        });
    }

    public function test_expired_role_is_refused(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            DB::table('role_assignments')->where('employee_id', $manifest->data['writer']['employee_id'])->update(['valid_to' => now()->subSecond()]);
            $this->expectException(\LogicException::class);
            app(BaDemoSnapshot::class)->candidate($manifest, str_repeat('a', 64));
        });
    }

    public function test_extra_tenant_grant_is_refused(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            $m = $manifest->data;
            DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $m['tenant_id'], 'user_id' => $m['writer']['user_id'], 'policy_version_id' => $m['policy_id'], 'purpose' => 'project.read', 'scope_type' => 'tenant', 'valid_from' => now()->subMinute()]);
            $this->expectException(\LogicException::class);
            app(BaDemoSnapshot::class)->candidate($manifest, str_repeat('a', 64));
        });
    }

    public function test_extra_role_is_refused(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            $m = $manifest->data;
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $m['tenant_id'], 'project_id' => $m['project_id'], 'employee_id' => $m['writer']['employee_id'], 'role' => 'pm', 'valid_from' => now()->subMinute()]);
            $this->expectException(\LogicException::class);
            app(BaDemoSnapshot::class)->candidate($manifest, str_repeat('a', 64));
        });
    }

    public function test_receipt_secret_hash_must_match_token(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            $this->expectException(\LogicException::class);
            app(BaDemoSnapshot::class)->candidate($manifest, str_repeat('b', 64));
        });
    }

    public function test_changed_source_kind_is_refused(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            DB::table('source_instances')->where('id', $manifest->data['source_id'])->update(['kind' => 'gitlab']);
            $this->expectException(\LogicException::class);
            app(BaDemoSnapshot::class)->candidate($manifest, str_repeat('a', 64));
        });
    }

    public function test_producer_subject_is_refused(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            $m = $manifest->data;
            DB::table('auth_subjects')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $m['tenant_id'], 'user_id' => $m['producer']['user_id'], 'issuer' => 'local-development', 'subject' => 'unexpected']);
            $this->expectException(\LogicException::class);
            app(BaDemoSnapshot::class)->candidate($manifest, str_repeat('a', 64));
        });
    }

    public function test_disabled_source_connector_is_refused(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            DB::table('ingestion_connectors')->where('source_instance_id', $manifest->data['source_id'])->update(['status' => 'disabled', 'revision' => 2]);
            $this->expectException(\LogicException::class);
            app(BaDemoSnapshot::class)->candidate($manifest, str_repeat('a', 64));
        });
    }
}
