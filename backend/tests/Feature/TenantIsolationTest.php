<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTenantContext;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PostgresTestCase;

class TenantIsolationTest extends PostgresTestCase
{
    private string $tenantA;

    private string $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantA = (string) Str::uuid();
        $this->tenantB = (string) Str::uuid();
        foreach ([$this->tenantA, $this->tenantB] as $tenant) {
            $this->context()->run($tenant, fn () => DB::table('tenants')->insert([
                'id' => $tenant, 'name' => 'Synthetic isolation fixture',
            ]));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->tenantA)) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ([$this->tenantA, $this->tenantB] as $tenant) {
                $this->context()->run($tenant, fn () => DB::table('tenants')->where('id', $tenant)->delete());
            }
        }
        parent::tearDown();
    }

    public function test_missing_context_is_denied(): void
    {
        $model = new class extends Model
        {
            protected $table = 'tenants';
        };
        $this->assertSame(0, $model->newQuery()->count());
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame([], DB::select('select id from tenants'));
        $this->assertSame(0, DB::table('tenants')->where('id', $this->tenantA)->update(['name' => 'Unauthorized']));
        $this->assertSqlState('42501', fn () => DB::table('tenants')->insert([
            'id' => (string) Str::uuid(), 'name' => 'Unauthorized',
        ]));
    }

    public function test_cross_tenant_reads_and_writes_are_denied(): void
    {
        $this->context()->run($this->tenantA, function (): void {
            $this->assertSame([$this->tenantA], DB::table('tenants')->pluck('id')->all());
            $this->assertSame([], DB::select('select id from tenants where id = ?', [$this->tenantB]));
            $this->assertSame(0, DB::table('tenants')->where('id', $this->tenantB)->update(['name' => 'Unauthorized']));
            $this->assertSqlState('42501', fn () => $this->context()->run($this->tenantA, fn () => DB::table('tenants')->insert(['id' => (string) Str::uuid(), 'name' => 'Unauthorized'])
            ));
        });
    }

    public function test_cross_tenant_composite_fk_write_is_denied(): void
    {
        $suffix = str_replace('-', '', (string) Str::uuid());
        $parent = 't03_probe_parent_'.$suffix;
        $child = 't03_probe_child_'.$suffix;
        $owner = $this->ownerConnection();
        try {
            $owner->statement("CREATE TABLE public.$parent (tenant_id uuid NOT NULL REFERENCES public.tenants(id), id uuid NOT NULL, UNIQUE (tenant_id, id))");
            $owner->statement("CREATE TABLE public.$child (tenant_id uuid NOT NULL, id uuid PRIMARY KEY, parent_id uuid NOT NULL, FOREIGN KEY (tenant_id, parent_id) REFERENCES public.$parent (tenant_id, id))");
            foreach ([$parent, $child] as $table) {
                $owner->statement("ALTER TABLE public.$table ENABLE ROW LEVEL SECURITY");
                $owner->statement("ALTER TABLE public.$table FORCE ROW LEVEL SECURITY");
                $owner->statement("CREATE POLICY tenant_isolation ON public.$table USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
            }
            $parentId = (string) Str::uuid();
            $this->context()->run($this->tenantB, fn () => DB::table($parent)->insert(['tenant_id' => $this->tenantB, 'id' => $parentId]));
            $this->assertSqlState('23503', fn () => $this->context()->run($this->tenantA, fn () => DB::table($child)->insert(['tenant_id' => $this->tenantA, 'id' => (string) Str::uuid(), 'parent_id' => $parentId])
            ));
            $this->assertSame(0, $this->context()->run($this->tenantA, fn () => DB::table($child)->count()));
        } finally {
            $owner->statement("DROP TABLE IF EXISTS public.$child");
            $owner->statement("DROP TABLE IF EXISTS public.$parent");
            $owner->statement('RESET ROLE');
        }
    }

    public function test_context_resets_after_commit_and_connection_reuse(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $this->assertSame($this->tenantA, $this->context()->run($this->tenantA, fn () => DB::table('tenants')->value('id')));
        $this->assertNoContext();
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame($this->tenantB, $this->context()->run($this->tenantB, fn () => DB::table('tenants')->value('id')));
        $this->assertSame($pdo, $connection->getPdo());
        $this->assertNoContext();
    }

    public function test_context_resets_after_rollback(): void
    {
        try {
            $this->context()->run($this->tenantA, function (): never {
                DB::table('tenants')->where('id', $this->tenantA)->update(['name' => 'Rolled back']);
                throw new RuntimeException('Synthetic rollback');
            });
            $this->fail('Expected rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic rollback', $exception->getMessage());
        }
        $this->assertNoContext();
        $this->assertSame('Synthetic isolation fixture', $this->context()->run($this->tenantA, fn () => DB::table('tenants')->value('name')));
    }

    public function test_same_tenant_nesting_preserves_outer_context_and_rejects_switch(): void
    {
        $this->context()->run($this->tenantA, function (): void {
            $this->assertSame($this->tenantA, $this->context()->run($this->tenantA, fn () => DB::table('tenants')->value('id')));
            try {
                $this->context()->run($this->tenantB, fn () => $this->fail('Switched tenant.'));
                $this->fail('Tenant switch must be rejected.');
            } catch (LogicException) {
                $this->assertSame($this->tenantA, DB::table('tenants')->value('id'));
            }
        });
        $this->assertNoContext();
    }

    public function test_outer_transaction_does_not_retain_context_after_scoped_work(): void
    {
        DB::beginTransaction();
        $this->context()->run($this->tenantA, fn () => $this->assertSame($this->tenantA, DB::table('tenants')->value('id')));
        $this->assertNoContext();
        $this->assertSame(0, DB::table('tenants')->count());
        DB::rollBack();
        $this->assertNoContext();
    }

    public function test_nested_rollback_restores_outer_tenant_and_rolls_back_inner_write(): void
    {
        $this->context()->run($this->tenantA, function (): void {
            try {
                $this->context()->run($this->tenantA, function (): never {
                    DB::table('tenants')->where('id', $this->tenantA)->update(['name' => 'Inner rollback']);
                    throw new RuntimeException('Synthetic nested rollback');
                });
                $this->fail('Expected nested rollback.');
            } catch (RuntimeException) {
                $this->assertSame($this->tenantA, DB::scalar("select current_setting('app.tenant_id', true)"));
                $this->assertSame('Synthetic isolation fixture', DB::table('tenants')->value('name'));
            }
        });
        $this->assertNoContext();
    }

    public function test_rollback_inside_existing_unscoped_transaction_does_not_leak_context(): void
    {
        DB::beginTransaction();
        try {
            $this->context()->run($this->tenantA, function (): never {
                throw new RuntimeException('Synthetic scoped rollback');
            });
            $this->fail('Expected scoped rollback.');
        } catch (RuntimeException) {
            $this->assertSame(1, DB::transactionLevel());
            $this->assertNoContext();
            $this->assertSame(0, DB::table('tenants')->count());
        }
        DB::commit();
        $this->assertNoContext();
    }

    public function test_session_scope_outside_a_transaction_is_rejected(): void
    {
        DB::select("select set_config('app.tenant_id', ?, false)", [$this->tenantA]);
        try {
            $this->context()->run($this->tenantA, fn () => $this->fail('Leaked session context accepted.'));
            $this->fail('Leaked context must be rejected.');
        } catch (LogicException) {
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            DB::select("select set_config('app.tenant_id', '', false)");
        }
    }

    public function test_local_migration_refuses_a_remote_host_without_exposing_secrets(): void
    {
        config(['database.connections.pgsql_migration.host' => 'untrusted.example',
            'database.connections.pgsql_migration.password' => 'synthetic-secret']);
        $this->artisan('app:migrate-local')->expectsOutput('Migration refused: use the dedicated local migration configuration.')
            ->assertFailed();
        $this->assertSame('monitoring_ai_app', DB::scalar('select current_user'));
    }

    public function test_local_migration_refuses_a_database_outside_the_dedicated_allowlist(): void
    {
        config(['database.connections.pgsql_migration.database' => 'shared_database']);
        $this->artisan('app:migrate-local')->expectsOutput('Migration refused: use the dedicated local migration configuration.')
            ->assertFailed();
        $this->assertSame('monitoring_ai_test', DB::scalar('select current_database()'));
    }

    public function test_invalid_tenant_id_is_rejected_before_work(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->context()->run('invalid-uuid', fn () => $this->fail('Invalid scope executed.'));
    }

    public function test_runtime_role_cannot_bypass_rls_or_own_protected_tables(): void
    {
        $role = DB::selectOne('select rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolreplication from pg_roles where rolname = current_user');
        foreach ((array) $role as $flag) {
            $this->assertFalse($flag);
        }
        $table = DB::selectOne("select pg_get_userbyid(relowner) as owner, relrowsecurity, relforcerowsecurity from pg_class where oid = 'public.tenants'::regclass");
        $this->assertSame('monitoring_ai_owner', $table->owner);
        $this->assertTrue($table->relrowsecurity);
        $this->assertTrue($table->relforcerowsecurity);
        $this->assertFalse(DB::scalar("select pg_has_role(current_user, 'monitoring_ai_owner', 'MEMBER')"));
        $this->assertFalse(DB::scalar("select has_schema_privilege(current_user, 'public', 'CREATE')"));
        $this->assertFalse(DB::scalar("select has_table_privilege(current_user, 'public.migrations', 'INSERT')"));
    }

    public function test_middleware_rejects_spoofed_headers_without_trusted_attribute(): void
    {
        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Tenant-ID', $this->tenantA);
        $this->expectException(HttpException::class);
        app(RequireTenantContext::class)->handle($request, fn () => $this->fail('Untrusted scope executed.'));
    }

    public function test_middleware_uses_trusted_attribute_and_cleans_context(): void
    {
        $request = Request::create('/test', 'GET');
        $request->attributes->set('trusted_tenant_id', $this->tenantA);
        $request->headers->set('X-Tenant-ID', $this->tenantB);
        $response = app(RequireTenantContext::class)->handle($request, fn () => response()->json(['id' => DB::table('tenants')->value('id')]));
        $this->assertSame($this->tenantA, $response->getData(true)['id']);
        $this->assertNoContext();
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    private function assertNoContext(): void
    {
        $this->assertNull(DB::scalar("select nullif(current_setting('app.tenant_id', true), '')"));
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected database constraint rejection.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}
