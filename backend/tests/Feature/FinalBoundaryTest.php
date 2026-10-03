<?php

namespace Tests\Feature;

use App\Domain\Identity\IdentityMappingService;
use App\Domain\Organization\OrganizationService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessTestCase;

class FinalBoundaryTest extends AccessTestCase
{
    public function test_head_uses_read_grants_and_cannot_mutate_with_query_inputs(): void
    {
        $this->checkHead(false);
    }

    public function test_head_with_manage_grants_still_cannot_mutate(): void
    {
        $this->checkHead(true);
    }

    private function checkHead(bool $manage): void
    {
        $this->fixture();
        $project = $this->project();
        foreach (['organization.read', 'identity.read'] as $purpose) {
            $this->grant($purpose);
        }
        foreach (['project.read', 'roles.read'] as $purpose) {
            $this->grant($purpose, $project);
        }
        if ($manage) {
            foreach (['organization.manage', 'identity.manage'] as $purpose) {
                $this->grant($purpose);
            }
            foreach (['project.manage', 'roles.manage'] as $purpose) {
                $this->grant($purpose, $project);
            }
        }
        [$identity, $mapping] = $this->identityFixture();
        $before = $this->state();
        $inputs = ['name' => 'HEAD mutation', 'display_name' => 'HEAD mutation', 'reason_code' => 'approved_change', 'expected_revision' => 1, 'employee_id' => $this->employee, 'role' => 'developer', 'valid_from' => '2026-01-01T00:00:00Z', 'at' => '2026-01-15T00:00:00Z'];
        foreach (['/api/v1/employees', '/api/v1/departments', '/api/v1/projects/'.$project, '/api/v1/projects/'.$project.'/roles', '/api/v1/identity-mappings/'.$mapping.'/history', '/api/v1/identities/'.$identity.'/attribution', '/api/v1/me', '/up'] as $path) {
            $this->withToken($this->token)->json('HEAD', $path.'?'.http_build_query($inputs))->assertOk()->assertContent('');
            $this->assertSame($before, $this->state());
        }
    }

    public function test_all_current_timestamp_columns_and_datetime_bindings_preserve_microseconds(): void
    {
        $columns = DB::select("select table_name,column_name,datetime_precision from information_schema.columns where table_schema='public' and data_type='timestamp with time zone'");
        $this->assertNotEmpty($columns);
        foreach ($columns as $column) {
            $this->assertSame(6, $column->datetime_precision, $column->table_name.'.'.$column->column_name);
        }
        $instant = CarbonImmutable::parse('2026-01-01T05:00:00.750001+05:00');
        $this->assertSame('2026-01-01 00:00:00.750001+00:00', DB::connection()->prepareBindings([$instant])[0]);
        $this->assertSame('+05:00', $instant->format('P'));
    }

    public function test_same_second_and_adjacent_role_membership_periods_are_valid(): void
    {
        $this->fixture();
        $project = $this->project();
        $this->grant('roles.manage', $project);
        $this->grant('roles.read', $project);
        $this->grant('membership.manage', $project);
        $base = ['employee_id' => $this->employee, 'valid_from' => '2026-01-01T00:00:00.200Z', 'valid_to' => '2026-01-01T00:00:00.400Z'];
        $this->withToken($this->token)->postJson('/api/v1/projects/'.$project.'/roles', $base + ['role' => 'developer'])->assertCreated();
        $this->postJson('/api/v1/projects/'.$project.'/roles', array_merge($base, ['role' => 'developer', 'valid_from' => $base['valid_to'], 'valid_to' => '2026-01-01T00:00:00.750Z']))->assertCreated();
        $membership = $this->postJson('/api/v1/projects/'.$project.'/memberships', $base)->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/projects/'.$project.'/memberships/'.$membership, ['valid_to' => '2026-01-01T00:00:00.750Z', 'expected_revision' => 1])->assertOk();
        foreach (['.199999' => 0, '.200' => 1, '.400' => 1, '.749999' => 1, '.750' => 0] as $fraction => $count) {
            $this->getJson('/api/v1/projects/'.$project.'/roles?'.http_build_query(['employee_id' => $this->employee, 'at' => '2026-01-01T00:00:00'.$fraction.'Z']))->assertOk()->assertJsonCount($count, 'data');
        }
        $this->postJson('/api/v1/projects/'.$project.'/roles', $base + ['role' => 'developer'])->assertConflict();
    }

    public function test_mapping_history_and_attribution_keep_fractional_half_open_boundaries(): void
    {
        $this->fixture();
        $this->grant('identity.manage');
        $this->grant('identity.read');
        [$identity] = $this->identityFixture();
        $base = ['employee_id' => $this->employee, 'reason' => 'Synthetic fraction', 'valid_from' => '2026-01-01T00:00:00.200Z', 'valid_to' => '2026-01-01T00:00:00.750Z'];
        $this->withToken($this->token);
        $mapping = $this->postJson('/api/v1/identities/'.$identity.'/mappings', $base)->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/identity-mappings/'.$mapping, $base + ['status' => 'approved', 'expected_revision' => 1])->assertOk();
        foreach (['.199999' => false, '.200' => true, '.749999' => true, '.750' => false] as $fraction => $present) {
            $response = $this->getJson('/api/v1/identities/'.$identity.'/attribution?'.http_build_query(['at' => '2026-01-01T00:00:00'.$fraction.'Z']))->assertOk();
            $this->assertSame($present, $response->json('data') !== null);
        }
        app(TenantContext::class)->run($this->tenant, function () use ($mapping): void {
            $current = DB::table('identity_mappings')->where('id', $mapping)->first();
            $history = DB::table('identity_mapping_histories')->where('mapping_id', $mapping)->where('revision', 2)->first();
            $this->assertSame('750000', CarbonImmutable::parse($current->valid_to)->format('u'));
            $this->assertSame($current->valid_from, $history->valid_from);
            $this->assertSame($current->valid_to, $history->valid_to);
        });
        $adjacent = array_merge($base, ['valid_from' => $base['valid_to'], 'valid_to' => '2026-01-01T00:00:00.900Z']);
        $second = $this->postJson('/api/v1/identities/'.$identity.'/mappings', $adjacent)->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/identity-mappings/'.$second, $adjacent + ['status' => 'approved', 'expected_revision' => 1])->assertOk();
        $this->getJson('/api/v1/identities/'.$identity.'/attribution?at=2026-01-01T00:00:00.750Z')->assertOk()->assertJsonPath('data.mapping_id', $second);
    }

    public function test_department_and_reporting_periods_preserve_fractional_instants(): void
    {
        $this->fixture();
        app(TenantContext::class)->run($this->tenant, function (): void {
            $service = app(OrganizationService::class);
            $department = $service->createDepartment($this->tenant, $this->user, 'Synthetic', 'approved_change');
            $manager = $service->createEmployee($this->tenant, $this->user, 'Synthetic manager', 'approved_change');
            $from = '2026-01-01T00:00:00.200Z';
            $to = '2026-01-01T00:00:00.750Z';
            $service->addDepartmentMembership($this->tenant, $this->user, $this->employee, $department->id, $from, $to, 'membership_change');
            $service->addReportingRelationship($this->tenant, $this->user, $this->employee, $manager->id, $from, $to, 'membership_change');
            foreach (['departmentMembershipsAt', 'reportingRelationshipsAt'] as $method) {
                $this->assertCount(0, $service->$method($this->employee, '2026-01-01T00:00:00.199999Z'));
                $this->assertCount(1, $service->$method($this->employee, $from));
                $this->assertCount(1, $service->$method($this->employee, '2026-01-01T00:00:00.749999Z'));
                $this->assertCount(0, $service->$method($this->employee, $to));
            }
        });
    }

    public function test_access_grant_membership_and_token_expire_at_exact_fractional_boundary(): void
    {
        $this->fixture();
        $project = $this->project();
        $grant = $this->grant('project.read', $project);
        $end = CarbonImmutable::parse('2026-01-01T00:00:00.750Z');
        $this->withToken($this->token);
        try {
            $this->travelTo($end->subMicrosecond());
            app(TenantContext::class)->run($this->tenant, function () use ($grant, $end): void {
                DB::table('auth_tokens')->update(['expires_at' => $end->addHour()]);
                DB::table('project_memberships')->update(['valid_from' => $end->subDay(), 'valid_to' => null]);
                DB::table('access_grants')->where('id', $grant)->update(['valid_from' => $end->subDay(), 'valid_to' => $end]);
            });
            $this->getJson('/api/v1/projects/'.$project)->assertOk();
            $this->travelTo($end);
            $this->getJson('/api/v1/projects/'.$project)->assertForbidden();
            app(TenantContext::class)->run($this->tenant, function () use ($grant, $end): void {
                DB::table('access_grants')->where('id', $grant)->update(['valid_to' => null]);
                DB::table('project_memberships')->update(['valid_to' => $end]);
            });
            $this->travelTo($end->subMicrosecond());
            $this->getJson('/api/v1/projects/'.$project)->assertOk();
            $this->travelTo($end);
            $this->getJson('/api/v1/projects/'.$project)->assertForbidden();
            app(TenantContext::class)->run($this->tenant, function () use ($grant, $end): void {
                DB::table('project_memberships')->update(['valid_to' => null]);
                DB::table('access_grants')->where('id', $grant)->update(['valid_to' => null, 'revoked_at' => $end]);
            });
            $this->getJson('/api/v1/projects/'.$project)->assertForbidden();
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_tokens')->update(['expires_at' => $end]));
            $this->travelTo($end->subMicrosecond());
            $this->getJson('/api/v1/me')->assertOk();
            $this->travelTo($end);
            $this->getJson('/api/v1/me')->assertUnauthorized();
        } finally {
            $this->travelBack();
            CarbonImmutable::setTestNow();
        }
    }

    private function identityFixture(): array
    {
        return app(TenantContext::class)->run($this->tenant, function (): array {
            $service = app(IdentityMappingService::class);
            $source = $service->createSource($this->tenant, $this->user, ['kind' => 'manual', 'label' => 'Synthetic']);
            $identity = $service->createIdentity($this->tenant, $this->user, $source->id, ['external_user_id' => 'synthetic']);
            $mapping = $service->propose($this->tenant, $this->user, $identity->id, ['employee_id' => $this->employee, 'reason' => 'Synthetic', 'valid_from' => '2025-01-01T00:00:00Z', 'valid_to' => '2025-02-01T00:00:00Z']);

            return [$identity->id, $mapping->id];
        });
    }

    private function state(): array
    {
        return app(TenantContext::class)->run($this->tenant, function (): array {
            $result = [];
            foreach (['employees', 'departments', 'projects', 'role_assignments', 'project_memberships', 'identity_mappings', 'identity_mapping_histories', 'audit_events'] as $table) {
                $result[$table] = DB::table($table)->orderBy('id')->get()->all();
            }

            return json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        });
    }
}
