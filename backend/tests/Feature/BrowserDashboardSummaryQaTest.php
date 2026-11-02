<?php

namespace Tests\Feature;

use App\Domain\Access\AccessGateway;
use App\Domain\Ba\BaReadService;
use App\Domain\Browser\DashboardSummaryReader;
use App\Domain\Lifecycle\TaskService;
use App\Domain\Qa\QaHandoffService;
use App\Support\ReadDeliveryFence;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\QaTestCase;

class BrowserDashboardSummaryQaTest extends QaTestCase
{
    public function test_original_qa_proof_expiry_during_later_ba_read_withholds_earlier_handoff_total(): void
    {
        $this->qaInputs();
        foreach (['project.read', 'progress.read', 'ba.read', 'ba.source.read'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
        [$body] = $this->executionInputs();
        $deadline = app(TenantContext::class)->run($this->tenant, function () {
            $deadline = DB::scalar("select clock_timestamp() + interval '10 seconds'");
            DB::table('access_grants')->where('purpose', 'qa.handoff.manage')->where('project_id', $this->p)->update(['valid_to' => $deadline]);

            return $deadline;
        });
        app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'dashboard-expiry');
        app()->instance(BaReadService::class, new class($deadline)
        {
            public function __construct(private string $deadline) {}

            public function queue(...$args): array
            {
                // Wait on the exact saved producer deadline, not an assumed machine speed.
                DB::select("select pg_sleep(greatest(0, extract(epoch from (?::timestamptz + interval '10 milliseconds' - clock_timestamp()))))", [$this->deadline]);

                return ['items' => [], 'next_cursor' => null, 'coverage_status' => 'complete', 'reason_codes' => []];
            }
        });
        $result = app(DashboardSummaryReader::class)->read($this->actor(), $this->p);
        $this->assertSame('unknown', $result['dependencies']['qa']['coverage_status']);
        $this->assertNull($result['dependencies']['qa']['counts']);
        $this->assertSame(['original_authority_expired'], $result['dependencies']['qa']['reason_codes']);
        $this->assertSame(['open' => 0, 'total' => 0], $result['dependencies']['ba']['counts']);
        app(AccessGateway::class)->authorize($this->actor(), 'qa.read', ['tenant_id' => $this->tenant, 'project_id' => $this->p]);
        $this->assertFalse(app()->bound(ReadDeliveryFence::class));
    }

    public function test_qa_execution_and_pass_counts_keep_native_criterion_denominator(): void
    {
        $this->qaInputs();
        foreach (['project.read', 'progress.read'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body);
        $native = $this->verification();
        $result = app(DashboardSummaryReader::class)->read($this->actor(), $this->p)['qa'];
        $this->assertSame('complete', $result['coverage_status']);
        $this->assertSame($native['counts']['denominator'], $result['counts']['required_criteria']);
        $this->assertSame($native['counts']['numerator'], $result['counts']['executed_criteria']);
        $this->assertSame(1, $result['counts']['all_required_passed_criteria']);
    }

    public function test_handoff_state_and_original_authority_are_rechecked_by_native_reader(): void
    {
        $this->qaInputs();
        foreach (['project.read', 'progress.read'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
        [$body] = $this->executionInputs();
        $this->handoffId = app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'dashboard-handoff')['id'];
        $reader = app(DashboardSummaryReader::class);
        $result = $reader->read($this->actor(), $this->p);
        $this->assertSame(['open' => 1, 'total' => 1], $result['dependencies']['qa']['counts']);
        $this->handoffTransition(1, 'acknowledged');
        $this->assertSame(1, $reader->read($this->actor(), $this->p)['dependencies']['qa']['counts']['open']);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'qa.handoff.manage')->where('project_id', $this->p)->update(['revoked_at' => now()]));
        $revoked = $reader->read($this->actor(), $this->p)['dependencies']['qa'];
        $this->assertSame('unknown', $revoked['coverage_status']);
        $this->assertNull($revoked['counts']);
    }

    public function test_current_task_revision_makes_old_handoff_unknown_instead_of_open(): void
    {
        $this->qaInputs();
        foreach (['project.read', 'progress.read'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
        [$body] = $this->executionInputs();
        app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'dashboard-stale');
        $task = app(TaskService::class)->listing($this->actor(), $this->p)[0];
        $data = ['title' => 'Revised synthetic task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic', 'remaining_effort' => 0, 'expected_test_keys' => ['t'], 'status' => 'done', 'criterion_links' => []];
        $generation = app(TenantContext::class)->run($this->tenant, fn () => DB::table('lifecycle_project_state')->where('project_id', $this->p)->value('generation'));
        app(TaskService::class)->revise($this->actor(), $this->p, $task['id'], $data, $task['revision'], $generation);
        $result = app(DashboardSummaryReader::class)->read($this->actor(), $this->p)['dependencies']['qa'];
        $this->assertSame('unknown', $result['coverage_status']);
        $this->assertNull($result['counts']);
    }
}
