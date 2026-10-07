<?php

namespace Tests\Feature;

use App\Domain\Lifecycle\RequirementService;
use App\Domain\Qa\QaDefinitionService;
use App\Domain\Qa\QaEligibility;
use App\Domain\Qa\QaExecutionService;
use App\Domain\Qa\QaFixtureService;
use App\Domain\Qa\QaPlanService;
use App\Domain\Qa\QaVerificationCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QaTestCase;

final class QaProjectionTest extends QaTestCase
{
    public function test_completed_counts_distinct_criteria_and_keeps_blocked_missing_and_critical_optional_failure(): void
    {
        $facts = app(QaVerificationCalculator::class)->calculate(['selection' => ['id' => 'selected'], 'coverage_status' => 'complete', 'reason_codes' => [], 'criteria' => [
            ['criterion_id' => 'A', 'included' => true, 'tests' => [['required' => true, 'status' => 'passed'], ['required' => false, 'status' => 'failed', 'release_critical' => true]]],
            ['criterion_id' => 'B', 'included' => true, 'tests' => [['required' => true, 'status' => 'blocked']]],
            ['criterion_id' => 'C', 'included' => true, 'tests' => []],
            ['criterion_id' => 'D', 'included' => false, 'exclusion_reason' => 'Explicit', 'tests' => []],
        ]]);
        $this->assertSame(1, $facts['counts']['numerator']);
        $this->assertSame(3, $facts['counts']['denominator']);
        $this->assertSame(3333, $facts['counts']['ratio_basis_points']);
        $this->assertTrue($facts['counts']['criteria']['A']['all_required_passed']);
        $this->assertFalse($facts['counts']['criteria']['B']['all_required_passed']);
        $this->assertFalse($facts['counts']['criteria']['C']['fully_executed']);
        $this->assertSame(1, $facts['counts']['criteria']['A']['optional_counts']['failed']);
        $this->assertSame(0, $facts['counts']['criteria']['A']['required_counts']['failed']);
        $this->assertContains('release_critical_test_failed', $facts['critical_signals']['codes']);
        $this->assertSame(1, $facts['counts']['excluded_criterion_count']);
        $this->assertArrayNotHasKey('release_accepted', $facts);
    }

    public function test_required_completeness_never_hides_missing_failed_blocked_or_zero_required(): void
    {
        foreach (['missing', 'failed', 'blocked', 'stale', 'unknown'] as $status) {
            $result = app(QaVerificationCalculator::class)->calculate(['selection' => ['id' => 'x'], 'criteria' => [['criterion_id' => 'A', 'included' => true, 'tests' => [['required' => true, 'status' => 'passed'], ['required' => true, 'status' => $status]]]]]);
            $this->assertFalse($result['counts']['criteria']['A']['all_required_passed']);
            $this->assertSame($status === 'failed', $result['counts']['criteria']['A']['fully_executed']);
        }
        $empty = app(QaVerificationCalculator::class)->calculate(['selection' => ['id' => 'x'], 'criteria' => [['criterion_id' => 'A', 'included' => true, 'tests' => [['required' => false, 'status' => 'passed']]]]]);
        $this->assertFalse($empty['counts']['criteria']['A']['all_required_passed']);
        $this->assertFalse($empty['counts']['criteria']['A']['fully_executed']);
    }

    public function test_no_plan_and_explicit_zero_scope_are_distinct_null_ratio_results(): void
    {
        $missing = app(QaVerificationCalculator::class)->calculate(['selection' => null]);
        $this->assertNull($missing['counts']['denominator']);
        $this->assertNull($missing['counts']['numerator']);
        $this->assertNull($missing['counts']['ratio_basis_points']);
        $this->assertContains('plan_missing', $missing['reason_codes']);
        $zero = app(QaVerificationCalculator::class)->calculate(['selection' => ['id' => 'x'], 'criteria' => []]);
        $this->assertSame(0, $zero['counts']['denominator']);
        $this->assertNull($zero['counts']['ratio_basis_points']);
    }

    public function test_latest_attempt_and_current_correction_win_without_historical_pass_fallback(): void
    {
        $this->qaInputs();
        [$body, $plan] = $this->selectedExecutionInputs();
        $first = $this->execution($body);
        $this->assertSame(1, $this->verification()['counts']['numerator']);
        $old = $this->qaRun(fn () => DB::table('qa_execution_versions')->find($first['record_id']));
        $blocked = array_replace($body, ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $old->attempt_id, 'outcome' => 'blocked', 'blocked_reason' => 'Synthetic blocker']);
        $second = $this->execution($blocked, object: 'second', seriesRevision: 1);
        $facts = $this->verification();
        $this->assertSame(0, $facts['counts']['numerator']);
        $this->assertSame(3, $facts['counts']['denominator']);
        $this->execution(array_replace($body, ['outcome' => 'failed']), revision: 2, sourceRevision: 1, seriesRevision: 2);
        $this->assertSame(0, $this->verification()['counts']['numerator']);
        unset($blocked['blocked_reason']);
        $this->execution(array_replace($blocked, ['outcome' => 'failed']), object: 'second', revision: 2, sourceRevision: 1, seriesRevision: 2);
        $this->assertSame(1, $this->verification()['counts']['numerator']);
        $this->assertCount(3, $plan['entries']);
    }

    public function test_keyed_invalid_latest_execution_proof_preserves_denominator_and_reports_partial(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $result = $this->execution($body);
        $eligible = $this->eligibilityInputs();
        $proofs = $eligible['proofs'];
        foreach ($eligible['graph']['requests'] as $request) {
            if (str_starts_with($request['key'], 'execution:'.$result['record_id'].':')) {
                $proofs[$request['key']] = ['valid' => false, 'reason_code' => 'access_revoked'];
            }
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $inspection = app(QaEligibility::class)->inspectLocked($this->p, $eligible['inputs'], $proofs);
        $this->assertSame([], DB::getQueryLog(), 'Eligibility must be pure over prefetched pins/proofs.');
        DB::disableQueryLog();
        $facts = app(QaVerificationCalculator::class)->calculate($inspection);
        $this->assertSame(0, $facts['counts']['numerator']);
        $this->assertSame(3, $facts['counts']['denominator']);
        $this->assertSame('partial', $facts['coverage_status']);
        $this->assertContains('access_revoked', $facts['reason_codes']);
        $overflow = array_map(fn () => ['valid' => false, 'reason_code' => 'coverage_limit'], $proofs);
        $unknown = app(QaVerificationCalculator::class)->calculate(app(QaEligibility::class)->inspectLocked($this->p, $eligible['inputs'], $overflow));
        $this->assertSame('unknown', $unknown['coverage_status']);
        $this->assertSame(3, $unknown['counts']['denominator']);
    }

    public function test_latest_source_gap_is_unknown_and_separate_source_series_is_not_merged(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body, revision: 3);
        $facts = $this->verification();
        $this->assertSame(0, $facts['counts']['numerator']);
        $this->assertContains('source_order_unknown', $facts['reason_codes']);
        $this->execution($body, revision: 1, sourceRevision: 1, seriesRevision: 1);
        $this->assertSame(0, $this->verification()['counts']['numerator']);
        $this->execution($body, revision: 2, sourceRevision: 1, seriesRevision: 1);
        $this->assertSame(1, $this->verification()['counts']['numerator']);
        $this->qaRun(function () use ($body) {
            $source = (string) Str::uuid();
            DB::table('source_instances')->insert(['id' => $source, 'tenant_id' => $this->tenant, 'kind' => 'manual', 'label' => 'Separate synthetic source']);
            $envelope = $this->qaEnvelope('execution', array_replace($body, ['outcome' => 'failed']), 'separate');
            $envelope['source_instance_id'] = $source;
            // The registration service needs its own open authority phase, so return the envelope only.
            $this->separateEnvelope = $envelope;
        });
        app(QaExecutionService::class)->register($this->actor(), $this->p, $this->separateEnvelope, 'separate', 0, 0);
        $facts = $this->verification();
        $this->assertSame(1, $facts['counts']['numerator']);
        $this->assertTrue($facts['counts']['criteria'][$this->criteria[0]['id']]['all_required_passed']);
    }

    private array $separateEnvelope;

    public function test_three_included_one_excluded_postgres_fixture_counts_criteria_not_attempts_or_pass_rate(): void
    {
        $this->qaInputs();
        $data = $this->requirement();
        $data['criteria'] = [['key' => 'D', 'text' => 'Explicitly excluded synthetic criterion', 'required_test_keys' => ['test-d']]];
        $draft = app(RequirementService::class)->createDraft($this->actor(), $this->p, $data);
        $approved = app(RequirementService::class)->approve($this->actor(), $this->p, $draft['id'], 1, $this->generation, 'Synthetic fourth criterion');
        $this->generation = $approved['generation'];
        $this->criteria[] = $approved['criteria'][0];
        $this->baseline($this->scope([40, 20, 20, 20]));
        [$body, $required] = $this->executionInputs();
        $optionalBody = $this->definitionBody(key: 'optional-critical');
        $optionalBody['release_critical'] = true;
        $optional = app(QaFixtureService::class)->register($this->actor(), $this->p, $this->qaEnvelope('definition', $optionalBody, 'optional-critical'), 'optional-critical', 0);
        app(QaDefinitionService::class)->review($this->actor(), $this->p, $optional['record_id'], ['expected_revision' => 0, 'decision' => 'reviewed', 'reason' => 'Critical optional synthetic test']);
        $blocked = $this->reviewedDefinition(1, 'test-b');
        $plan = $this->composeEntries([
            $this->planEntry(0, [['definition_version_id' => $required['id'], 'source_instance_id' => $this->sourceId(), 'required' => true], ['definition_version_id' => $optional['record_id'], 'source_instance_id' => $this->sourceId(), 'required' => false]]),
            $this->planEntry(1, [['definition_version_id' => $blocked['id'], 'source_instance_id' => $this->sourceId(), 'required' => true]]), $this->planEntry(2), $this->planEntry(3, included: false),
        ]);
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic approval']);
        $this->selectPlan($plan['id'], $body['build_id'], $body['environment_version_id'], 0);
        $first = $this->execution($body);
        $attempt = $this->qaRun(fn () => DB::table('qa_execution_versions')->find($first['record_id'])->attempt_id);
        $this->execution(array_replace($body, ['attempt_ordinal' => 2, 'predecessor_attempt_id' => $attempt]), object: 'retest', seriesRevision: 1);
        $this->execution(array_replace($body, ['definition_version_id' => $optional['record_id'], 'outcome' => 'failed']), object: 'optional-failure');
        $task = $this->qaTask(1);
        $this->execution(array_replace($body, ['definition_version_id' => $blocked['id'], 'task_version_id' => $task['version_id'], 'outcome' => 'blocked', 'blocked_reason' => 'Synthetic environment blocked']), object: 'blocked');
        $facts = $this->verification();
        $this->assertSame(1, $facts['counts']['numerator']);
        $this->assertSame(3, $facts['counts']['denominator']);
        $this->assertSame(3333, $facts['counts']['ratio_basis_points']);
        $this->assertSame(1, $facts['counts']['excluded_criterion_count']);
        $this->assertFalse($facts['counts']['criteria'][$this->criteria[1]['id']]['all_required_passed']);
        $this->assertContains('release_critical_test_failed', $facts['critical_signals']['codes']);
        $this->assertFalse($facts['critical_signals']['items'][0]['required']);
    }

    public function test_actual_authority_actor_overflow_is_unknown_with_the_full_approved_denominator(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body);
        $eligibility = app(QaEligibility::class);
        $graph = $eligibility->collectSelectionPins($this->actor(), $this->p);
        foreach (range(1, 101) as $number) {
            $graph['requests'][] = ['key' => 'extra:'.$number, 'actor_id' => (string) Str::uuid(), 'purposes' => ['qa.read'], 'required' => false];
        }
        $facts = $this->qaRun(function ($proofs) use ($graph, $eligibility) {
            $this->assertSame('coverage_limit', $proofs['extra:101']['reason_code']);

            return app(QaVerificationCalculator::class)->calculate($eligibility->inspectLocked($this->p, $eligibility->loadInputsLocked($this->p, $graph), $proofs));
        }, $graph['requests']);
        $this->assertSame('unknown', $facts['coverage_status']);
        $this->assertSame(3, $facts['counts']['denominator']);
        $this->assertSame(0, $facts['counts']['numerator']);
    }

    public function test_projection_ignores_extra_historical_graph_entries_outside_the_selected_plan(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body);
        $loaded = $this->eligibilityInputs();
        $extra = clone reset($loaded['inputs']['rows']['entry']);
        $extra->id = (string) Str::uuid();
        $extra->criterion_id = (string) Str::uuid();
        $loaded['inputs']['rows']['entry'][$extra->id] = $extra;
        $facts = app(QaVerificationCalculator::class)->calculate(app(QaEligibility::class)->inspectLocked($this->p, $loaded['inputs'], $loaded['proofs']));
        $this->assertSame(3, $facts['counts']['denominator']);
        $this->assertSame(1, $facts['counts']['numerator']);
        $this->assertSame('complete', $facts['coverage_status']);
    }

    public function test_definition_review_change_stales_only_affected_entry_without_dropping_scope(): void
    {
        $this->qaInputs();
        [$body] = $this->selectedExecutionInputs();
        $this->execution($body);
        app(QaDefinitionService::class)->review($this->actor(), $this->p, $body['definition_version_id'], ['expected_revision' => 1, 'decision' => 'reviewed', 'reason' => 'Explicit new review']);
        $facts = $this->verification();
        $this->assertSame(3, $facts['counts']['denominator']);
        $this->assertSame(0, $facts['counts']['numerator']);
        $this->assertSame('partial', $facts['coverage_status']);
        $this->assertContains('definition_review_changed', $facts['reason_codes']);
        $this->assertSame(['definition_missing'], $facts['counts']['criteria'][$this->criteria[1]['id']]['reason_codes']);
    }

    public function test_selection_original_plan_review_stays_pinned_after_a_new_review(): void
    {
        $this->qaInputs();
        [$body, $plan] = $this->selectedExecutionInputs();
        $selection = $this->currentSelection();
        $this->execution($body);
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 1, 'decision' => 'approved', 'reason' => 'New explicit review']);
        $graph = app(QaEligibility::class)->collectSelectionPins($this->actor(), $this->p);
        $this->assertContains($selection['plan_review_id'], $graph['refs']['review']);
        $this->assertContains('review:'.$selection['plan_review_id'].':0', array_column($graph['requests'], 'key'));
        $facts = $this->verification();
        $this->assertSame($selection['plan_review_id'], $facts['selection']['plan_review_id']);
        $this->assertSame(3, $facts['counts']['denominator']);
        $this->assertSame(0, $facts['counts']['numerator']);
        $this->assertContains('plan_review_changed', $facts['reason_codes']);
    }

    public function test_maximum_projection_prefetch_is_batched_for_one_hundred_criteria_two_thousand_current_attempts(): void
    {
        $definitions = $this->qaMaxSizeInputs();
        $entries = [];
        foreach ($definitions as $index => $tests) {
            $entries[] = $this->planEntry($index, $tests);
        }
        $this->completeSyntheticQaJobs();
        $plan = $this->composeEntries($entries, 'max-projection');
        app(QaPlanService::class)->review($this->actor(), $this->p, $plan['id'], ['expected_revision' => 0, 'decision' => 'approved', 'reason' => 'Synthetic maximum approval']);
        $build = $this->qaBuild();
        $environment = $this->qaEnvironment();
        $this->selectPlan($plan['id'], $build['record_id'], $environment['record_id'], 0);
        $this->seedMaximumExecutions($definitions, $build['record_id'], $environment['record_id']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $facts = $this->verification();
            $queries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame(100, $facts['counts']['denominator']);
        $this->assertSame(100, $facts['counts']['numerator']);
        $this->assertSame(10000, $facts['counts']['ratio_basis_points']);
        $this->assertSame(100, $facts['counts']['fully_executed_criterion_count']);
        $this->assertSame('complete', $facts['coverage_status']);
        $this->assertCount(100, $facts['counts']['criteria']);
        $this->assertLessThanOrEqual(100, $queries, 'Projection SQL must be bounded by table categories, not per definition or attempt.');
        fwrite(STDOUT, 'QA_PROJECTION_MAX queries='.$queries." definitions=2000 criteria=100 attempts=2000\n");
    }
}
