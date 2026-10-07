<?php

namespace Tests\Feature;

use App\Domain\Qa\QaPlanService;
use Illuminate\Support\Facades\DB;
use Tests\Support\QaTestCase;

class QaSelectionTest extends QaTestCase
{
    public function test_overlapping_selection_writers_commit_one_event_and_one_change(): void
    {
        $this->qaInputs();
        $plan = $this->approvedPlan();
        $build = $this->qaBuild();
        $environment = $this->qaEnvironment();
        $this->contendQaOperation('selection', ['plan_version_id' => $plan['id'], 'build_id' => $build['record_id'], 'environment_version_id' => $environment['record_id'], 'expected_revision' => 0, 'reason' => 'Concurrent synthetic selection']);
        $this->qaRun(function () {
            $this->assertSame(1, DB::table('qa_selection_events')->where('project_id', $this->p)->count());
            $this->assertSame(1, DB::table('qa_changes')->where('project_id', $this->p)->where('kind', 'selection_changed')->count());
            $this->assertSame(1, DB::table('qa_project_state')->where('project_id', $this->p)->value('selection_revision'));
        });
    }

    public function test_selection_is_explicit_cas_and_keeps_history_when_build_imported(): void
    {
        $this->qaInputs();
        $plan = $this->approvedPlan();
        $build = $this->qaBuild();
        $environment = $this->qaEnvironment();
        $first = $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 0);
        $old = $this->currentSelection();
        $next = $this->qaBuild(2, 1);
        $this->assertSame($old, $this->currentSelection());
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => $this->selectPlan($plan['id'], $next['record_id'], $environment['record_id'], 0));
        $this->assertSame($before, $this->qaCounts());
        $second = $this->selectPlan($plan['id'], $next['record_id'], $environment['record_id'], 1);
        $this->assertSame(2, $second['revision']);
        $this->qaRun(function () use ($first) {
            $this->assertSame(2, DB::table('qa_selection_events')->count());
            $this->assertNotNull(DB::table('qa_selection_events')->find($first['id']));
        });
        $this->assertSame($second['id'], $this->currentSelection()['id']);
    }

    public function test_draft_revoked_plan_and_old_build_or_environment_cannot_be_selected(): void
    {
        $this->qaInputs();
        $plan = $this->composeEntries([$this->planEntry(0), $this->planEntry(1), $this->planEntry(2)]);
        $build = $this->qaBuild();
        $environment = $this->qaEnvironment();
        $this->assertQaConflict(fn () => $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 0));
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic approval']);
        $newBuild = $this->qaBuild(2, 1);
        $this->assertQaConflict(fn () => $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 0));
        $newEnv = $this->qaEnvironment(2, 1);
        $this->assertQaConflict(fn () => $this->selectPlan($plan['id'], $newBuild['record_id'], $environment['record_id'], 0));
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 1, 'decision' => 'revoked', 'reason' => 'Synthetic revocation']);
        $this->assertQaConflict(fn () => $this->selectPlan($plan['id'], $newBuild['record_id'], $newEnv['record_id'], 0));
        $this->assertNull($this->currentSelection());
    }

    public function test_original_entry_and_review_proofs_are_not_repaired_by_replacement_grants(): void
    {
        $this->qaInputs();
        $plan = $this->approvedPlan();
        $build = $this->qaBuild();
        $environment = $this->qaEnvironment();
        $this->revokeQaGrant('qa.plan.manage');
        $this->grant('qa.plan.manage', $this->p);
        $this->assertQaConflict(fn () => $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 0));
        $this->assertNull($this->currentSelection());
    }

    public function test_selection_revalidates_criterion_versions_and_does_not_increment_e03_generation(): void
    {
        $this->qaInputs();
        $generation = $this->generation;
        $plan = $this->approvedPlan();
        $build = $this->qaBuild();
        $environment = $this->qaEnvironment();
        $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 0);
        $this->qaRun(fn () => $this->assertSame($generation, DB::table('lifecycle_project_state')->where('project_id', $this->p)->value('generation')));
        $this->baseline($this->scope());
        $this->assertQaConflict(fn () => $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 1));
    }

    public function test_late_build_receipt_is_historical_and_cannot_replace_current_source_head(): void
    {
        $this->qaInputs();
        $plan = $this->approvedPlan();
        $build = $this->qaBuild(3);
        $environment = $this->qaEnvironment();
        $historical = $this->qaBuild(2, 1);
        $this->assertFalse($historical['current']);
        $this->assertSame(1, $historical['source_head_revision']);
        $this->assertQaConflict(fn () => $this->selectPlan($plan['id'], $historical['record_id'], $environment['record_id'], 0));
        $selected = $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 0);
        $this->assertSame($selected['id'], $this->currentSelection()['id']);
    }

    public function test_late_environment_receipt_retains_history_without_replacing_current_source_head(): void
    {
        $this->qaInputs();
        $plan = $this->approvedPlan();
        $build = $this->qaBuild();
        $current = $this->qaEnvironment(3);
        $historical = $this->qaEnvironment(2, 1);
        $this->assertFalse($historical['current']);
        $this->assertSame(1, $historical['source_head_revision']);
        $this->qaRun(function () use ($current, $historical) {
            $this->assertSame($current['receipt_id'], DB::table('qa_source_heads')->where('object_id', 'environment')->value('receipt_id'));
            $this->assertNotNull(DB::table('qa_environment_versions')->find($historical['record_id']));
        });
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => $this->selectPlan($plan['id'], $build['record_id'], $historical['record_id'], 0));
        $this->assertSame($before, $this->qaCounts());
        $selected = $this->selectPlan($plan['id'], $build['record_id'], $current['record_id'], 0);
        $this->assertSame($selected['id'], $this->currentSelection()['id']);
    }

    public function test_same_actor_new_source_proofs_do_not_repair_or_poison_other_plan_versions(): void
    {
        $this->qaInputs();
        $oldPlan = $this->approvedPlan();
        $this->revokeQaGrant('qa.source.read');
        $this->grant('qa.source.read', $this->p);
        $definition = $this->reviewedDefinition(key: 'new-test');
        $entries = [$this->planEntry(0, [['definition_version_id' => $definition['id'], 'source_instance_id' => $this->sourceId(), 'required' => true]], key: 'new-entry-0'), $this->planEntry(1, key: 'new-entry-1'), $this->planEntry(2, key: 'new-entry-2')];
        $plan = $this->composeEntries($entries, 'new-plan');
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'New original source proof']);
        $build = $this->qaBuild();
        $environment = $this->qaEnvironment();
        $this->assertQaConflict(fn () => $this->selectPlan($oldPlan['id'], $build['record_id'], $environment['record_id'], 0));
        $selected = $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 0);
        $this->assertSame($selected['id'], $this->currentSelection()['id']);
    }

    public function test_fresh_plan_decisions_replace_revoked_reviewer_authority_without_repairing_old_selection(): void
    {
        $this->qaInputs();
        $definition = $this->reviewedDefinition();
        $entries = [$this->planEntry(0, [['definition_version_id' => $definition['id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(1), $this->planEntry(2)];
        $plan = $this->composeEntries($entries);
        $reviewer = $this->distinctQaReviewer();
        $oldReview = app(QaPlanService::class)->review($reviewer, $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Distinct synthetic approval']);
        $otherPlan = $this->composeEntries($entries, 'other-plan');
        app(QaPlanService::class)->review($reviewer, $this->p, $otherPlan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Distinct synthetic approval to revoke']);
        $build = $this->qaBuild();
        $environment = $this->qaEnvironment();
        $oldSelection = $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 0);
        $old = $this->currentSelection();
        $this->assertSame($oldReview['id'], $old['plan_review_id']);
        $this->revokeDistinctQaReviewer($reviewer);
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 1));
        $this->assertSame($before, $this->qaCounts());
        $fresh = app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 1, 'decision' => 'approved', 'reason' => 'Fresh explicit synthetic approval']);
        $this->assertSame(2, $fresh['revision']);
        $this->assertNotSame($oldReview['id'], $fresh['id']);
        $this->assertSame($old, $this->currentSelection());
        $freshRevocation = app(QaPlanService::class)->review($this->actor(), $this->p, $otherPlan['id'], ['expected_revision' => 1, 'decision' => 'revoked', 'reason' => 'Fresh revocation after prior reviewer grant revocation']);
        $this->assertSame(2, $freshRevocation['revision']);
        $this->assertSame('revoked', $freshRevocation['state']);
        $graph = app(QaPlanService::class)->collectPins($this->actor(), $this->p, ['selection' => [$oldSelection['id']], 'review' => [$oldReview['id']]]);
        $this->assertQaConflict(fn () => $this->qaRun(fn ($proofs) => app(QaPlanService::class)->assertOriginalsLocked($graph, $proofs), $graph['requests']));
        $newSelection = $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 1);
        $this->assertSame($fresh['id'], $this->currentSelection()['plan_review_id']);
        $revoked = app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 2, 'decision' => 'revoked', 'reason' => 'Fresh explicit synthetic revocation']);
        $this->assertSame(3, $revoked['revision']);
        $before = $this->qaCounts();
        $this->assertQaConflict(fn () => $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 2));
        $this->assertSame($before, $this->qaCounts());
        $this->qaRun(fn () => $this->assertSame($oldReview['id'], DB::table('qa_selection_events')->find($oldSelection['id'])->plan_review_id));
        $this->assertSame($newSelection['id'], $this->currentSelection()['id']);
    }
}
