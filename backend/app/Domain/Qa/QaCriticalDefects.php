<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Bounded inventory and current applicability of authorized critical defects. */
final class QaCriticalDefects
{
    public function collect(TrustedActor $actor, string $project, ?string $department, array $graph): array
    {
        return app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $department, $graph) {
            $ids = $this->inventory($project, $department);
            $graph['critical_ids'] = $ids;
            $graph['critical_overflow'] = count($ids) > 100;
            if (! $ids || $graph['critical_overflow']) {
                return $graph;
            }
            try {
                $extra = app(QaDefectService::class)->collectPins($actor, $project, ['defect' => $ids], includeHistoricalExecutions: true);
            } catch (ConflictHttpException) {
                $graph['critical_overflow'] = true;

                return $graph;
            } catch (InvalidArgumentException $failure) {
                if ($failure->getMessage() !== 'QA pin graph exceeds supported slots.') {
                    throw $failure;
                }
                $graph['critical_overflow'] = true;

                return $graph;
            }
            $merged = $graph['refs'];
            foreach ($extra['refs'] as $kind => $refs) {
                $merged[$kind] = array_values(array_unique([...($merged[$kind] ?? []), ...$refs]));
            }
            if (array_sum(array_map('count', $merged)) > QaPins::MAX_REFERENCE_ROWS) {
                $graph['critical_overflow'] = true;

                return $graph;
            }
            $graph['refs'] = $merged;
            $graph['requests'] = array_values(array_column([...$graph['requests'], ...$extra['requests']], null, 'key'));
            $graph['source_ids'] = array_values(array_unique([...$graph['source_ids'], ...$extra['source_ids']]));
            $graph['heads'] = $extra['heads'];
            $graph['input_hash'] = hash('sha256', QaContract::canonical($graph['refs']));

            return $graph;
        });
    }

    public function inspectLocked(string $project, ?string $department, array $graph, array $inputs, array $proofs, array $eligible, ?array $view): array
    {
        // Recheck under the project lock; hints cannot omit a concurrently reported defect.
        if ($this->inventory($project, $department) !== $graph['critical_ids']) {
            throw new ConflictHttpException;
        }
        $result = ['critical_signals' => [], 'critical_continuation_required' => false, 'coverage_status' => 'complete', 'reason_codes' => []];
        if ($graph['critical_overflow'] || ($graph['dependency_overflow'] ?? false) || ($view['overflow'] ?? false)) {
            return array_replace($result, ['critical_continuation_required' => true, 'coverage_status' => 'unknown', 'reason_codes' => ['coverage_limit']]);
        }
        $rows = $inputs['rows'];
        $currentRows = $rows;
        foreach ($rows['defect'] ?? [] as $id => $defect) {
            // The initial report's execution is historical context, not the current closure proof.
            // Keep it in the delivery graph for department privacy without requiring its old authority.
            $currentRows['defect'][$id] = clone $defect;
            $currentRows['defect'][$id]->execution_id = null;
        }
        app(QaDefectService::class)->assertCapturedHeadsLocked($graph, $rows, ['defect' => array_keys($graph['heads']['defect'] ?? [])]);
        $taskHeads = empty($graph['critical_ids']) ? collect() : DB::table('lifecycle_tasks')->where('project_id', $project)->whereIn('id', array_map(fn ($task) => $task->task_id, $rows['task_version'] ?? []))->pluck('current_version_id', 'id');
        foreach ($graph['critical_ids'] as $id) {
            if ($view !== null && ! app(QaDepartmentView::class)->visible($view, $proofs['current'], 'defect', $id)) {
                throw new ConflictHttpException;
            }
            $defect = $rows['defect'][$id] ?? null;
            $head = $rows['defect_event'][$defect?->current_event_id ?? ''] ?? null;
            $reasons = [];
            foreach (app(QaDepartmentView::class)->dependencies($currentRows, 'defect', $id) as $key) {
                [$kind, $ref] = explode(':', $key, 2);
                $row = $rows[$kind][$ref] ?? null;
                if (! $row) {
                    $reasons[] = 'proof_unknown';

                    continue;
                }
                $slots = (isset($row->authority_proof) ? 1 : 0) + (isset($row->receipt_id) ? 1 : 0);
                for ($i = 0; $i < $slots; $i++) {
                    if (! ($proofs[$key.':'.$i]['valid'] ?? false)) {
                        $reasons[] = $proofs[$key.':'.$i]['reason_code'] ?? 'proof_unknown';
                    }
                }
                if ($kind === 'receipt' && ! isset($inputs['source_heads'][$ref])) {
                    $reasons[] = 'source_version_changed';
                }
                if ($kind === 'task_version' && $taskHeads->get($row->task_id) !== $row->id) {
                    $reasons[] = 'task_version_changed';
                }
            }
            $test = null;
            foreach ($eligible['criteria'] as $criterion) {
                if (! $head || ! $criterion['included'] || $criterion['criterion_version_id'] !== $head->criterion_version_id) {
                    continue;
                }
                foreach ($criterion['tests'] as $candidate) {
                    if ($candidate['definition_version_id'] === $head->definition_id) {
                        $test = $candidate;
                        break;
                    }
                }
            }
            if (! $head || ! $test || $head->build_id !== ($eligible['selection']['build_id'] ?? null) || $head->environment_id !== ($eligible['selection']['environment_id'] ?? null)) {
                $reasons[] = 'defect_scope_unknown';
            } elseif (in_array($test['status'], ['stale', 'unknown'], true)) {
                $reasons = [...$reasons, ...$test['reason_codes']];
            }
            $closed = $head && $head->state === 'verified_closed' && $head->event_type === 'verified_closed'
                && $head->selection_id === ($eligible['selection']['id'] ?? null) && $test && $test['status'] === 'passed'
                && $test['execution_id'] === $head->execution_id && ($inputs['attempts'][$test['attempt_id']]->fix_request_event_id ?? null) === $head->fix_request_event_id;
            $canonical = $defect?->canonical_defect_id;
            $seen = [$id];
            while ($canonical) {
                if (in_array($canonical, $seen, true)) {
                    $reasons[] = 'proof_unknown';
                    break;
                }
                $seen[] = $canonical;
                $parent = $rows['defect'][$canonical] ?? null;
                $decision = $rows['defect_event'][$parent?->current_event_id ?? ''] ?? null;
                if (! $decision || $decision->state !== 'verified_closed' || $decision->event_type !== 'verified_closed'
                    || $decision->selection_id !== ($eligible['selection']['id'] ?? null)
                    || ! $this->currentClosureExecution($decision, $eligible, $inputs)) {
                    $reasons[] = 'canonical_defect_unresolved';
                }
                $canonical = $parent?->canonical_defect_id;
            }
            if ($head?->state === 'verified_closed' && ! $closed) {
                $reasons[] = 'closure_not_current';
            }
            if ($closed && ! $reasons) {
                continue;
            }
            $result['critical_signals'][] = ['code' => $reasons ? 'critical_defect_applicability_unknown' : 'critical_defect_unresolved', 'defect_id' => $id, 'criterion_id' => $defect?->criterion_id, 'definition_version_id' => $head?->definition_id, 'state' => $head?->state, 'reason_codes' => $department !== null && $reasons ? ['current_verification_unavailable'] : array_values(array_unique($reasons))];
            if ($reasons) {
                $result['coverage_status'] = 'unknown';
                $result['reason_codes'] = ['current_verification_unavailable'];
            }
        }

        return $result;
    }

    private function currentClosureExecution(object $decision, array $eligible, array $inputs): bool
    {
        foreach ($eligible['criteria'] as $criterion) {
            if (! $criterion['included'] || $criterion['criterion_version_id'] !== $decision->criterion_version_id) {
                continue;
            }
            foreach ($criterion['tests'] as $test) {
                if ($test['status'] === 'passed' && $test['definition_version_id'] === $decision->definition_id && $test['execution_id'] === $decision->execution_id
                    && ($inputs['attempts'][$test['attempt_id']]->fix_request_event_id ?? null) === $decision->fix_request_event_id) {
                    return true;
                }
            }
        }

        return false;
    }

    private function inventory(string $project, ?string $department): array
    {
        $query = DB::table('qa_defects as d')->where('d.project_id', $project)->where('d.severity', 'critical');
        if ($department !== null) {
            app(QaDepartmentView::class)->filter($query, $department, 'defect', 'd.id', true);
        }

        return $query->orderBy('d.id')->limit(101)->pluck('d.id')->all();
    }
}
