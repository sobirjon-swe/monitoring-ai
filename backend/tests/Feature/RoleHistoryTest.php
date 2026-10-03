<?php

namespace Tests\Feature;

use App\Domain\Projects\ProjectService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessTestCase;

class RoleHistoryTest extends AccessTestCase
{
    public function test_concurrent_roles_are_allowed(): void
    {
        $this->fixture();
        $project = $this->project();
        app(TenantContext::class)->run($this->tenant, function () use ($project): void {
            $service = app(ProjectService::class);
            foreach (['developer', 'qa'] as $role) {
                $service->assignRole($this->tenant, $this->user, $project, $this->employee, $role, '2026-10-01T00:00:00Z', '2026-10-04T00:00:00Z');
            }
            $this->assertCount(2, $service->rolesAt($project, $this->employee, '2026-10-02T00:00:00Z'));
            $this->assertCount(0, $service->rolesAt($project, $this->employee, '2026-10-04T00:00:00Z'));
        });
    }

    public function test_duplicate_overlapping_role_is_rejected(): void
    {
        $this->fixture();
        $project = $this->project();
        app(TenantContext::class)->run($this->tenant, function () use ($project): void {
            $service = app(ProjectService::class);
            $service->assignRole($this->tenant, $this->user, $project, $this->employee, 'developer', '2026-10-01T00:00:00Z', '2026-10-04T00:00:00Z');
            try {
                $service->assignRole($this->tenant, $this->user, $project, $this->employee, 'developer', '2026-10-03T00:00:00Z', null);
                $this->fail('Duplicate overlap accepted.');
            } catch (QueryException $exception) {
                $this->assertSame('23P01', $exception->errorInfo[0]);
            }
            $service->assignRole($this->tenant, $this->user, $project, $this->employee, 'developer', '2026-10-04T00:00:00Z', null);
            $this->assertSame(2, DB::table('role_assignments')->count());
        });
    }

    public function test_past_role_does_not_restore_current_access(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('roles.read', $project);
        app(TenantContext::class)->run($this->tenant, function () use ($project): void {
            app(ProjectService::class)->assignRole($this->tenant, $this->user, $project, $this->employee, 'pm', '2020-01-01T00:00:00Z', '2020-02-01T00:00:00Z');
        });
        $url = '/api/v1/projects/'.$project.'/roles?employee_id='.$this->employee.'&at=2020-01-15T00:00:00Z';
        $this->withToken($this->token)->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.role', 'pm');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->update(['valid_to' => now()]));
        $this->getJson($url)->assertForbidden();
    }

    public function test_extending_role_into_adjacent_period_returns_409_without_changes(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('roles.manage', $project);
        $role = app(TenantContext::class)->run($this->tenant, function () use ($project): object {
            $service = app(ProjectService::class);
            $first = $service->assignRole($this->tenant, $this->user, $project, $this->employee, 'developer', '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z');
            $service->assignRole($this->tenant, $this->user, $project, $this->employee, 'developer', '2026-02-01T00:00:00Z', '2026-03-01T00:00:00Z');

            return $first;
        });
        $auditCount = app(TenantContext::class)->run($this->tenant, fn () => DB::table('audit_events')->count());
        $this->withToken($this->token)->patchJson('/api/v1/projects/'.$project.'/roles/'.$role->id, ['valid_to' => '2026-02-15T00:00:00Z', 'expected_revision' => 1])->assertConflict();
        app(TenantContext::class)->run($this->tenant, function () use ($role, $auditCount): void {
            $unchanged = DB::table('role_assignments')->where('id', $role->id)->first();
            $this->assertSame($role->valid_to, $unchanged->valid_to);
            $this->assertSame(1, $unchanged->revision);
            $this->assertSame($auditCount, DB::table('audit_events')->count());
        });
    }

    public function test_stale_revision_returns_409(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('project.manage', $project);
        $this->withToken($this->token)->patchJson('/api/v1/projects/'.$project, ['name' => 'Updated', 'expected_revision' => 1])->assertOk();
        $this->patchJson('/api/v1/projects/'.$project, ['name' => 'Stale', 'expected_revision' => 1])->assertConflict();
    }
}
