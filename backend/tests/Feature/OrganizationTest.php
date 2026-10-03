<?php

namespace Tests\Feature;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\Organization\OrganizationService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\PostgresTestCase;

class OrganizationTest extends PostgresTestCase
{
    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->tenant = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('tenants')->insert(['id' => $this->tenant, 'name' => 'Synthetic organization']));
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_employee_is_separate_from_login(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('employees'));
        app(TenantContext::class)->run($this->tenant, function (): void {
            $employee = app(OrganizationService::class)->createEmployee($this->tenant, (string) Str::uuid(), 'Synthetic employee', 'local_setup');
            $this->assertSame('Synthetic employee', DB::table('employees')->where('id', $employee->id)->value('display_name'));
            $this->assertFalse(property_exists($employee, 'password'));
            $this->assertFalse(property_exists($employee, 'email'));
            $this->assertSame(1, DB::table('audit_events')->where('resource_id', $employee->id)->count());
        });
    }

    public function test_department_membership_does_not_grant_project_access(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('department_memberships'));
        app(TenantContext::class)->run($this->tenant, function (): void {
            $service = app(OrganizationService::class);
            $actor = (string) Str::uuid();
            $employee = $service->createEmployee($this->tenant, $actor, 'Employee', 'local_setup');
            $department = $service->createDepartment($this->tenant, $actor, 'Department', 'local_setup');
            $service->addDepartmentMembership($this->tenant, $actor, $employee->id, $department->id, '2026-10-01T00:00:00Z', null, 'local_setup');
            $this->assertSame(1, DB::table('department_memberships')->count());
            try {
                app(AccessGateway::class)->authorize(new TrustedActor($actor, $this->tenant), 'project.read', ['tenant_id' => $this->tenant, 'project_id' => (string) Str::uuid()]);
                $this->fail('Department membership granted project access.');
            } catch (AccessDeniedHttpException) {
                $this->assertTrue(true);
            }
        });
    }

    public function test_lists_have_bounded_cursor_pages(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('employees'));
        app(TenantContext::class)->run($this->tenant, function (): void {
            $service = app(OrganizationService::class);
            for ($i = 0; $i < 3; $i++) {
                $service->createEmployee($this->tenant, (string) Str::uuid(), 'Employee '.$i, 'local_setup');
            }
            $first = $service->listEmployees(2, null);
            $second = $service->listEmployees(2, $first['next_cursor']);
            $this->assertCount(2, $first['data']);
            $this->assertCount(1, $second['data']);
            $this->assertNull($second['next_cursor']);
            $this->assertEmpty(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
        });
    }

    public function test_organization_http_endpoints_deny_anonymous_requests(): void
    {
        $this->getJson('/api/v1/employees')->assertUnauthorized();
        $this->postJson('/api/v1/employees', ['display_name' => 'Untrusted'])->assertUnauthorized();
        $this->getJson('/api/v1/departments')->assertUnauthorized();
    }

    public function test_stale_revision_cannot_overwrite_employee(): void
    {
        app(TenantContext::class)->run($this->tenant, function (): void {
            $service = app(OrganizationService::class);
            $actor = (string) Str::uuid();
            $employee = $service->createEmployee($this->tenant, $actor, 'Original', 'local_setup');
            $service->updateEmployee($this->tenant, $actor, $employee->id, 'Updated', 1, 'approved_change');
            try {
                $service->updateEmployee($this->tenant, $actor, $employee->id, 'Stale', 1, 'approved_change');
                $this->fail('Stale revision accepted.');
            } catch (ConflictHttpException) {
                $this->assertSame('Updated', DB::table('employees')->where('id', $employee->id)->value('display_name'));
                $this->assertSame(2, DB::table('audit_events')->count());
            }
        });
    }

    public function test_relationship_history_uses_half_open_periods(): void
    {
        app(TenantContext::class)->run($this->tenant, function (): void {
            $service = app(OrganizationService::class);
            $actor = (string) Str::uuid();
            $employee = $service->createEmployee($this->tenant, $actor, 'Employee', 'local_setup');
            $manager = $service->createEmployee($this->tenant, $actor, 'Manager', 'local_setup');
            $department = $service->createDepartment($this->tenant, $actor, 'Department', 'local_setup');
            $service->addDepartmentMembership($this->tenant, $actor, $employee->id, $department->id, '2026-10-01T00:00:00Z', '2026-10-03T00:00:00Z', 'membership_change');
            $service->addReportingRelationship($this->tenant, $actor, $employee->id, $manager->id, '2026-10-01T00:00:00Z', '2026-10-03T00:00:00Z', 'membership_change');
            $this->assertCount(1, $service->departmentMembershipsAt($employee->id, '2026-10-01T00:00:00Z'));
            $this->assertCount(0, $service->departmentMembershipsAt($employee->id, '2026-10-03T00:00:00Z'));
            $this->assertSame($manager->id, $service->reportingRelationshipsAt($employee->id, '2026-10-02T00:00:00Z')[0]->manager_id);
            $this->assertCount(0, $service->reportingRelationshipsAt($employee->id, '2026-10-03T00:00:00Z'));
        });
    }

    public function test_cross_tenant_relationship_and_reads_are_denied(): void
    {
        $otherTenant = (string) Str::uuid();
        $department = app(TenantContext::class)->run($otherTenant, function () use ($otherTenant): object {
            DB::table('tenants')->insert(['id' => $otherTenant, 'name' => 'Other synthetic tenant']);

            return app(OrganizationService::class)->createDepartment($otherTenant, (string) Str::uuid(), 'Private department', 'local_setup');
        });
        app(TenantContext::class)->run($this->tenant, function () use ($department): void {
            $this->assertSame(0, DB::table('departments')->where('id', $department->id)->count());
            $employee = app(OrganizationService::class)->createEmployee($this->tenant, (string) Str::uuid(), 'Employee', 'local_setup');
            try {
                app(OrganizationService::class)->addDepartmentMembership($this->tenant, (string) Str::uuid(), $employee->id, $department->id, '2026-10-01T00:00:00Z', null, 'membership_change');
                $this->fail('Cross-tenant relationship accepted.');
            } catch (QueryException $exception) {
                $this->assertSame('23503', $exception->errorInfo[0]);
                $this->assertSame(0, DB::table('department_memberships')->count());
                $this->assertSame(1, DB::table('audit_events')->count());
            }
        });
        $this->assertSame(0, DB::table('employees')->count());
        $this->assertSame(0, DB::table('audit_events')->count());
    }
}
