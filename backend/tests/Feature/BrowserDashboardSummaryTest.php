<?php

namespace Tests\Feature;

use App\Domain\Access\TrustedActor;
use App\Domain\Browser\BrowserProjectReader;
use App\Domain\Browser\DashboardSummaryReader;
use App\Domain\Lifecycle\TaskService;
use App\Domain\Qa\QaReadService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\BrowserTestCase;

class BrowserDashboardSummaryTest extends BrowserTestCase
{
    public function test_only_pinned_demo_scope_is_attested_synthetic(): void
    {
        $this->tenant = 'f1200000-0000-4000-8000-000000000001';
        $this->fixture();
        $project = 'f1200000-0000-4000-8000-000000000010';
        app(TenantContext::class)->run($this->tenant, function () use ($project) {
            DB::table('projects')->insert(['id' => $project, 'tenant_id' => $this->tenant, 'name' => 'Name is not provenance']);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $project, 'employee_id' => $this->employee, 'valid_from' => now()->subDay()]);
        });
        foreach (['project.read', 'progress.read'] as $purpose) {
            $this->grant($purpose, $project);
        }
        $this->assertTrue(app(DashboardSummaryReader::class)->read(new TrustedActor($this->user, $this->tenant), $project)['synthetic']);
        $this->assertTrue(app(BrowserProjectReader::class)->capabilities(new TrustedActor($this->user, $this->tenant), $project)['synthetic']);
        $other = $this->project();
        foreach (['project.read', 'progress.read'] as $purpose) {
            $this->grant($purpose, $other);
        }
        $this->assertNull(app(DashboardSummaryReader::class)->read(new TrustedActor($this->user, $this->tenant), $other)['synthetic']);
        $this->assertNull(app(BrowserProjectReader::class)->capabilities(new TrustedActor($this->user, $this->tenant), $other)['synthetic']);
    }

    public function test_cross_project_and_cross_tenant_authority_is_denied(): void
    {
        $f = $this->browserInputs(['project.read', 'progress.read']);
        $other = $this->project();
        foreach ([[$f['actor'], $other], [new TrustedActor($f['actor']->id, '00000000-0000-4000-8000-000000000001'), $f['project']]] as [$actor, $project]) {
            try {
                app(DashboardSummaryReader::class)->read($actor, $project);
                $this->fail('Scope accepted.');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_missing_block_permissions_are_unknown_not_zero_and_overview_requires_both_purposes(): void
    {
        $f = $this->browserInputs(['project.read', 'progress.read']);
        $result = app(DashboardSummaryReader::class)->read($f['actor'], $f['project']);
        foreach ([$result['tasks'], $result['qa'], ...array_values($result['dependencies'])] as $block) {
            $this->assertSame('unavailable', $block['coverage_status']);
            $this->assertNull($block['counts']);
            $this->assertSame(['permission_unavailable'], $block['reason_codes']);
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'progress.read')->update(['revoked_at' => now()]));
        $this->expectException(AccessDeniedHttpException::class);
        app(DashboardSummaryReader::class)->read($f['actor'], $f['project']);
    }

    public function test_native_current_versions_count_once_and_complete_empty_is_zero(): void
    {
        $f = $this->browserInputs(['project.read', 'progress.read', 'requirements.read', 'requirements.manage', 'qa.read', 'qa.source.read', 'ba.read', 'ba.source.read']);
        $reader = app(DashboardSummaryReader::class);
        $empty = $reader->read($f['actor'], $f['project']);
        $this->assertSame(0, $empty['tasks']['counts']['total']);
        $this->assertSame(['open' => 0, 'total' => 0], $empty['dependencies']['qa']['counts']);
        $this->assertSame(['open' => 0, 'total' => 0], $empty['dependencies']['ba']['counts']);
        $this->assertNull($empty['qa']['counts']);
        $data = ['title' => 'Synthetic task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic', 'remaining_effort' => 1, 'expected_test_keys' => ['t'], 'status' => 'blocked', 'criterion_links' => []];
        $task = app(TaskService::class)->create($f['actor'], $f['project'], $data, 0);
        app(TaskService::class)->revise($f['actor'], $f['project'], $task['id'], [...$data, 'status' => 'done'], 1, 1);
        $result = $reader->read($f['actor'], $f['project']);
        $this->assertSame(1, $result['tasks']['counts']['total']);
        $this->assertSame(1, $result['tasks']['counts']['done']);
        $this->assertSame(0, $result['tasks']['counts']['blocked']);
        $this->assertNull($result['synthetic']);
        $this->loginBrowser();
        $this->getJson('/browser/v1/projects/'.$f['project'].'/dashboard-summary')->assertOk()->assertJsonPath('data.version', 'dashboard-summary-v1');
        $this->getJson('/browser/v1/projects/'.$f['project'].'/dashboard-summary?limit=1')->assertUnprocessable();
    }

    public function test_dependency_totals_require_all_pages_and_discard_partial_counts_on_overflow(): void
    {
        $f = $this->browserInputs(['project.read', 'progress.read', 'qa.read', 'qa.source.read']);
        $pages = new class
        {
            public int $calls = 0;

            public int $pages = 2;

            public bool $unknownLast = false;

            public ?\Closure $duringQueue = null;

            public function verification(...$args): array
            {
                return ['coverage_status' => 'unknown', 'counts' => [], 'reason_codes' => ['plan_missing']];
            }

            public function queue($actor, $project, $limit, $cursor): array
            {
                $this->calls++;
                if ($this->duringQueue !== null) {
                    ($this->duringQueue)();
                }
                $items = [];
                for ($i = 0; $i < 50; $i++) {
                    $items[] = ['id' => $this->calls.':'.$i, 'applicability' => 'current'];
                }

                return ['items' => $items, 'next_cursor' => $this->calls < $this->pages ? 'page:'.$this->calls : null, 'coverage_status' => $this->unknownLast && $this->calls === $this->pages ? 'unknown' : 'complete', 'reason_codes' => $this->unknownLast ? ['coverage_limit'] : []];
            }
        };
        app()->instance(QaReadService::class, $pages);
        $reader = app(DashboardSummaryReader::class);
        $this->assertSame(['open' => 100, 'total' => 100], $reader->read($f['actor'], $f['project'])['dependencies']['qa']['counts']);
        $grant = $this->grant('requirements.read', $f['project']);
        $pages->calls = 0;
        $pages->duringQueue = fn () => app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
        $this->assertNull($reader->read($f['actor'], $f['project'])['tasks']['counts']);
        $pages->duringQueue = null;
        $pages->calls = 0;
        $pages->unknownLast = true;
        $this->assertNull($reader->read($f['actor'], $f['project'])['dependencies']['qa']['counts']);
        $pages->calls = 0;
        $pages->unknownLast = false;
        $pages->pages = 22;
        $overflow = $reader->read($f['actor'], $f['project'])['dependencies']['qa'];
        $this->assertNull($overflow['counts']);
        $this->assertSame(['coverage_limit'], $overflow['reason_codes']);
        $this->assertSame(21, $pages->calls);
    }
}
