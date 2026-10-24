<?php

namespace Tests\Feature;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\Browser\BrowserProjectReader;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\BrowserTestCase;

class BrowserProjectAccessTest extends BrowserTestCase
{
    public function test_discovery_requires_explicit_current_project_read_and_membership(): void
    {
        $f = $this->browserInputs();
        $hidden = $this->project();
        $this->grant('qa.read', $hidden);
        $this->assertSame([], $this->page()['items']);
        $grant = $this->grant('project.read', $f['project']);
        $page = $this->page();
        $this->assertSame([$f['project']], array_column($page['items'], 'id'));
        $this->assertArrayNotHasKey('total', $page);
        $this->assertSame(['id', 'name', 'revision'], array_keys($page['items'][0]));
        foreach ([['access_grants', 'id', $grant, ['revoked_at' => now()]], ['policy_versions', 'id', $this->policy, ['status' => 'retired']], ['project_memberships', 'project_id', $f['project'], ['valid_to' => now()]], ['users', 'id', $this->user, ['employee_id' => null]]] as [$table, $key, $id, $values]) {
            app(TenantContext::class)->run($this->tenant, function () use ($table, $key, $id, $values): void {
                DB::beginTransaction();
                try {
                    DB::table($table)->where($key, $id)->update($values);
                    $this->assertSame([], $this->page()['items']);
                } finally {
                    DB::rollBack();
                }
            });
        }
    }

    public function test_signed_pagination_rechecks_revocation_and_binds_actor_and_tenant(): void
    {
        $this->browserInputs();
        $this->grant('project.read');
        for ($i = 1; $i < 28; $i++) {
            $this->project();
        }
        $first = $this->page();
        $this->assertCount(25, $first['items']);
        $second = $this->page($first['next_cursor']);
        $this->assertCount(3, $second['items']);
        $this->assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        foreach ([new TrustedActor((string) Str::uuid(), $this->tenant), new TrustedActor($this->user, (string) Str::uuid())] as $actor) {
            try {
                app(BrowserProjectReader::class)->page($actor, $first['next_cursor']);
                $this->fail('Cursor scope accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->update(['revoked_at' => now()]));
        $this->assertSame([], $this->page($first['next_cursor'])['items']);
    }

    public function test_department_grants_cross_tenant_projects_and_inactive_subjects_are_not_discovered(): void
    {
        $f = $this->browserInputs();
        $department = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('departments')->insert(['id' => $department, 'tenant_id' => $this->tenant, 'name' => 'Synthetic department']));
        $this->grant('project.read', null, $department);
        $this->assertSame([], $this->page()['items']);
        $this->grant('project.read');
        $other = (string) Str::uuid();
        app(TenantContext::class)->run($other, function () use ($other): void {
            DB::table('tenants')->insert(['id' => $other, 'name' => 'Hidden tenant']);
            DB::table('projects')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $other, 'name' => 'Hidden project']);
        });
        $this->assertSame([$f['project']], array_column($this->page()['items'], 'id'));
        foreach (['users' => ['status' => 'inactive'], 'tenants' => ['status' => 'inactive']] as $table => $values) {
            app(TenantContext::class)->run($this->tenant, function () use ($table, $values): void {
                DB::beginTransaction();
                try {
                    DB::table($table)->update($values);
                    $this->assertSame([], $this->page()['items']);
                } finally {
                    DB::rollBack();
                }
            });
        }
    }

    public function test_discovery_and_gateway_share_precise_inclusive_start_exclusive_end(): void
    {
        $f = $this->browserInputs(['project.read']);
        $at = CarbonImmutable::now()->utc()->startOfSecond()->addMicroseconds(123456);
        $this->travelTo($at);
        foreach (['project_memberships', 'access_grants'] as $table) {
            app(TenantContext::class)->run($this->tenant, function () use ($table, $at): void {
                DB::table($table)->update(['valid_from' => $at->format('Y-m-d H:i:s.u'), 'valid_to' => $at->addMicrosecond()->format('Y-m-d H:i:s.u')]);
            });
        }
        $this->assertCount(1, $this->page()['items']);
        app(AccessGateway::class)->authorize($f['actor'], 'project.read', ['tenant_id' => $this->tenant, 'project_id' => $f['project']]);
        $this->travelTo($at->addMicrosecond());
        $this->assertSame([], $this->page()['items']);
        $this->travelBack();
    }

    public function test_query_shape_stays_constant_for_one_twenty_five_and_fifty_memberships(): void
    {
        $f = $this->browserInputs();
        $this->grant('project.read');
        $counts = [];
        $capabilityCounts = [];
        foreach ([1, 25, 50] as $size) {
            for ($i = ($size === 1 ? 1 : ($size === 25 ? 1 : 25)); $i < $size; $i++) {
                $this->project();
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->page();
            $counts[$size] = count(DB::getQueryLog());
            DB::flushQueryLog();
            app(BrowserProjectReader::class)->capabilities($f['actor'], $f['project']);
            $capabilityCounts[$size] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertSame($counts[1], $counts[25]);
        $this->assertSame($counts[1], $counts[50]);
        $this->assertSame($capabilityCounts[1], $capabilityCounts[25]);
        $this->assertSame($capabilityCounts[1], $capabilityCounts[50]);
    }

    private function page(?string $cursor = null): array
    {
        return app(BrowserProjectReader::class)->page(new TrustedActor($this->user, $this->tenant), $cursor);
    }
}
