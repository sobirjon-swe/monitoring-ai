<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccessTestCase;

class ProjectAccessTest extends AccessTestCase
{
    public function test_revoked_membership_denies_next_read(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('project.read', $project);
        $this->withToken($this->token)->getJson('/api/v1/projects/'.$project)->assertOk();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->update(['valid_to' => now()]));
        $this->getJson('/api/v1/projects/'.$project)->assertForbidden();
    }

    public function test_revoked_grant_denies_next_read(): void
    {
        $this->fixture();
        $grant = $this->grant('organization.read');
        $this->withToken($this->token)->getJson('/api/v1/employees')->assertOk();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
        $this->getJson('/api/v1/employees')->assertForbidden();
    }

    public function test_organization_crud_uses_real_actor_and_stale_revision_returns_409(): void
    {
        $this->fixture();
        $this->grant('organization.read');
        $this->grant('organization.manage');
        $result = $this->withToken($this->token)->postJson('/api/v1/employees', ['display_name' => 'Created', 'reason_code' => 'approved_change', 'actor_id' => (string) Str::uuid(), 'tenant_id' => (string) Str::uuid()])->assertCreated();
        $id = $result->json('data.id');
        $this->patchJson('/api/v1/employees/'.$id, ['display_name' => 'Updated', 'expected_revision' => 1, 'reason_code' => 'approved_change'])->assertOk();
        $this->patchJson('/api/v1/employees/'.$id, ['display_name' => 'Stale', 'expected_revision' => 1, 'reason_code' => 'approved_change'])->assertConflict();
        app(TenantContext::class)->run($this->tenant, function () use ($id): void {
            $this->assertSame($this->user, DB::table('audit_events')->where('resource_id', $id)->value('actor_id'));
            $this->assertSame('Updated', DB::table('employees')->where('id', $id)->value('display_name'));
        });
    }

    public function test_department_and_project_grants_do_not_widen_organization_lists(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('organization.read', $project);
        $department = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('departments')->insert(['id' => $department, 'tenant_id' => $this->tenant, 'name' => 'Restricted']));
        $this->grant('organization.read', null, $department);
        $this->withToken($this->token)->getJson('/api/v1/employees')->assertForbidden();
    }

    public function test_project_scope_cannot_cross_tenants(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('project.read', $project);
        $otherProject = (string) Str::uuid();
        $otherTenant = (string) Str::uuid();
        app(TenantContext::class)->run($otherTenant, function () use ($otherTenant, $otherProject): void {
            DB::table('tenants')->insert(['id' => $otherTenant, 'name' => 'Other']);
            DB::table('projects')->insert(['id' => $otherProject, 'tenant_id' => $otherTenant, 'name' => 'Private']);
        });
        $this->withToken($this->token)->getJson('/api/v1/projects/'.$otherProject)->assertForbidden()->assertDontSee('Private');
    }

    public function test_role_and_membership_mutations_require_separate_explicit_grants(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('project.read', $project);
        $this->withToken($this->token)->postJson('/api/v1/projects/'.$project.'/roles', ['employee_id' => $this->employee, 'role' => 'pm', 'valid_from' => '2026-01-01T00:00:00Z'])->assertForbidden();
        $this->grant('roles.manage', $project);
        $this->grant('roles.read', $project);
        $role = $this->postJson('/api/v1/projects/'.$project.'/roles', ['employee_id' => $this->employee, 'role' => 'qa', 'valid_from' => '2026-01-01T00:00:00Z'])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/projects/'.$project.'/roles/'.$role, ['valid_to' => '2026-02-01T00:00:00Z', 'expected_revision' => 1])->assertOk();
        $this->patchJson('/api/v1/projects/'.$project.'/roles/'.$role, ['valid_to' => '2026-03-01T00:00:00Z', 'expected_revision' => 1])->assertConflict();
        $this->getJson('/api/v1/projects/'.$project.'/roles?employee_id='.$this->employee.'&at=2026-02-01T00:00:00Z')->assertOk()->assertExactJson(['data' => []]);
        $membership = app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->value('id'));
        $this->patchJson('/api/v1/projects/'.$project.'/memberships/'.$membership, ['valid_to' => now()->toIso8601String(), 'expected_revision' => 1])->assertForbidden();
        $this->grant('membership.manage', $project);
        $this->patchJson('/api/v1/projects/'.$project.'/memberships/'.$membership, ['valid_to' => now()->toIso8601String(), 'expected_revision' => 1])->assertOk();
        $this->getJson('/api/v1/projects/'.$project)->assertForbidden();
    }

    public function test_project_creation_does_not_create_payload_privileges(): void
    {
        $this->fixture();
        $this->withToken($this->token)->postJson('/api/v1/projects', ['name' => 'Unauthorized'])->assertForbidden();
        $this->grant('project.create');
        $result = $this->postJson('/api/v1/projects', ['name' => 'New project', 'admin' => true, 'access_grants' => ['organization.manage']])->assertCreated();
        app(TenantContext::class)->run($this->tenant, function () use ($result): void {
            $this->assertSame(1, DB::table('access_grants')->count());
            $this->assertSame($this->employee, DB::table('project_memberships')->where('project_id', $result->json('data.id'))->value('employee_id'));
        });
        $this->getJson('/api/v1/projects/'.$result->json('data.id'))->assertForbidden();
    }

    public function test_policy_retirement_and_exact_grant_expiry_deny_access(): void
    {
        $this->fixture();
        $this->grant('organization.read');
        $this->withToken($this->token)->getJson('/api/v1/employees')->assertOk();
        $this->travelTo(CarbonImmutable::parse('2030-01-01T00:00:00Z'));
        app(TenantContext::class)->run($this->tenant, function (): void {
            DB::table('auth_tokens')->update(['expires_at' => now()->addHour()]);
            DB::table('access_grants')->update(['valid_to' => now()]);
        });
        $this->getJson('/api/v1/employees')->assertForbidden();
        app(TenantContext::class)->run($this->tenant, function (): void {
            DB::table('access_grants')->update(['valid_to' => null]);
            DB::table('policy_versions')->update(['status' => 'retired']);
        });
        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->travelBack();
    }

    public function test_login_subject_uniqueness_and_cross_tenant_grant_fk_are_enforced(): void
    {
        $this->fixture();
        $otherTenant = (string) Str::uuid();
        $otherProject = (string) Str::uuid();
        app(TenantContext::class)->run($otherTenant, function () use ($otherTenant, $otherProject): void {
            DB::table('tenants')->insert(['id' => $otherTenant, 'name' => 'Other']);
            DB::table('projects')->insert(['id' => $otherProject, 'tenant_id' => $otherTenant, 'name' => 'Other project']);
        });
        app(TenantContext::class)->run($this->tenant, function () use ($otherProject): void {
            try {
                DB::transaction(fn () => DB::table('auth_subjects')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'user_id' => $this->user, 'issuer' => 'local-development', 'subject' => $this->user]));
                $this->fail('Duplicate issuer/subject accepted.');
            } catch (QueryException $exception) {
                $this->assertSame('23505', $exception->errorInfo[0]);
            }
            try {
                $this->grant('project.read', $otherProject);
                $this->fail('Cross-tenant grant accepted.');
            } catch (QueryException $exception) {
                $this->assertSame('23503', $exception->errorInfo[0]);
            }
        });
    }
}
