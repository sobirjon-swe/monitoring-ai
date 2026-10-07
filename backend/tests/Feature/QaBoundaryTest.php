<?php

namespace Tests\Feature;

use App\Domain\Lifecycle\BaselineService;
use App\Domain\Lifecycle\RequirementService;
use App\Domain\Qa\QaDefinitionService;
use App\Domain\Qa\QaFixtureService;
use App\Domain\Qa\QaPlanService;
use App\Domain\Qa\QaReadService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\QaTestCase;

class QaBoundaryTest extends QaTestCase
{
    public function test_e03_67_percent_and_candidate_failure_separate(): void
    {
        $this->qaInputs();
        $this->accept($this->evidence());
        [$body] = $this->selectedExecutionInputs();
        $critical = app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('definition', array_replace($this->definitionBody(0, 2), ['release_critical' => true]), 'test-a', 2), 'critical', 1);
        app(QaDefinitionService::class)->review($this->actor(), $this->p, $critical['record_id'], ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => 'Synthetic critical review']);
        $plan = $this->composeEntries([$this->planEntry(0, [['definition_version_id' => $critical['record_id'], 'source_instance_id' => $this->sourceId(), 'required' => true]], key: 'critical-entry'), $this->planEntry(1), $this->planEntry(2)], 'critical-compose');
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic critical plan']);
        $this->selectPlan($plan['id'], $body['build_id'], $body['environment_version_id'], 1);
        $body['definition_version_id'] = $critical['record_id'];
        $this->execution(array_replace($body, ['outcome' => 'failed']));
        $before = [$this->generation, $this->progress()['accepted_weight']];
        $this->assertTrue(class_exists(QaReadService::class), 'QA delivery must remain separate from acceptance.');
        $facts = app(QaReadService::class)->verification($this->actor(), $this->p);
        $this->assertSame(1, $facts['counts']['criteria'][$this->criteria[0]['id']]['failed']);
        $this->assertContains('release_critical_test_failed', $facts['critical_signals']['codes']);
        $this->assertSame([$before[0], 67], [$this->generation, $this->progress()['accepted_weight']]);
    }

    public function test_full_qa_admission_backlog_never_blocks_closed_hook_or_relocks_authority(): void
    {
        $this->qaInputs();
        foreach (range(1, 100) as $n) {
            $this->qaBuild($n, $n - 1);
        }
        $this->assertSame(100, app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_outbox')->where('project_id', $this->p)->where('is_catchup', false)->where('status', 'pending')->count()));
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(BaselineService::class)->close($this->actor(), $this->p, $this->generation, 'Synthetic full QA backlog closure');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $hook = array_find_key($queries, fn ($query) => str_starts_with($query['query'], 'insert into "qa_changes"'));
        $this->assertNotNull($hook);
        $later = array_slice($queries, $hook);
        $this->assertSame([], array_values(array_filter($later, fn ($query) => str_contains($query['query'], 'for update') || str_contains($query['query'], 'for share'))), 'The final QA outbox hook must not reopen authority/state locks.');
        $this->assertTrue(app(TenantContext::class)->run($this->tenant, fn () => DB::table('lifecycle_project_state')->where('project_id', $this->p)->value('is_closed')));
        $this->assertSame(1, app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_outbox')->where('project_id', $this->p)->where('is_catchup', true)->whereIn('status', ['pending', 'processing', 'retry'])->count()));
    }

    public function test_hook_backlog_and_lock_order_preserve_e03(): void
    {
        $this->qaInputs();
        $before = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_changes')->where('project_id', $this->p)->where('origin', 'lifecycle')->count());
        $data = $this->requirement();
        $data['criteria'][] = ['key' => 'C', 'text' => 'Synthetic C', 'required_test_keys' => ['test-c']];
        $data['criteria'][0]['text'] = 'Merge';
        app(RequirementService::class)->updateDraft($this->actor(), $this->p, $this->draft['id'], $data, 1);
        $approved = app(RequirementService::class)->approve($this->actor(), $this->p, $this->draft['id'], 2, $this->generation, 'Synthetic changed scope');
        $this->generation = $approved['generation'];
        $after = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_changes')->where('project_id', $this->p)->where('origin', 'lifecycle')->count());
        $this->assertSame($before + 1, $after, 'The exact changed old criterion must have a durable QA hook.');
    }

    public function test_closed_current_unavailable_and_history_retained(): void
    {
        $this->qaInputs();
        $build = $this->qaBuild();
        app(BaselineService::class)->close($this->actor(), $this->p, $this->generation, 'Synthetic close');
        $this->assertTrue(class_exists(QaReadService::class), 'Closed delivery boundary is required.');
        $facts = app(QaReadService::class)->verification($this->actor(), $this->p);
        $this->assertSame('unavailable', $facts['coverage_status']);
        $this->assertNull($facts['counts']['numerator']);
        $this->assertContains('project_closed', $facts['reason_codes']);
        $history = app(QaReadService::class)->history($this->actor(), $this->p, 'build', $build['record_id']);
        $this->assertCount(1, $history['items']);
    }
}
