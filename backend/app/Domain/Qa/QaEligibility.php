<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class QaEligibility
{
    /** Scoped relationship hints only. Every consumed original proof is collected before coordination. */
    public function collectSelectionPins(TrustedActor $actor, string $project): array
    {
        return app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project) {
            $selectionId = DB::table('qa_project_state')->where('project_id', $project)->value('current_selection_id');
            if (! $selectionId) {
                return ['refs' => [], 'requests' => [], 'source_ids' => [], 'selection_id' => null, 'input_hash' => hash('sha256', 'no-selection')];
            }
            $selection = DB::table('qa_selection_events')->where('project_id', $project)->find($selectionId);
            if (! $selection) {
                throw new ConflictHttpException;
            }
            $refs = ['selection' => [$selectionId], 'plan' => [$selection->plan_id], 'build' => [$selection->build_id], 'environment' => [$selection->environment_id], 'review' => [$selection->plan_review_id]];
            $series = $this->selectedSeries($project, $selection);
            $attempts = DB::table('qa_execution_attempts')->where('project_id', $project)->whereIn('id', $series->pluck('current_attempt_id')->filter()->all())->get();
            $refs['execution'] = $attempts->pluck('current_version_id')->filter()->all();
            $origins = DB::table('qa_execution_attempts')->where('project_id', $project)->whereIn('id', array_values(array_unique(array_filter([...$attempts->pluck('predecessor_id')->all(), ...$attempts->pluck('retests_execution_id')->all()]))))->get();
            $refs['execution'] = array_values(array_unique([...$refs['execution'], ...$origins->pluck('current_version_id')->filter()->all()]));
            $refs['defect_event'] = $attempts->pluck('fix_request_event_id')->filter()->unique()->values()->all();
            $refs['defect'] = DB::table('qa_defect_events')->where('project_id', $project)->whereIn('id', $refs['defect_event'])->pluck('defect_id')->unique()->values()->all();
            $refs['receipt'] = DB::table('qa_execution_versions')->where('project_id', $project)->whereIn('id', $refs['execution'])->pluck('receipt_id')->unique()->values()->all();
            $refs['receipt'] = array_values(array_unique([...$refs['receipt'], ...DB::table('qa_defects')->where('project_id', $project)->whereIn('id', $refs['defect'])->pluck('receipt_id')->all()]));
            $graph = app(QaPlanService::class)->collectPins($actor, $project, $refs);

            return $graph + ['selection_id' => $selectionId];
        });
    }

    /** No authority locks are acquired here; callers supply the already collected graph. */
    public function loadInputsLocked(string $project, array $graph): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.qa_authority_closed',true),'')") !== '1') {
            throw new LogicException('QA eligibility inputs require closed coordination.');
        }
        $state = DB::table('qa_project_state')->where('project_id', $project)->first();
        $lifecycle = DB::table('lifecycle_project_state')->where('project_id', $project)->first();
        $rows = app(QaPlanService::class)->loadGraphLocked($project, $graph);
        $selection = $rows['selection'][$graph['selection_id'] ?? ''] ?? null;
        $selectedEntryIds = $selection ? DB::table('qa_plan_entries')->where('project_id', $project)->where('plan_id', $selection->plan_id)->orderBy('entry_id')->limit(101)->pluck('entry_id')->all() : [];
        $series = $selection ? $this->selectedSeries($project, $selection) : collect();
        $versions = $rows['execution'] ?? [];
        $attemptIds = array_values(array_unique(array_filter([...$series->pluck('current_attempt_id')->all(), ...array_map(fn ($e) => $e->attempt_id, $versions)])));
        $attempts = DB::table('qa_execution_attempts')->where('project_id', $project)->whereIn('id', $attemptIds)->get();
        $originIds = array_values(array_unique(array_filter([...$attempts->pluck('predecessor_id')->all(), ...$attempts->pluck('retests_execution_id')->all()])));
        $origins = DB::table('qa_execution_attempts')->where('project_id', $project)->whereIn('id', $originIds)->get();
        $attempts = $attempts->concat($origins)->keyBy('id');
        $attemptCounts = DB::table('qa_execution_attempts')->where('project_id', $project)->whereIn('series_id', $series->pluck('id')->all())->groupBy('series_id')->selectRaw('series_id,count(*) as count,min(ordinal) as min,max(ordinal) as max')->get()->keyBy('series_id');
        $versionCounts = DB::table('qa_execution_versions')->where('project_id', $project)->whereIn('attempt_id', $attemptIds)->groupBy('attempt_id')->selectRaw('attempt_id,count(*) as count,min(source_revision) as min,max(source_revision) as max')->get()->keyBy('attempt_id');
        $definitions = $rows['definition'] ?? [];
        $definitionHeads = DB::table('qa_definition_identities')->where('project_id', $project)->whereIn('id', array_map(fn ($d) => $d->test_identity_id, $definitions))->get()->keyBy('id')->all();
        $sourceHeads = DB::table('qa_source_heads')->where('project_id', $project)->whereIn('receipt_id', array_keys($rows['receipt'] ?? []))->get()->keyBy('receipt_id')->all();
        $currentCriteria = DB::table('requirement_version_criteria as link')->join('requirement_versions as v', 'v.id', '=', 'link.requirement_version_id')->where('link.project_id', $project)->whereIn('link.criterion_version_id', array_keys($rows['criterion_version'] ?? []))->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('requirement_versions as newer')->whereColumn('newer.tenant_id', 'v.tenant_id')->whereColumn('newer.project_id', 'v.project_id')->whereColumn('newer.requirement_id', 'v.requirement_id')->whereColumn('newer.version', '>', 'v.version');
        })->distinct()->pluck('link.criterion_version_id')->all();

        return ['project_id' => $project, 'graph' => $graph, 'rows' => $rows, 'state' => $state, 'current_baseline_id' => $lifecycle?->current_baseline_id, 'selection' => $selection, 'selected_entry_ids' => $selectedEntryIds, 'series' => $series->keyBy('id')->all(), 'attempts' => $attempts->all(), 'attempt_counts' => $attemptCounts->all(), 'version_counts' => $versionCounts->all(), 'definition_heads' => $definitionHeads, 'source_heads' => $sourceHeads, 'current_criterion_version_ids' => $currentCriteria];
    }

    /** Pure evaluation: no queries, mutation, source calls or authority reacquisition. */
    public function inspectLocked(string $project, array $inputs, array $proofResults): array
    {
        $rows = $inputs['rows'] ?? [];
        $selection = $inputs['selection'] ?? null;
        $result = ['selection' => $selection ? array_intersect_key((array) $selection, array_flip(['id', 'revision', 'plan_id', 'plan_review_id', 'build_id', 'environment_id'])) : null, 'criteria' => [], 'coverage_status' => 'complete', 'reason_codes' => [], 'omitted_count' => 0];
        if (! $selection) {
            return array_replace($result, ['coverage_status' => 'unknown', 'reason_codes' => ['plan_missing']]);
        }
        $global = [];
        if (($inputs['project_id'] ?? null) !== $project || $selection->project_id !== $project || ($inputs['state']->current_selection_id ?? null) !== $selection->id) {
            $global[] = 'selection_changed';
        }
        $plan = $rows['plan'][$selection->plan_id] ?? null;
        $review = $rows['review'][$selection->plan_review_id] ?? null;
        if (! $plan || $plan->review_state !== 'approved' || $plan->current_review_id !== $selection->plan_review_id || ! $review || $review->plan_id !== $plan->id || $review->decision !== 'approved' || $review->subject_hash !== $plan->content_hash) {
            $global[] = 'plan_review_changed';
        }
        if (! $plan || $plan->baseline_id !== ($inputs['current_baseline_id'] ?? null)) {
            $global[] = 'baseline_changed';
        }
        foreach ([['selection', $selection->id], ['plan', $selection->plan_id], ['review', $selection->plan_review_id], ['build', $selection->build_id], ['environment', $selection->environment_id]] as [$kind, $id]) {
            $global = [...$global, ...$this->proofReasons($kind, $id, $inputs, $proofResults)];
        }
        foreach ([['build', $selection->build_id], ['environment', $selection->environment_id]] as [$kind, $id]) {
            $row = $rows[$kind][$id] ?? null;
            if (! $row || ! isset($inputs['source_heads'][$row->receipt_id])) {
                $global[] = 'source_version_changed';
            }
            if ($row) {
                $global = [...$global, ...$this->proofReasons('receipt', $row->receipt_id, $inputs, $proofResults)];
            }
        }
        $selectedSeries = [];
        foreach ($inputs['series'] ?? [] as $series) {
            $selectedSeries[$series->source_instance_id.':'.$series->definition_id] = $series;
        }
        if (count($rows['entry'] ?? []) > 100 || count($rows['definition'] ?? []) > 2000) {
            $global[] = 'coverage_limit';
        }
        $entryIds = $inputs['selected_entry_ids'] ?? array_keys($rows['entry'] ?? []);
        if (! isset($inputs['selected_entry_ids']) || array_diff($entryIds, array_keys($rows['entry'] ?? []))) {
            $global[] = 'plan_entries_unknown';
        }
        foreach (array_intersect_key($rows['entry'] ?? [], array_fill_keys($entryIds, true)) as $entry) {
            $reasons = [...$global, ...$this->proofReasons('entry', $entry->id, $inputs, $proofResults)];
            if (! in_array($entry->criterion_version_id, $inputs['current_criterion_version_ids'] ?? [], true)) {
                $reasons[] = 'criterion_version_changed';
            }
            $criterion = ['criterion_id' => $entry->criterion_id, 'criterion_version_id' => $entry->criterion_version_id, 'entry_snapshot_id' => $entry->id, 'included' => $entry->included, 'exclusion_reason' => $entry->exclusion_reason, 'reason_codes' => array_values(array_unique($reasons)), 'tests' => []];
            $tests = $rows['entry_definitions_by_entry'][$entry->id] ?? [];
            if (count($tests) > 20) {
                $reasons[] = 'coverage_limit';
            }
            foreach ($tests as $test) {
                $testReasons = $reasons;
                $definition = $rows['definition'][$test->definition_id] ?? null;
                $definitionReview = $rows['review'][$test->definition_review_id] ?? null;
                $series = $selectedSeries[$test->source_instance_id.':'.$test->definition_id] ?? null;
                $attempt = $series ? ($inputs['attempts'][$series->current_attempt_id] ?? null) : null;
                $execution = $attempt ? ($rows['execution'][$attempt->current_version_id] ?? null) : null;
                $out = ['definition_version_id' => $test->definition_id, 'definition_review_id' => $test->definition_review_id, 'source_instance_id' => $test->source_instance_id, 'required' => $test->required, 'release_critical' => $definition?->release_critical ?? false, 'series_id' => $series?->id, 'attempt_id' => $attempt?->id, 'execution_id' => $execution?->id, 'status' => 'missing', 'reason_codes' => []];
                if (! $definition || ($inputs['definition_heads'][$definition->test_identity_id]->current_definition_id ?? null) !== $definition->id) {
                    $testReasons[] = 'definition_version_changed';
                }
                if (! $definition || $definition->review_state !== 'reviewed' || $definition->current_review_id !== $test->definition_review_id || ! $definitionReview || $definitionReview->definition_id !== $test->definition_id || $definitionReview->decision !== 'reviewed' || $definitionReview->subject_hash !== $definition->content_hash) {
                    $testReasons[] = 'definition_review_changed';
                }
                $testReasons = [...$testReasons, ...$this->proofReasons('definition', $test->definition_id, $inputs, $proofResults), ...$this->proofReasons('review', $test->definition_review_id, $inputs, $proofResults)];
                if ($definition) {
                    if (! isset($inputs['source_heads'][$definition->receipt_id])) {
                        $testReasons[] = 'source_version_changed';
                    }
                    $testReasons = [...$testReasons, ...$this->proofReasons('receipt', $definition->receipt_id, $inputs, $proofResults)];
                }
                if ($series) {
                    $sequence = $inputs['attempt_counts'][$series->id] ?? null;
                    $versions = $attempt ? ($inputs['version_counts'][$attempt->id] ?? null) : null;
                    if (! $attempt || $attempt->series_id !== $series->id || $attempt->ordinal !== $series->current_ordinal || ! $sequence || (int) $sequence->min !== 1 || (int) $sequence->max !== $series->current_ordinal || (int) $sequence->count !== $series->current_ordinal) {
                        $testReasons[] = 'attempt_order_unknown';
                    }
                    if ($attempt && $attempt->ordinal > 1) {
                        $prior = $inputs['attempts'][$attempt->predecessor_id] ?? null;
                        if (! $prior || $prior->series_id !== $series->id || $prior->ordinal !== $attempt->ordinal - 1) {
                            $testReasons[] = 'predecessor_missing';
                        }
                    }
                    if (! $execution || ! $versions || (int) $versions->min !== 1 || (int) $versions->count !== $attempt->source_revision || (int) $versions->max !== $attempt->source_revision || $execution->source_revision !== $attempt->source_revision) {
                        $testReasons[] = 'source_order_unknown';
                    }
                    if ($execution) {
                        if ($execution->series_id !== $series->id || $execution->definition_id !== $test->definition_id || $execution->criterion_version_id !== $entry->criterion_version_id || $execution->build_id !== $selection->build_id || $execution->environment_id !== $selection->environment_id || ! isset($inputs['source_heads'][$execution->receipt_id])) {
                            $testReasons[] = 'execution_pins_changed';
                        }
                        $testReasons = [...$testReasons, ...$this->proofReasons('execution', $execution->id, $inputs, $proofResults), ...$this->proofReasons('receipt', $execution->receipt_id, $inputs, $proofResults)];
                        if ($attempt->fix_request_event_id) {
                            $testReasons = [...$testReasons, ...$this->proofReasons('defect_event', $attempt->fix_request_event_id, $inputs, $proofResults)];
                            $fix = $rows['defect_event'][$attempt->fix_request_event_id] ?? null;
                            $defect = $fix ? ($rows['defect'][$fix->defect_id] ?? null) : null;
                            if (! $fix || $fix->event_type !== 'fix_requested' || $fix->definition_id !== $test->definition_id || $fix->criterion_version_id !== $entry->criterion_version_id || $fix->build_id !== $selection->build_id || $fix->environment_id !== $selection->environment_id || ! $defect || ! isset($inputs['source_heads'][$defect->receipt_id])) {
                                $testReasons[] = 'fix_request_pins_changed';
                            }
                            if ($defect) {
                                $testReasons = [...$testReasons, ...$this->proofReasons('defect', $defect->id, $inputs, $proofResults), ...$this->proofReasons('receipt', $defect->receipt_id, $inputs, $proofResults)];
                            }
                        }
                    }
                }
                $testReasons = array_values(array_unique($testReasons));
                $out['reason_codes'] = $testReasons;
                $out['status'] = $testReasons ? (array_intersect($testReasons, ['coverage_limit', 'proof_unknown', 'selection_changed', 'plan_entries_unknown', 'attempt_order_unknown', 'source_order_unknown', 'predecessor_missing']) ? 'unknown' : 'stale') : ($execution?->outcome ?? 'missing');
                $criterion['tests'][] = $out;
                if ($entry->included && $testReasons) {
                    $result['coverage_status'] = in_array('coverage_limit', $testReasons, true) || in_array('selection_changed', $testReasons, true) ? 'unknown' : ($result['coverage_status'] === 'unknown' ? 'unknown' : 'partial');
                    $result['reason_codes'] = [...$result['reason_codes'], ...$testReasons];
                }
            }
            if ($entry->included && $reasons) {
                $result['coverage_status'] = array_intersect($reasons, ['coverage_limit', 'selection_changed']) ? 'unknown' : ($result['coverage_status'] === 'unknown' ? 'unknown' : 'partial');
                $result['reason_codes'] = [...$result['reason_codes'], ...$reasons];
            }
            $result['criteria'][] = $criterion;
        }
        if ($global) {
            $result['coverage_status'] = array_intersect($global, ['coverage_limit', 'selection_changed', 'proof_unknown', 'plan_entries_unknown']) ? 'unknown' : ($result['coverage_status'] === 'unknown' ? 'unknown' : 'partial');
            $result['reason_codes'] = [...$result['reason_codes'], ...$global];
        }
        $result['reason_codes'] = array_values(array_unique($result['reason_codes']));

        return $result;
    }

    private function proofReasons(string $kind, string $id, array $inputs, array $proofResults): array
    {
        $row = $inputs['rows'][$kind][$id] ?? null;
        if (! $row) {
            return ['proof_unknown'];
        }
        // Keys are record-specific, never actor-specific: one stale grant must not poison another fact.
        $slots = (isset($row->authority_proof) ? 1 : 0) + (isset($row->receipt_id) ? 1 : 0);
        $reasons = [];
        for ($i = 0; $i < $slots; $i++) {
            $key = $kind.':'.$id.':'.$i;
            $proof = $proofResults[$key] ?? null;
            if (! $proof || ! isset($proof['valid'])) {
                $reasons[] = 'proof_unknown';
            } elseif (! $proof['valid']) {
                $reasons[] = $proof['reason_code'] ?? 'proof_unknown';
            }
        }

        return array_values(array_unique($reasons));
    }

    private function selectedSeries(string $project, object $selection): Collection
    {
        $series = DB::table('qa_execution_series as s')->join('qa_plan_entry_definitions as d', function ($q) {
            $q->on('d.tenant_id', '=', 's.tenant_id')->on('d.project_id', '=', 's.project_id')->on('d.definition_id', '=', 's.definition_id')->on('d.source_instance_id', '=', 's.source_instance_id');
        })->join('qa_plan_entries as e', function ($q) {
            $q->on('e.tenant_id', '=', 'd.tenant_id')->on('e.project_id', '=', 'd.project_id')->on('e.entry_id', '=', 'd.entry_id');
        })->where('s.project_id', $project)->where('e.plan_id', $selection->plan_id)->where('s.build_id', $selection->build_id)->where('s.environment_id', $selection->environment_id)->select('s.*')->distinct()->orderBy('s.id')->limit(2001)->get();
        if ($series->count() > 2000) {
            throw new ConflictHttpException;
        }

        return $series;
    }
}
