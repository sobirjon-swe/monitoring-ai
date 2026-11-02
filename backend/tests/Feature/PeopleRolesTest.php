<?php

namespace Tests\Feature;

use App\Domain\Browser\BrowserProjectReader;
use App\Domain\PeopleRoles\PeopleRolesService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\BrowserTestCase;

class PeopleRolesTest extends BrowserTestCase
{
    public function test_catalog_admin_assignment_requires_both_read_grants_and_capability_does_not_bypass_them(): void
    {
        $f = $this->browserInputs(['project.read', 'roles.manage']);
        $this->grant('role_catalog.manage');
        $service = app(PeopleRolesService::class);
        $role = $service->create($f['actor'], ['name' => 'Custom role', 'request_id' => (string) Str::uuid()])['item'];
        $this->assertNotContains('roles.assign', app(BrowserProjectReader::class)->capabilities($f['actor'], $f['project'])['purposes']);
        try {
            $service->assign($f['actor'], $f['project'], ['employee_id' => $this->employee, 'role_catalog_id' => $role['id'], 'expected_role_revision' => 1, 'request_id' => (string) Str::uuid()]);
            $this->fail('Administrator assignment without employee read accepted.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
        $this->grant('employees.read', $f['project']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'project.read')->update(['revoked_at' => now()]));
        $this->expectException(AccessDeniedHttpException::class);
        $service->assign($f['actor'], $f['project'], ['employee_id' => $this->employee, 'role_catalog_id' => $role['id'], 'expected_role_revision' => 1, 'request_id' => (string) Str::uuid()]);
    }

    public function test_project_catalog_denies_catalog_only_admin_before_exposing_project_existence(): void
    {
        $f = $this->browserInputs();
        $this->grant('role_catalog.manage');
        foreach ([$f['project'], (string) Str::uuid()] as $project) {
            try {
                app(PeopleRolesService::class)->catalog($f['actor'], $project, []);
                $this->fail('Project catalog without project read accepted.');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_string_revisions_are_normalized_before_compare_and_request_hash(): void
    {
        $f = $this->browserInputs();
        $this->grant('role_catalog.manage');
        $service = app(PeopleRolesService::class);
        $role = $service->create($f['actor'], ['name' => 'Role', 'request_id' => (string) Str::uuid()])['item'];
        $input = ['name' => 'Edited role', 'description' => '', 'status' => 'active', 'expected_revision' => '1', 'request_id' => (string) Str::uuid()];
        $first = $service->update($f['actor'], $role['id'], $input);
        $this->assertSame(2, $first['item']['revision']);
        $input['expected_revision'] = 1;
        $this->assertSame($first['item'], $service->update($f['actor'], $role['id'], $input)['item']);
    }

    public function test_runtime_tables_force_rls_and_deny_destructive_privileges(): void
    {
        foreach (['role_catalog', 'project_catalog_role_assignments', 'people_role_requests'] as $table) {
            $row = DB::selectOne('SELECT c.relrowsecurity, c.relforcerowsecurity, r.rolname FROM pg_class c JOIN pg_roles r ON r.oid=c.relowner WHERE c.oid=?::regclass', [$table]);
            $this->assertTrue($row->relrowsecurity);
            $this->assertTrue($row->relforcerowsecurity);
            $this->assertNotSame('monitoring_ai_app', $row->rolname);
            foreach (['DELETE', 'TRUNCATE', 'TRIGGER', 'REFERENCES'] as $privilege) {
                $this->assertFalse(DB::scalar('SELECT has_table_privilege(current_user, ?, ?)', [$table, $privilege]));
            }
        }
        $this->assertFalse(DB::scalar("SELECT has_table_privilege(current_user, 'people_role_requests', 'UPDATE')"));
        $this->assertFalse(DB::scalar('SELECT rolsuper OR rolbypassrls FROM pg_roles WHERE rolname=current_user'));
    }

    public function test_catalog_create_replays_without_granting_functional_authority(): void
    {
        $f = $this->browserInputs();
        $this->grant('role_catalog.manage');
        $input = ['name' => 'Release engineer', 'description' => 'Coordinates releases', 'request_id' => (string) Str::uuid()];
        $service = app(PeopleRolesService::class);
        $first = $service->create($f['actor'], $input);
        $again = $service->create($f['actor'], $input);
        $this->assertSame($first['item'], $again['item']);
        $this->assertTrue($first['created']);
        $this->assertFalse($again['created']);
        app(TenantContext::class)->run($this->tenant, function (): void {
            $this->assertSame(0, DB::table('role_assignments')->count());
            $this->assertSame(1, DB::table('role_catalog')->count());
        });
    }

    public function test_project_manage_grant_does_not_allow_catalog_creation(): void
    {
        $f = $this->browserInputs(['roles.manage']);
        $this->expectException(AccessDeniedHttpException::class);
        app(PeopleRolesService::class)->create($f['actor'], ['name' => 'Custom PM', 'description' => '', 'request_id' => (string) Str::uuid()]);
    }

    public function test_pm_can_assign_without_creating_membership_or_system_grants(): void
    {
        $f = $this->browserInputs(['project.read', 'roles.manage', 'employees.read', 'roles.read']);
        $this->grant('role_catalog.manage');
        $service = app(PeopleRolesService::class);
        $role = $service->create($f['actor'], ['name' => 'Custom reviewer', 'description' => 'Descriptive only', 'request_id' => (string) Str::uuid()])['item'];
        app(TenantContext::class)->run($this->tenant, function () use ($f) {
            DB::table('access_grants')->where('purpose', 'role_catalog.manage')->update(['revoked_at' => now()]);
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $f['project'], 'employee_id' => $this->employee, 'role' => 'pm', 'valid_from' => now()->subDay()]);
        });
        $input = ['employee_id' => $this->employee, 'role_catalog_id' => $role['id'], 'expected_role_revision' => 1, 'request_id' => (string) Str::uuid()];
        $assignment = $service->assign($f['actor'], $f['project'], $input);
        $this->assertTrue($assignment['created']);
        $this->assertSame($assignment['item'], $service->assign($f['actor'], $f['project'], $input)['item']);
        $page = $service->employees($f['actor'], $f['project'], []);
        $this->assertSame(1, $page['visible_record_count']);
        $this->assertSame('Custom reviewer', $page['items'][0]['roles'][0]['name']);
        $this->assertSame(['pm'], $page['items'][0]['system_roles']);
        $catalog = $service->catalog($f['actor'], $f['project'], []);
        $this->assertSame([$role], $catalog['items']);
        app(TenantContext::class)->run($this->tenant, function () {
            $this->assertSame(1, DB::table('project_memberships')->count());
            $this->assertSame(1, DB::table('role_assignments')->count());
            $this->assertSame(5, DB::table('access_grants')->count());
        });
    }

    public function test_roles_manage_without_pm_is_denied_and_catalog_admin_cannot_read_employees(): void
    {
        $f = $this->browserInputs(['roles.manage']);
        $service = app(PeopleRolesService::class);
        try {
            app(TenantContext::class)->run($this->tenant, fn () => $service->authority($f['actor'], 'roles.assign', $f['project']));
            $this->fail('Missing PM responsibility accepted.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
        $this->grant('role_catalog.manage');
        $this->expectException(AccessDeniedHttpException::class);
        $service->employees($f['actor'], $f['project'], []);
    }

    public function test_revoked_catalog_grant_cannot_replay_successful_request(): void
    {
        $f = $this->browserInputs();
        $grant = $this->grant('role_catalog.manage');
        $input = ['name' => 'Architect', 'description' => 'Architecture', 'request_id' => (string) Str::uuid()];
        app(PeopleRolesService::class)->create($f['actor'], $input);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
        $this->expectException(AccessDeniedHttpException::class);
        app(PeopleRolesService::class)->create($f['actor'], $input);
    }

    public function test_project_catalog_manage_scope_is_refused(): void
    {
        $f = $this->browserInputs(['role_catalog.manage']);
        $this->expectException(AccessDeniedHttpException::class);
        app(PeopleRolesService::class)->create($f['actor'], ['name' => 'Architect', 'description' => 'Architecture', 'request_id' => (string) Str::uuid()]);
    }

    public function test_browser_routes_validate_shapes_and_pm_cannot_create_catalog(): void
    {
        $f = $this->browserInputs(['project.read', 'roles.manage', 'employees.read', 'roles.read']);
        $this->loginBrowser();
        $this->get('/browser/v1/projects/'.$f['project'].'/employees')->assertOk()->assertJsonPath('data.visible_record_count', 1);
        $this->get('/browser/v1/projects/'.$f['project'].'/employees?tenant_id='.$this->tenant)->assertUnprocessable();
        $this->postJson('/browser/v1/role-catalog', ['name' => 'Reviewer', 'description' => '', 'request_id' => (string) Str::uuid()])->assertForbidden();
        $this->grant('role_catalog.manage');
        $id = (string) Str::uuid();
        $this->postJson('/browser/v1/role-catalog', ['name' => 'Reviewer', 'description' => '', 'request_id' => $id])->assertCreated()->assertJsonPath('data.description', '');
        $this->postJson('/browser/v1/role-catalog', ['request_id' => $id, 'description' => '', 'name' => 'Reviewer'])->assertOk();
        $this->postJson('/browser/v1/role-catalog', ['name' => 'Injected', 'request_id' => (string) Str::uuid(), 'tenant_id' => $this->tenant])->assertUnprocessable();
    }

    public function test_archiving_preserves_assignment_name_and_rejects_stale_and_archived_writes(): void
    {
        $f = $this->browserInputs(['project.read', 'roles.manage', 'employees.read']);
        $this->grant('role_catalog.manage');
        $service = app(PeopleRolesService::class);
        $role = $service->create($f['actor'], ['name' => 'Release owner', 'request_id' => (string) Str::uuid()])['item'];
        $input = ['employee_id' => $this->employee, 'role_catalog_id' => $role['id'], 'expected_role_revision' => 1, 'request_id' => (string) Str::uuid()];
        $service->assign($f['actor'], $f['project'], $input);
        $updated = $service->update($f['actor'], $role['id'], ['name' => 'Archived release owner', 'description' => '', 'status' => 'archived', 'expected_revision' => 1, 'request_id' => (string) Str::uuid()]);
        $this->assertSame(2, $updated['item']['revision']);
        $this->assertSame('Release owner', $service->employees($f['actor'], $f['project'], [])['items'][0]['roles'][0]['name']);
        foreach ([1, 2] as $revision) {
            try {
                $service->assign($f['actor'], $f['project'], array_replace($input, ['expected_role_revision' => $revision, 'request_id' => (string) Str::uuid()]));
                $this->fail('Archived role accepted.');
            } catch (ConflictHttpException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(ConflictHttpException::class);
        $service->update($f['actor'], $role['id'], ['name' => 'Stale', 'description' => '', 'status' => 'active', 'expected_revision' => 1, 'request_id' => (string) Str::uuid()]);
    }

    public function test_target_membership_bounds_and_duplicate_periods_are_enforced(): void
    {
        $f = $this->browserInputs(['project.read', 'employees.read', 'roles.manage']);
        $this->grant('role_catalog.manage');
        $service = app(PeopleRolesService::class);
        $role = $service->create($f['actor'], ['name' => 'Architect', 'request_id' => (string) Str::uuid()])['item'];
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('project_id', $f['project'])->update(['valid_to' => now()->addHour()]));
        $input = ['employee_id' => $this->employee, 'role_catalog_id' => $role['id'], 'expected_role_revision' => 1, 'request_id' => (string) Str::uuid()];
        try {
            $service->assign($f['actor'], $f['project'], $input);
            $this->fail('Unbounded period escaped membership.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
        $input['valid_to'] = now()->addMinutes(30)->toIso8601String();
        $service->assign($f['actor'], $f['project'], $input);
        $this->expectException(ConflictHttpException::class);
        $service->assign($f['actor'], $f['project'], array_replace($input, ['request_id' => (string) Str::uuid()]));
    }

    public function test_catalog_rls_and_composite_tenant_foreign_keys_reject_cross_tenant_references(): void
    {
        $f = $this->browserInputs(['roles.manage']);
        $this->grant('role_catalog.manage');
        $role = app(PeopleRolesService::class)->create($f['actor'], ['name' => 'Architect', 'request_id' => (string) Str::uuid()])['item'];
        $this->assertSame(0, DB::table('role_catalog')->count());
        $otherTenant = (string) Str::uuid();
        $otherProject = (string) Str::uuid();
        app(TenantContext::class)->run($otherTenant, function () use ($otherTenant, $otherProject) {
            DB::table('tenants')->insert(['id' => $otherTenant, 'name' => 'Other synthetic tenant']);
            DB::table('projects')->insert(['id' => $otherProject, 'tenant_id' => $otherTenant, 'name' => 'Other project']);
            $this->assertSame(0, DB::table('role_catalog')->count());
        });
        try {
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_catalog_role_assignments')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $otherProject, 'employee_id' => $this->employee,
                'role_catalog_id' => $role['id'], 'definition_revision' => 1, 'definition_name' => 'Architect', 'valid_from' => now(),
            ]));
            $this->fail('Cross tenant project FK accepted.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0]);
        }
        try {
            app(TenantContext::class)->run($otherTenant, fn () => DB::table('role_catalog')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'key' => (string) Str::uuid(), 'name' => 'RLS bypass',
            ]));
            $this->fail('Cross tenant insert accepted.');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->errorInfo[0]);
        }
    }

    public function test_assign_capability_requires_exact_project_grant_and_current_functional_pm(): void
    {
        $f = $this->browserInputs(['project.read', 'employees.read']);
        $this->grant('roles.manage');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $f['project'], 'employee_id' => $this->employee, 'role' => 'pm', 'valid_from' => now()->subDay(),
        ]));
        $reader = app(BrowserProjectReader::class);
        $this->assertNotContains('roles.assign', $reader->capabilities($f['actor'], $f['project'])['purposes']);
        $grant = $this->grant('roles.manage', $f['project']);
        $this->assertContains('roles.assign', $reader->capabilities($f['actor'], $f['project'])['purposes']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('role_assignments')->update(['valid_to' => now()]));
        $this->assertNotContains('roles.assign', $reader->capabilities($f['actor'], $f['project'])['purposes']);
        $this->grant('role_catalog.manage');
        $this->assertContains('roles.assign', $reader->capabilities($f['actor'], $f['project'])['purposes']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
        $this->assertNotContains('roles.assign', $reader->capabilities($f['actor'], $f['project'])['purposes']);
    }

    public function test_catalog_pagination_binds_filters_and_explicit_tenant_read_has_no_employee_access(): void
    {
        $f = $this->browserInputs(['project.read']);
        $this->grant('role_catalog.read');
        app(TenantContext::class)->run($this->tenant, function () {
            for ($i = 0; $i < 28; $i++) {
                DB::table('role_catalog')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'key' => (string) Str::uuid(), 'name' => 'Specialist '.$i]);
            }
        });
        $service = app(PeopleRolesService::class);
        $first = $service->catalog($f['actor'], $f['project'], []);
        $second = $service->catalog($f['actor'], $f['project'], ['cursor' => $first['next_cursor']]);
        $this->assertCount(25, $first['items']);
        $this->assertCount(3, $second['items']);
        $this->assertSame(28, $first['visible_record_count']);
        $this->assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        $capabilities = app(BrowserProjectReader::class)->capabilities($f['actor'], $f['project'])['purposes'];
        $this->assertContains('roles.read', $capabilities);
        $this->assertNotContains('employees.read', $capabilities);
        $this->expectException(ValidationException::class);
        $service->catalog($f['actor'], $f['project'], ['cursor' => $first['next_cursor'], 'status' => 'all']);
    }
}
