<?php

namespace Tests\Feature;

use App\Domain\DeveloperAnalysis\AnalysisInvalidation;
use App\Domain\DeveloperAnalysis\AnalysisProcessor;
use App\Domain\DeveloperAnalysis\AnalysisReadService;
use App\Domain\DeveloperAnalysis\SyntheticMrRegistry;
use App\Domain\Lifecycle\BaselineService;
use App\Domain\Lifecycle\DodPolicyService;
use App\Domain\Lifecycle\RequirementService;
use App\Domain\Lifecycle\TaskService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\DeveloperAnalysisTestCase;
use Tests\Support\DeveloperProcessorFixtures;

class DeveloperInvalidationTest extends DeveloperAnalysisTestCase
{
    use DeveloperProcessorFixtures;

    public function test_newer_blocked_receipt_synchronously_invalidates_run_without_advancing_source_head(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $fixture = $this->mr();
        $fixture['revision_ordinal'] = 2;
        $fixture['diff'] = str_replace('+new', '+password=synthetic-secret', $fixture['diff']);
        $receipt = app(SyntheticMrRegistry::class)->register($this->actor(), $project, $fixture, 1);
        $this->assertFalse($receipt['accepted']);
        $this->scoped(function () use ($run): void {
            $this->assertSame($run['snapshot_id'], DB::table('developer_source_heads')->value('snapshot_id'));
            $this->assertSame('stale', DB::table('developer_runs')->where('id', $run['id'])->value('applicability'));
            $this->assertSame(1, DB::table('developer_invalidations')->where('run_id', $run['id'])->count());
            $this->assertSame(1, DB::table('developer_outbox')->whereNotNull('invalidation_id')->count());
        });
    }

    public function test_denied_read_records_sticky_revocation_without_returning_facts(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $this->runner(fn ($input) => $this->fixtureOutput($input));
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->scoped(fn () => DB::table('access_grants')->where('purpose', 'analysis.request')->update(['revoked_at' => now()]));
        try {
            app(AnalysisReadService::class)->run($this->actor(), $run['id']);
            $this->fail('Revoked requester content was returned.');
        } catch (AccessDeniedHttpException) {
            $this->scoped(function () use ($run): void {
                $this->assertSame('stale', DB::table('developer_runs')->where('id', $run['id'])->value('applicability'));
                $this->assertSame('succeeded', DB::table('developer_runs')->where('id', $run['id'])->value('status'));
            });
        }
        $this->grant('analysis.request', $project);
        app(AnalysisInvalidation::class)->refresh($this->actor(), $project);
        $this->scoped(fn () => $this->assertSame(1, DB::table('developer_invalidations')->where('run_id', $run['id'])->count()));
    }

    public function test_source_change_during_compute_cancels_publication_and_preserves_distinct_events(): void
    {
        $project = $this->developerProject();
        $run = $this->queuedRun($project);
        $this->runner(function ($input) use ($project) {
            $fixture = $this->mr();
            $fixture['revision_ordinal'] = 2;
            $fixture['head_sha'] = str_repeat('c', 40);
            app(SyntheticMrRegistry::class)->register($this->actor(), $project, $fixture, 1);

            return $this->fixtureOutput($input);
        });
        app(AnalysisProcessor::class)->drain($this->actor(), $project, 1);
        $this->scoped(function () use ($run): void {
            $this->assertSame('cancelled', DB::table('developer_runs')->where('id', $run['id'])->value('status'));
            $this->assertSame(0, DB::table('developer_results')->count());
            $this->assertSame(2, DB::table('developer_source_events')->count());
        });
    }

    public function test_task_revision_synchronously_invalidates_exact_pin_without_extra_e03_generation(): void
    {
        $project = $this->developerProject();
        $this->grant('requirements.manage', $project);
        $data = ['title' => 'Synthetic task', 'assignee_id' => $this->employee, 'estimate_basis' => 'Synthetic units', 'remaining_effort' => 5, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => []];
        $tasks = app(TaskService::class);
        $task = $tasks->create($this->actor(), $project, $data, 0);
        $id = $this->queuedWithPins($project, ['criterion_version_ids' => [], 'task_version_ids' => [$task['version_id']]]);
        $data['status'] = 'in_progress';
        $tasks->revise($this->actor(), $project, $task['id'], $data, 1, 1);
        $this->scoped(function () use ($id, $project): void {
            $this->assertSame('stale', DB::table('developer_runs')->where('id', $id)->value('applicability'));
            $this->assertSame('pins_changed', DB::table('developer_invalidations')->where('run_id', $id)->value('reason_code'));
            $this->assertSame(2, DB::table('lifecycle_project_state')->where('project_id', $project)->value('generation'));
            $this->assertSame(0, DB::table('lifecycle_changes')->where('project_id', $project)->count());
        });
    }

    public function test_requirement_approval_synchronously_invalidates_replaced_pin(): void
    {
        $project = $this->developerProject();
        foreach (['requirements.manage', 'requirements.approve'] as $purpose) {
            $this->grant($purpose, $project);
        }
        $data = ['title' => 'Synthetic requirement', 'owner_id' => $this->employee, 'stakeholder' => 'Synthetic stakeholder', 'business_outcome' => 'Synthetic outcome', 'nfr_constraints' => ['Synthetic NFR'], 'dependencies' => [], 'criteria' => [['key' => 'A', 'text' => 'Check A', 'required_test_keys' => ['test-a']]]];
        $requirements = app(RequirementService::class);
        $draft = $requirements->createDraft($this->actor(), $project, $data);
        $approved = $requirements->approve($this->actor(), $project, $draft['id'], 1, 0, 'Synthetic approval');
        $id = $this->queuedWithPins($project, ['criterion_version_ids' => [$approved['criteria'][0]['version_id']], 'task_version_ids' => []]);
        $data['criteria'][0]['text'] = 'Changed';
        $requirements->updateDraft($this->actor(), $project, $draft['id'], $data, 1);
        $requirements->approve($this->actor(), $project, $draft['id'], 2, 1, 'Synthetic revision');
        $this->scoped(function () use ($id, $project): void {
            $this->assertSame('stale', DB::table('developer_runs')->where('id', $id)->value('applicability'));
            $this->assertSame(2, DB::table('lifecycle_project_state')->where('project_id', $project)->value('generation'));
            $this->assertSame(1, DB::table('lifecycle_changes')->where('project_id', $project)->count());
        });
    }

    public function test_actual_baseline_close_invalidates_synchronously_and_reopen_cannot_rehabilitate_run(): void
    {
        $project = $this->developerProject();
        foreach (['requirements.manage', 'requirements.approve', 'policy.approve', 'baseline.approve', 'progress.read', 'ingestion.read'] as $purpose) {
            $this->grant($purpose, $project);
        }
        $data = ['title' => 'Synthetic requirement', 'owner_id' => $this->employee, 'stakeholder' => 'Synthetic stakeholder', 'business_outcome' => 'Synthetic outcome', 'nfr_constraints' => ['Synthetic NFR'], 'dependencies' => [], 'criteria' => [['key' => 'A', 'text' => 'Check A', 'required_test_keys' => ['test-a']]]];
        $requirements = app(RequirementService::class);
        $draft = $requirements->createDraft($this->actor(), $project, $data);
        $approved = $requirements->approve($this->actor(), $project, $draft['id'], 1, 0, 'Synthetic approval');
        $policy = app(DodPolicyService::class)->approve($this->actor(), $project, ['fictional' => true, 'mandatory_qa' => true, 'human_acceptance' => true], 1)['id'];
        $scope = [['criterion_version_id' => $approved['criteria'][0]['version_id'], 'weight' => 100]];
        $baselines = app(BaselineService::class);
        $baseline = $baselines->approve($this->actor(), $project, $scope, $policy, null, 2, 'Synthetic baseline');
        $id = $this->queuedWithPins($project, ['criterion_version_ids' => [], 'task_version_ids' => []]);
        $baselines->close($this->actor(), $project, 3, 'Synthetic closure');
        $this->scoped(function () use ($id): void {
            $this->assertSame('stale', DB::table('developer_runs')->where('id', $id)->value('applicability'));
            $this->assertSame('project_closed', DB::table('developer_invalidations')->where('run_id', $id)->value('reason_code'));
        });
        $baselines->approve($this->actor(), $project, $scope, $policy, $baseline['id'], 4, 'Synthetic reopen', true);
        $this->assertSame('stale', app(AnalysisReadService::class)->run($this->actor(), $id)['applicability']);
    }

    public function test_denied_delivery_queries_do_not_grow_with_other_run_history(): void
    {
        $project = $this->developerProject();
        $first = $this->queuedRun($project);
        $this->scoped(fn () => DB::table('access_grants')->where('purpose', 'analysis.request')->update(['revoked_at' => now()]));
        $measure = function ($id): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                app(AnalysisReadService::class)->run($this->actor(), $id);
                $this->fail('Revoked content was returned.');
            } catch (AccessDeniedHttpException) {
                $this->assertTrue(true);
            } finally {
                $queries = count(DB::getQueryLog());
                DB::disableQueryLog();
            }

            return $queries;
        };
        $one = $measure($first['id']);
        $this->scoped(fn () => DB::table('access_grants')->where('purpose', 'analysis.request')->update(['revoked_at' => null]));
        for ($i = 0; $i < 30; $i++) {
            $last = $this->queuedRun($project);
        }
        $this->scoped(fn () => DB::table('access_grants')->where('purpose', 'analysis.request')->update(['revoked_at' => now()]));
        $many = $measure($last['id']);
        $this->assertSame($one, $many);
        $this->assertLessThanOrEqual(70, $many); // Measured 67 including nested transaction/context boundaries.
        $this->scoped(fn () => $this->assertSame(2, DB::table('developer_invalidations')->count()));
    }
}
