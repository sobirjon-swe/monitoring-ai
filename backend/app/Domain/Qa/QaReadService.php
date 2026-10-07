<?php

namespace App\Domain\Qa;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class QaReadService
{
    public function verification(TrustedActor $actor, string $project, ?string $department = null): array
    {
        $this->authorizeHints($actor, $project, $department);
        $eligibility = app(QaEligibility::class);
        $graph = $eligibility->collectSelectionPins($actor, $project);
        $graph = app(QaCriticalDefects::class)->collect($actor, $project, $department, $graph);
        $graph = $this->withTasks($actor, $project, $graph);
        $departmentView = $department === null ? null : app(QaDepartmentView::class)->prepare($actor, $project, $department, $graph);
        $requests = $graph['requests'];
        $purposes = $department === null ? ['qa.read', 'qa.source.read'] : ['qa.read', 'qa.source.read', 'qa.department.read'];

        return app(QaCoordination::class)->run($actor, $project, $purposes, false, function ($proofs) use ($actor, $project, $department, $departmentView, $graph, $eligibility) {
            if (DB::table('lifecycle_project_state')->where('project_id', $project)->value('is_closed')) {
                $result = app(QaVerificationCalculator::class)->calculate(['selection' => null]);
                $result['coverage_status'] = 'unavailable';
                $result['reason_codes'] = ['project_closed'];

                return $department === null ? $result : app(QaDepartmentView::class)->redact($result, []);
            }
            $inputs = $eligibility->loadInputsLocked($project, $graph);
            $invalidations = app(QaInvalidation::class);
            $invalidations->observeProofsLocked($actor, $project, $graph, $proofs, 'read:'.$graph['input_hash']);
            $proofs = $invalidations->overlayLocked($project, $inputs['rows'], $proofs);
            $proofs = $this->taskValidityLocked($project, $inputs, $proofs);
            $eligible = $eligibility->inspectLocked($project, $inputs, $proofs);
            $subjects = [];
            foreach ($eligible['criteria'] as $criterion) {
                foreach (array_intersect($criterion['reason_codes'], ['criterion_version_changed', 'baseline_changed', 'plan_review_changed', 'source_version_changed']) as $reason) {
                    $subjects[] = ['kind' => 'entry', 'id' => $criterion['entry_snapshot_id'], 'reason_code' => $reason];
                }
                foreach ($criterion['tests'] as $test) {
                    if (! $test['execution_id']) {
                        continue;
                    }
                    foreach (array_intersect($test['reason_codes'], ['execution_pins_changed', 'task_version_changed', 'source_version_changed']) as $reason) {
                        $subjects[] = ['kind' => 'execution', 'id' => $test['execution_id'], 'reason_code' => $reason];
                    }
                }
            }
            $invalidations->observeSubjectsLocked($actor, $project, $subjects, 'delivery:'.$graph['input_hash']);
            // Reload terminal states before building the first-observation response.
            $proofs = $invalidations->overlayLocked($project, $inputs['rows'], $proofs);
            $eligible = $eligibility->inspectLocked($project, $inputs, $proofs);
            $critical = app(QaCriticalDefects::class)->inspectLocked($project, $department, $graph, $inputs, $proofs, $eligible, $departmentView);
            $eligible['critical_signals'] = $critical['critical_signals'];
            $eligible['critical_continuation_required'] = $critical['critical_continuation_required'];
            if ($critical['coverage_status'] !== 'complete') {
                $eligible['coverage_status'] = 'unknown';
                $eligible['reason_codes'] = array_values(array_unique([...$eligible['reason_codes'], ...$critical['reason_codes']]));
            }
            if ($department !== null) {
                $visible = app(QaDepartmentView::class)->authorizeLocked($actor, $project, $department, $inputs + ['department_view' => $departmentView], $proofs);
                $visible['critical_signals'] = $critical['critical_signals'];
                $visible['critical_continuation_required'] = $critical['critical_continuation_required'];
                if ($critical['coverage_status'] !== 'complete') {
                    $visible['coverage_status'] = 'unknown';
                    $visible['reason_codes'] = ['current_verification_unavailable'];
                }
                $eligible['criteria'] = $visible['criteria'];
                $result = app(QaVerificationCalculator::class)->calculate($eligible);

                return app(QaDepartmentView::class)->redact($result, $visible);
            }

            return app(QaVerificationCalculator::class)->calculate($eligible);
        }, $requests, $department, $departmentView['refs'] ?? null);
    }

    public function history(TrustedActor $actor, string $project, string $kind, string $id, int $limit = 50, ?string $cursor = null, ?string $department = null): array
    {
        $this->page($limit, $cursor);
        $this->authorizeHints($actor, $project, $department);
        if (! in_array($kind, ['build', 'environment', 'definition', 'plan', 'selection', 'execution', 'defect', 'handoff'], true) || ! Str::isUuid($id)) {
            throw new InvalidArgumentException('Invalid QA history.');
        }
        $offset = $this->decodeCursor($actor, $project, 'history:'.$kind.':'.$id, $department, $cursor);
        $hint = app(TenantContext::class)->run($actor->tenantId, fn () => $this->historyRows($project, $kind, $id, $limit + 1, $offset, $department));
        $refs = [$kind => [$id]];
        $eventKind = $kind === 'plan' ? 'review' : (in_array($kind, ['defect', 'handoff'], true) ? $kind.'_event' : $kind);
        $refs[$eventKind] = array_values(array_unique([...($refs[$eventKind] ?? []), ...$hint->pluck('id')->all()]));
        $graph = in_array($kind, ['defect', 'handoff'], true) ? app(QaDefectService::class)->collectPins($actor, $project, $refs) : app(QaPlanService::class)->collectPins($actor, $project, $refs);
        $graph = $this->withTasks($actor, $project, $graph);
        $view = $department === null ? null : app(QaDepartmentView::class)->prepare($actor, $project, $department, $graph);
        $purposes = $department === null ? ['qa.read', 'qa.source.read'] : ['qa.read', 'qa.source.read', 'qa.department.read'];

        return app(QaCoordination::class)->run($actor, $project, $purposes, false, function ($proofs) use ($actor, $project, $kind, $id, $limit, $offset, $department, $hint, $graph, $view, $eventKind) {
            if ($graph['dependency_overflow'] ?? false) {
                return $this->unknownPage();
            }
            $rows = app(QaPlanService::class)->loadGraphLocked($project, $graph);
            if (in_array($kind, ['defect', 'handoff'], true)) {
                app(QaDefectService::class)->assertCapturedHeadsLocked($graph, $rows);
            }
            app(QaInvalidation::class)->observeProofsLocked($actor, $project, $graph, $proofs, 'history:'.$graph['input_hash']);
            $events = $this->historyRows($project, $kind, $id, $limit + 1, $offset, $department);
            if ($events->pluck('id')->all() !== $hint->pluck('id')->all()) {
                throw new ConflictHttpException;
            }
            if ($view !== null && $view['overflow']) {
                return ['items' => [], 'next_cursor' => null, 'visible_record_count' => null, 'coverage_status' => 'unknown', 'reason_codes' => ['coverage_limit'], 'synthetic' => true, 'ai_interpretation' => 'unavailable'];
            }
            if ($view !== null) {
                foreach ($events as $event) {
                    if (! app(QaDepartmentView::class)->visible($view, $proofs['current'], $eventKind, $event->id)) {
                        throw new ConflictHttpException;
                    }
                }
            }
            if ($events->isNotEmpty() && in_array('coverage_limit', array_column($proofs, 'reason_code'), true)) {
                return $this->unknownPage();
            }
            $more = $events->count() > $limit;
            $items = $events->take($limit)->map(fn ($row) => array_intersect_key((array) $row, array_flip(['id', 'revision', 'source_revision', 'event_type', 'state', 'outcome', 'decision', 'owner_id', 'effective_at', 'received_at', 'content_hash'])))->all();

            return ['items' => $items, 'next_cursor' => $more ? $this->encodeCursor($actor, $project, 'history:'.$kind.':'.$id, $department, (int) $events[$limit - 1]->page_revision) : null, 'visible_record_count' => count($items), 'coverage_status' => 'complete', 'reason_codes' => [], 'synthetic' => true, 'ai_interpretation' => 'unavailable'];
        }, $graph['requests'], $department, $view['refs'] ?? null);
    }

    public function queue(TrustedActor $actor, string $project, int $limit = 50, ?string $cursor = null, ?string $department = null): array
    {
        $this->page($limit, $cursor);
        $this->authorizeHints($actor, $project, $department);
        $offset = $this->decodeCursor($actor, $project, 'queue', $department, $cursor);
        $hint = app(TenantContext::class)->run($actor->tenantId, fn () => $this->queueRows($project, $limit + 1, $offset, $department));
        $graph = app(QaDefectService::class)->collectPins($actor, $project, ['handoff' => $hint->pluck('id')->all()]);
        $graph = $this->withTasks($actor, $project, $graph);
        $view = $department === null ? null : app(QaDepartmentView::class)->prepare($actor, $project, $department, $graph, true);
        $purposes = $department === null ? ['qa.read', 'qa.source.read'] : ['qa.read', 'qa.source.read', 'qa.department.read'];

        return app(QaCoordination::class)->run($actor, $project, $purposes, false, function ($proofs) use ($actor, $project, $limit, $offset, $department, $hint, $graph, $view) {
            if ($graph['dependency_overflow'] ?? false) {
                return $this->unknownPage();
            }
            $rows = app(QaPlanService::class)->loadGraphLocked($project, $graph);
            app(QaDefectService::class)->assertCapturedHeadsLocked($graph, $rows);
            $events = $this->queueRows($project, $limit + 1, $offset, $department);
            if ($events->pluck('id')->all() !== $hint->pluck('id')->all()) {
                throw new ConflictHttpException;
            }
            if ($view !== null && $view['overflow']) {
                return ['items' => [], 'next_cursor' => null, 'visible_record_count' => null, 'coverage_status' => 'unknown', 'reason_codes' => ['coverage_limit'], 'synthetic' => true, 'ai_interpretation' => 'unavailable'];
            }
            if ($events->isNotEmpty() && in_array('coverage_limit', array_column($proofs, 'reason_code'), true)) {
                return $this->unknownPage();
            }
            app(QaInvalidation::class)->observeProofsLocked($actor, $project, $graph, $proofs, 'queue:'.$graph['input_hash']);
            $proofs = app(QaInvalidation::class)->overlayLocked($project, $rows, $proofs);
            $more = $events->count() > $limit;
            $items = [];
            $instant = DB::scalar('select clock_timestamp()');
            $taskHeads = DB::table('lifecycle_tasks')->where('project_id', $project)->whereIn('id', array_values(array_unique(array_map(fn ($task) => $task->task_id, $rows['task_version'] ?? []))))->pluck('current_version_id', 'id');
            $validity = $this->queueValidityLocked($project, $rows, $proofs, $events->take($limit), $taskHeads);
            $observed = [];
            foreach ($events->take($limit) as $handoff) {
                foreach ($validity[$handoff->id] as $reason) {
                    if (in_array($reason, ['access_revoked', 'source_version_changed', 'criterion_version_changed', 'definition_version_changed', 'definition_review_changed', 'task_version_changed', 'execution_pins_changed'], true)) {
                        $observed[] = ['kind' => 'handoff_event', 'id' => $handoff->current_event_id, 'reason_code' => $reason];
                        break;
                    }
                }
            }
            app(QaInvalidation::class)->observeSubjectsLocked($actor, $project, $observed, 'queue-current:'.$graph['input_hash']);
            $proofs = app(QaInvalidation::class)->overlayLocked($project, $rows, $proofs);
            foreach ($events->take($limit) as $handoff) {
                if ($view !== null && ! app(QaDepartmentView::class)->visible($view, $proofs['current'], 'handoff', $handoff->id)) {
                    throw new ConflictHttpException;
                }
                $reasons = $validity[$handoff->id];
                foreach (['handoff:'.$handoff->id.':0', 'handoff_event:'.$handoff->current_event_id.':0'] as $key) {
                    if (! ($proofs[$key]['valid'] ?? false)) {
                        $reasons[] = $proofs[$key]['reason_code'] ?? 'proof_unknown';
                    }
                }
                $items[] = array_intersect_key((array) $handoff, array_flip(['id', 'revision', 'state', 'handoff_type', 'owner_id', 'opened_at'])) + ['responsibility' => json_decode($handoff->responsibility, true), 'age' => app(QaResponsibility::class)->elapsedAge($handoff->opened_at, $instant), 'applicability' => $reasons ? 'stale' : 'current', 'reason_codes' => array_values(array_unique($reasons))];
            }
            $last = $events->take($limit)->last();

            return ['items' => $items, 'next_cursor' => $more ? $this->encodeCursor($actor, $project, 'queue', $department, pack('J', (int) $last->page_time).hex2bin(str_replace('-', '', $last->id))) : null, 'visible_record_count' => count($items), 'basis' => $department === null ? 'project_authorized_entries' : 'department_authorized_entries', 'coverage_status' => 'complete', 'reason_codes' => [], 'synthetic' => true, 'ai_interpretation' => 'unavailable'];
        }, $graph['requests'], $department, $view['refs'] ?? null);
    }

    /** Batch current heads once, then evaluate only each delivered handoff's reachable immutable facts. */
    private function queueValidityLocked(string $project, array $rows, array $proofs, Collection $handoffs, Collection $taskHeads): array
    {
        $currentCriteria = DB::table('requirement_version_criteria as link')->join('requirement_versions as v', 'v.id', '=', 'link.requirement_version_id')->where('link.project_id', $project)->whereIn('link.criterion_version_id', array_keys($rows['criterion_version'] ?? []))->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('requirement_versions as newer')->whereColumn('newer.tenant_id', 'v.tenant_id')->whereColumn('newer.project_id', 'v.project_id')->whereColumn('newer.requirement_id', 'v.requirement_id')->whereColumn('newer.version', '>', 'v.version');
        })->distinct()->pluck('link.criterion_version_id')->all();
        $sourceHeads = DB::table('qa_source_heads')->where('project_id', $project)->whereIn('receipt_id', array_keys($rows['receipt'] ?? []))->pluck('receipt_id')->all();
        $definitionHeads = DB::table('qa_definition_identities')->where('project_id', $project)->whereIn('id', array_map(fn ($row) => $row->test_identity_id, $rows['definition'] ?? []))->pluck('current_definition_id', 'id');
        $reasons = [];
        foreach ($handoffs as $handoff) {
            $invalid = [];
            foreach (app(QaDepartmentView::class)->dependencies($rows, 'handoff', $handoff->id) as $key) {
                [$kind, $id] = explode(':', $key, 2);
                $row = $rows[$kind][$id] ?? null;
                if (! $row) {
                    $invalid[] = 'proof_unknown';

                    continue;
                }
                $slots = (isset($row->authority_proof) ? 1 : 0) + (isset($row->receipt_id) ? 1 : 0);
                for ($i = 0; $i < $slots; $i++) {
                    if (! ($proofs[$key.':'.$i]['valid'] ?? false)) {
                        $invalid[] = $proofs[$key.':'.$i]['reason_code'] ?? 'proof_unknown';
                    }
                }
                $criterion = $kind === 'criterion_version' ? $row->id : ($row->criterion_version_id ?? null);
                if ($criterion && ! in_array($criterion, $currentCriteria, true)) {
                    $invalid[] = 'criterion_version_changed';
                }
                if ($kind === 'task_version' && $taskHeads->get($row->task_id) !== $row->id) {
                    $invalid[] = 'task_version_changed';
                }
                if (in_array($kind, ['build', 'environment', 'definition', 'execution'], true) && ! in_array($row->receipt_id, $sourceHeads, true)) {
                    $invalid[] = 'source_version_changed';
                }
                if ($kind === 'definition') {
                    if ($definitionHeads->get($row->test_identity_id) !== $row->id) {
                        $invalid[] = 'definition_version_changed';
                    }
                    $review = $rows['review'][$row->current_review_id ?? ''] ?? null;
                    if ($row->review_state !== 'reviewed' || ! $review || $review->definition_id !== $row->id || $review->decision !== 'reviewed' || $review->subject_hash !== $row->content_hash) {
                        $invalid[] = 'definition_review_changed';
                    }
                }
            }
            $reasons[$handoff->id] = array_values(array_unique($invalid));
        }

        return $reasons;
    }

    private function withTasks(TrustedActor $actor, string $project, array $graph): array
    {
        return app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $graph) {
            $handoffs = $graph['refs']['handoff'] ?? [];
            if ($handoffs) {
                $placeholders = implode(',', array_fill(0, count($handoffs), '?::uuid'));
                $linked = DB::select("WITH RECURSIVE linked(id) AS (SELECT id FROM qa_handoffs WHERE project_id=?::uuid AND id IN ($placeholders) UNION SELECT h.linked_handoff_id FROM qa_handoffs h JOIN linked l ON l.id=h.id WHERE h.project_id=?::uuid AND h.linked_handoff_id IS NOT NULL) SELECT id FROM linked LIMIT ".(QaPins::MAX_REFERENCE_ROWS + 1), [$project, ...$handoffs, $project]);
                $ids = array_column($linked, 'id');
                if (count($ids) > QaPins::MAX_REFERENCE_ROWS) {
                    return $this->overflowGraph($graph);
                }
                if (array_diff($ids, $handoffs)) {
                    $refs = $graph['refs'];
                    $refs['handoff'] = $ids;
                    try {
                        $expanded = app(QaDefectService::class)->collectPins($actor, $project, $refs);
                    } catch (InvalidArgumentException $failure) {
                        if ($failure->getMessage() !== 'QA pin graph exceeds supported slots.') {
                            throw $failure;
                        }

                        return $this->overflowGraph($graph);
                    }
                    $graph = array_replace($graph, $expanded);
                }
            }
            // The producer helper captures executions themselves; this consumer also delivers their linked facts.
            $executions = DB::table('qa_execution_versions')->where('project_id', $project)->whereIn('id', $graph['refs']['execution'] ?? [])->get(['definition_id', 'criterion_version_id', 'build_id', 'environment_id']);
            $refs = $graph['refs'];
            foreach (['definition' => 'definition_id', 'criterion_version' => 'criterion_version_id', 'build' => 'build_id', 'environment' => 'environment_id'] as $kind => $field) {
                $refs[$kind] = array_values(array_unique([...($refs[$kind] ?? []), ...$executions->pluck($field)->all()]));
            }
            if ($refs !== $graph['refs']) {
                try {
                    $graph = array_replace($graph, app(QaDefectService::class)->collectPins($actor, $project, $refs));
                } catch (InvalidArgumentException $failure) {
                    if ($failure->getMessage() !== 'QA pin graph exceeds supported slots.') {
                        throw $failure;
                    }

                    return $this->overflowGraph($graph);
                }
            }
            $ids = DB::table('qa_receipts')->where('project_id', $project)->whereIn('id', $graph['refs']['receipt'] ?? [])->where('kind', 'execution')->selectRaw("distinct envelope->'body'->>'task_version_id' as task_id")->pluck('task_id')->filter()->all();
            $graph['refs']['task_version'] = array_values(array_unique([...($graph['refs']['task_version'] ?? []), ...$ids]));
            if (array_sum(array_map('count', $graph['refs'])) > QaPins::MAX_REFERENCE_ROWS) {
                return $this->overflowGraph($graph);
            }
            $graph['input_hash'] = hash('sha256', QaContract::canonical($graph['refs']));

            return $graph;
        });
    }

    private function overflowGraph(array $graph): array
    {
        return array_replace($graph, ['refs' => [], 'requests' => [], 'source_ids' => [], 'heads' => [], 'dependency_overflow' => true]);
    }

    private function unknownPage(): array
    {
        return ['items' => [], 'next_cursor' => null, 'visible_record_count' => null, 'coverage_status' => 'unknown', 'reason_codes' => ['coverage_limit'], 'synthetic' => true, 'ai_interpretation' => 'unavailable'];
    }

    private function taskValidityLocked(string $project, array $inputs, array $proofs): array
    {
        $versions = $inputs['rows']['task_version'] ?? [];
        $heads = DB::table('lifecycle_tasks')->where('project_id', $project)->whereIn('id', array_map(fn ($row) => $row->task_id, $versions))->get()->keyBy('id');
        foreach ($inputs['rows']['execution'] ?? [] as $execution) {
            $receipt = $inputs['rows']['receipt'][$execution->receipt_id] ?? null;
            $taskId = $receipt ? json_decode($receipt->envelope, true)['body']['task_version_id'] ?? null : null;
            $task = $versions[$taskId ?? ''] ?? null;
            if (! $task || $heads->get($task->task_id)?->current_version_id !== $task->id) {
                foreach (array_keys($proofs) as $key) {
                    if (str_starts_with($key, 'execution:'.$execution->id.':')) {
                        $proofs[$key] = ['valid' => false, 'reason_code' => 'task_version_changed'];
                    }
                }
            }
        }

        return $proofs;
    }

    private function historyRows(string $project, string $kind, string $id, int $limit, int $after, ?string $department = null): Collection
    {
        $root = DB::table(QaPins::TABLES[$kind])->where('project_id', $project)->find($id) ?? throw new NotFoundHttpException;
        if ($department !== null && in_array($kind, ['build', 'environment', 'plan', 'selection'], true)) {
            return collect();
        }
        if (in_array($kind, ['defect', 'handoff'], true)) {
            $q = DB::table(QaPins::TABLES[$kind.'_event'].' as e')->where('e.project_id', $project)->where('e.'.$kind.'_id', $id)->where('e.revision', '>', $after);
            if ($department !== null) {
                $q->join(QaPins::TABLES[$kind].' as h', 'h.id', '=', 'e.'.$kind.'_id')->join('qa_receipts as r', 'r.id', '=', 'h.receipt_id');
                $this->departmentFilter($q, $department, $kind);
            }

            return $q->orderBy('e.revision')->limit($limit)->selectRaw('e.*,e.revision as page_revision')->get();
        }
        if ($kind === 'selection') {
            return DB::table('qa_selection_events')->where('project_id', $project)->where('revision', '>', $after)->orderBy('revision')->limit($limit)->selectRaw('*,revision as page_revision')->get();
        }
        if ($kind === 'plan') {
            return DB::table('qa_reviews')->where('project_id', $project)->where('plan_id', $id)->where('revision', '>', $after)->orderBy('revision')->limit($limit)->selectRaw('*,revision as page_revision')->get();
        }
        if ($kind === 'execution') {
            $q = DB::table('qa_execution_versions as v')->where('v.project_id', $project)->where('v.attempt_id', $root->attempt_id)->where('v.source_revision', '>', $after);
            if ($department !== null) {
                $q->join('qa_receipts as r', 'r.id', '=', 'v.receipt_id');
                app(QaDepartmentView::class)->filter($q, $department, 'execution', 'v.id');
            }

            return $q->orderBy('v.source_revision')->limit($limit)->selectRaw('v.*,v.source_revision as page_revision')->get();
        }
        $q = DB::table(QaPins::TABLES[$kind])->where('project_id', $project);
        if ($kind === 'definition') {
            $q->where('test_identity_id', $root->test_identity_id);
        } else {
            $q->where('logical_key', $root->logical_key);
        }
        if ($department !== null && $kind === 'definition') {
            $q = DB::table('qa_definition_versions as v')->join('qa_receipts as r', 'r.id', '=', 'v.receipt_id')->where('v.project_id', $project)->where('v.test_identity_id', $root->test_identity_id);
            $this->employeeFilter($q, $department, "r.authority_proof->>'employee_id'");

            return $q->where('v.revision', '>', $after)->orderBy('v.revision')->limit($limit)->selectRaw('v.*,v.revision as page_revision')->get();
        }

        return $q->where('revision', '>', $after)->orderBy('revision')->limit($limit)->selectRaw('*,revision as page_revision')->get();
    }

    private function queueRows(string $project, int $limit, int|string $after, ?string $department): Collection
    {
        $q = DB::table('qa_handoffs as h')->join('qa_handoff_events as e', 'e.id', '=', 'h.current_event_id')->join('qa_receipts as r', 'r.id', '=', 'h.receipt_id')->where('h.project_id', $project)->whereIn('h.state', ['open', 'acknowledged']);
        if (is_string($after)) {
            $time = unpack('Jtime', substr($after, 0, 8))['time'];
            $hex = bin2hex(substr($after, 8, 16));
            $id = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
            $q->whereRaw('((extract(epoch from h.opened_at)*1000000)::bigint,h.id)>(?,?::uuid)', [$time, $id]);
        }
        if ($department !== null) {
            $this->departmentFilter($q, $department, 'handoff', true);
        }

        return $q->orderBy('h.opened_at')->orderBy('h.id')->limit($limit)->selectRaw('h.*,(extract(epoch from h.opened_at)*1000000)::bigint as page_time')->get();
    }

    private function departmentFilter(Builder $q, string $department, string $kind, bool $currentReviews = false): void
    {
        app(QaDepartmentView::class)->filter($q, $department, $kind.'_event', 'e.id', $currentReviews);
    }

    private function employeeFilter(Builder $q, string $department, string $expression): void
    {
        $q->whereRaw("($expression IS NOT NULL AND (SELECT count(*) FROM department_memberships dm WHERE dm.employee_id::text=$expression AND dm.department_id=?::uuid AND dm.valid_from<=clock_timestamp() AND (dm.valid_to IS NULL OR dm.valid_to>clock_timestamp()))=1)", [$department]);
    }

    private function authorizeHints(TrustedActor $actor, string $project, ?string $department): void
    {
        foreach (['qa.read', 'qa.source.read'] as $purpose) {
            app(AccessGateway::class)->authorize($actor, $purpose, ['tenant_id' => $actor->tenantId, 'project_id' => $project]);
        }
        if ($department !== null) {
            app(AccessGateway::class)->authorize($actor, 'qa.department.read', ['tenant_id' => $actor->tenantId, 'department_id' => $department]);
        }
    }

    private function page(int $limit, ?string $cursor): void
    {
        if ($limit < 1 || $limit > 100 || ($cursor !== null && (strlen($cursor) > 128 || ! preg_match('/\A[A-Za-z0-9_-]+\z/D', $cursor)))) {
            throw new InvalidArgumentException('Invalid QA page.');
        }
    }

    private function encodeCursor(TrustedActor $actor, string $project, string $resource, ?string $department, int|string $position): string
    {
        $data = is_int($position) ? pack('J', $position) : $position;
        $signature = substr(hash_hmac('sha256', $actor->tenantId.'|'.$actor->id.'|'.$project.'|'.$resource.'|'.($department ?? '').'|'.$data, (string) config('app.key'), true), 0, 16);

        return rtrim(strtr(base64_encode($data.$signature), '+/', '-_'), '=');
    }

    private function decodeCursor(TrustedActor $actor, string $project, string $resource, ?string $department, ?string $cursor): int|string
    {
        if ($cursor === null) {
            return 0;
        }
        $binary = base64_decode(strtr($cursor, '-_', '+/'), true);
        $length = $resource === 'queue' ? 40 : 24;
        if ($binary === false || strlen($binary) !== $length) {
            throw new InvalidArgumentException('Invalid QA cursor.');
        }
        $value = $resource === 'queue' ? substr($binary, 0, 24) : unpack('Jposition', substr($binary, 0, 8))['position'];
        if ((is_int($value) && $value < 0) || ! hash_equals($this->encodeCursor($actor, $project, $resource, $department, $value), $cursor)) {
            throw new InvalidArgumentException('Invalid QA cursor scope.');
        }

        return $value;
    }
}
