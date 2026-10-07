<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class QaDefectService
{
    private const PIN_FIELDS = ['criterion_version_id' => 'criterion_version_id', 'definition_version_id' => 'definition_id', 'build_id' => 'build_id', 'environment_version_id' => 'environment_id', 'task_version_id' => 'task_version_id'];

    public function report(TrustedActor $actor, string $project, array $report, string $key): array
    {
        return app(QaInvalidation::class)->guardRequest($actor, $project, 'defect.report', $key, fn () => $this->reportRequest($actor, $project, $report, $key));
    }

    private function reportRequest(TrustedActor $actor, string $project, array $report, string $key): array
    {
        $this->local();
        $envelope = app(QaContract::class)->validate('envelope', $report);
        if ($envelope['operation'] !== 'defect' || $envelope['source_revision'] !== 1) {
            throw new ConflictHttpException;
        }
        $body = $envelope['body'];
        $refs = $this->bodyRefs($body);
        $graph = $this->collectPins($actor, $project, $refs, $envelope['source_instance_id'], 'defect.report', $key, $envelope);

        return app(QaCoordination::class)->run($actor, $project, ['qa.defect.review', 'qa.source.read'], true, function ($proofs) use ($actor, $project, $body, $envelope, $key, $graph) {
            $plans = app(QaPlanService::class);
            $rows = $plans->loadGraphLocked($project, $graph);
            $this->assertCapturedHeadsLocked($graph, $rows);
            $plans->assertOriginalsLocked($graph, $proofs);
            $writer = app(QaReceiptWriter::class);
            $receipt = $writer->recordLocked($actor, $project, $envelope, $key, 0, $proofs['current']['proof']);
            if ($receipt['replayed']) {
                $defect = $rows['defect'][$receipt['record_id']] ?? throw new ConflictHttpException;
                app(QaInvalidation::class)->assertReplayCurrentLocked($project, ['receipt_id' => $receipt['receipt_id'], 'defect_event_id' => $defect->current_event_id]);
                $writer->bindRequestLocked($actor, $project, 'defect.report', $key, $receipt['input_hash'], ['receipt_id' => $receipt['receipt_id']]);

                return $receipt + ['id' => $defect->id, 'revision' => $defect->revision, 'state' => $defect->state, 'synthetic' => true];
            }
            if (DB::table('qa_defects')->where('project_id', $project)->where('logical_key', $body['logical_key'])->exists()) {
                throw new ConflictHttpException;
            }
            $pins = $this->affectedPins($body);
            $definition = $rows['definition'][$pins['definition_id']];
            $pins['criterion_id'] = $definition->criterion_id;
            $this->assertAffectedLocked($project, $pins, $rows);
            if (isset($body['execution_id'])) {
                $this->assertExecutionLocked($body['execution_id'], $pins, $rows);
            }
            $id = (string) Str::uuid();
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
            DB::table('qa_defects')->insert(['id' => $id, 'receipt_id' => $receipt['receipt_id'], 'logical_key' => $body['logical_key'], 'severity' => $body['severity'], 'reproducibility' => $body['reproducibility'], 'reproduction' => $body['reproduction'], 'execution_id' => $body['execution_id'] ?? null] + $scope + array_diff_key($pins, ['task_version_id' => true]));
            $this->appendLocked($actor, $project, $id, 1, 'opened', 'open', 'active', null, $pins + ['execution_id' => $body['execution_id'] ?? null], 'Synthetic defect reported', $proofs['current']['proof']);
            $writer->bindRequestLocked($actor, $project, 'defect.report', $key, $receipt['input_hash'], ['receipt_id' => $receipt['receipt_id']]);

            return array_replace($receipt, ['record_id' => $id]) + ['id' => $id, 'revision' => 1, 'state' => 'open', 'synthetic' => true];
        }, $graph['requests']);
    }

    public function transition(TrustedActor $actor, string $project, string $defect, array $event): array
    {
        $event = app(QaContract::class)->validate('defect.transition', $event);
        $pins = $event['pins'];
        $refs = $this->bodyRefs($pins);
        $refs['defect'] = array_values(array_unique([$defect, ...(isset($pins['canonical_defect_id']) ? [$pins['canonical_defect_id']] : [])]));
        foreach (['selection_id' => 'selection', 'fix_request_event_id' => 'defect_event'] as $field => $kind) {
            if (isset($pins[$field])) {
                $refs[$kind] = [$pins[$field]];
            }
        }
        $selectionGraph = $event['kind'] === 'verified_closed' ? app(QaEligibility::class)->collectSelectionPins($actor, $project) : null;
        if ($selectionGraph) {
            foreach ($selectionGraph['refs'] as $kind => $ids) {
                $refs[$kind] = array_values(array_unique([...($refs[$kind] ?? []), ...$ids]));
            }
        }
        $graph = $this->collectPins($actor, $project, $refs);
        $graph['selection_id'] = $selectionGraph['selection_id'] ?? null;
        $snapshot = isset($pins['fix_snapshot_id']) ? app(TenantContext::class)->run($actor->tenantId, fn () => DB::table('developer_snapshots')->where('project_id', $project)->where('id', $pins['fix_snapshot_id'])->first(['id', 'content_hash', 'base_sha', 'head_sha'])) : null;
        if (isset($pins['fix_snapshot_id']) && ! $snapshot) {
            throw new NotFoundHttpException;
        }

        return app(QaCoordination::class)->run($actor, $project, ['qa.defect.review', 'qa.source.read'], true, function ($proofs) use ($actor, $project, $defect, $event, $pins, $graph, $snapshot) {
            $plans = app(QaPlanService::class);
            $rows = $plans->loadGraphLocked($project, $graph);
            if ($event['kind'] === 'verified_closed') {
                // Once observed, invalid original evidence cannot be repaired by restoring a grant.
                $proofs = app(QaInvalidation::class)->overlayLocked($project, $rows, $proofs);
                // Only this decision's lineage is mandatory; R3 evaluates other selected facts separately.
                $subjects = $this->closureDefectIds($defect, $rows);
                $this->assertCapturedHeadsLocked($graph, $rows, ['defect' => $subjects]);
                $required = $graph;
                $required['requests'] = array_values(array_filter($graph['requests'], function ($request) use ($subjects, $rows) {
                    [$kind, $id] = explode(':', $request['key']);

                    return ($kind === 'defect' && in_array($id, $subjects, true)) || ($kind === 'defect_event' && in_array($rows['defect_event'][$id]->defect_id ?? null, $subjects, true));
                }));
                $plans->assertOriginalsLocked($required, $proofs);
            } else {
                $this->assertCapturedHeadsLocked($graph, $rows);
                $plans->assertOriginalsLocked($graph, $proofs);
            }
            $row = $rows['defect'][$defect];
            $head = $rows['defect_event'][$row->current_event_id] ?? throw new ConflictHttpException;
            if ($row->revision !== $event['expected_revision']) {
                throw new ConflictHttpException;
            }
            $kind = $event['kind'];
            $state = $row->state;
            $affected = array_intersect_key((array) $head, array_flip(['criterion_id', 'criterion_version_id', 'definition_id', 'build_id', 'environment_id', 'task_version_id']));
            $replacement = $this->affectedPins($pins);
            $scopeChanged = array_diff_assoc($replacement, $affected) !== [];
            if ($scopeChanged && ! ($kind === 'scope_revised' || ($kind === 'reopened' && ($pins['cause'] ?? null) === 'new_scope'))) {
                throw new ConflictHttpException;
            }
            if ($kind === 'triaged' && in_array($state, ['open', 'reopened'], true)) {
                $state = 'triaged';
            } elseif ($kind === 'fix_requested' && in_array($state, ['triaged', 'fixed_awaiting_retest'], true)) {
                if (! $snapshot) {
                    throw new ConflictHttpException;
                }
                $this->assertAffectedLocked($project, $affected, $rows);
                $state = 'fixed_awaiting_retest';
            } elseif ($kind === 'verified_closed' && $state === 'fixed_awaiting_retest') {
                $this->assertClosureLocked($project, $row, $affected, $pins, $graph, $rows, $proofs);
                $state = 'verified_closed';
            } elseif ($kind === 'reopened' && $state === 'verified_closed') {
                if (! isset($pins['cause']) || ! $this->hasEvidence($pins)) {
                    throw new ConflictHttpException;
                }
                if (($pins['cause'] ?? null) === 'new_scope') {
                    $this->assertScopeLocked($project, $row, $affected, $replacement, $rows, $pins);
                    $affected = array_replace($affected, $replacement);
                }
                $state = 'reopened';
            } elseif ($kind === 'scope_revised' && in_array($state, ['open', 'triaged', 'fixed_awaiting_retest', 'reopened'], true)) {
                $this->assertScopeLocked($project, $row, $affected, $replacement, $rows, $pins);
                $affected = array_replace($affected, $replacement);
            } elseif ($kind === 'duplicate') {
                $this->assertDuplicateLocked($defect, $pins['canonical_defect_id'] ?? null, $rows);
            } elseif ($kind !== 'context_added') {
                throw new ConflictHttpException;
            }
            if ($kind === 'context_added' && array_diff(array_keys($pins), ['execution_id', 'fix_snapshot_id'])) {
                throw new ConflictHttpException;
            }
            $details = array_intersect_key($pins, array_flip(['selection_id', 'execution_id', 'fix_snapshot_id', 'fix_request_event_id', 'cause']));
            if (isset($details['execution_id'])) {
                $this->assertExecutionLocked($details['execution_id'], $affected, $rows);
            }
            if ($kind === 'scope_revised') {
                $details['cause'] = 'new_scope';
            }
            app(QaReceiptWriter::class)->admit($project);

            return $this->appendLocked($actor, $project, $defect, $row->revision + 1, $kind, $state, $kind === 'duplicate' ? 'duplicate' : $row->disposition, $kind === 'duplicate' ? $pins['canonical_defect_id'] : $row->canonical_defect_id, $affected + $details, $event['reason'], $proofs['current']['proof']);
        }, $graph['requests']);
    }

    /** Scoped hints collect both original and current proofs once, before the closed authority phase. */
    public function collectPins(TrustedActor $actor, string $project, array $refs, ?string $source = null, ?string $operation = null, ?string $key = null, ?array $envelope = null, bool $includeHistoricalExecutions = false): array
    {
        return app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $refs, $source, $operation, $key, $envelope, $includeHistoricalExecutions) {
            $capturedHeads = ['defect' => [], 'handoff' => []];
            $add = function (string $kind, array $ids) use (&$refs) {
                $refs[$kind] = array_values(array_unique([...($refs[$kind] ?? []), ...array_filter($ids)]));
            };
            if ($operation) {
                $old = DB::table('qa_request_keys')->where(['project_id' => $project, 'requester_id' => $actor->id, 'operation' => $operation, 'key' => $key])->first();
                $receiptId = $old?->receipt_id;
                if ($envelope) {
                    $receiptId ??= DB::table('qa_receipts')->where('project_id', $project)->where('source_instance_id', $envelope['source_instance_id'])->where('object_id', $envelope['object_id'])->where('source_revision', $envelope['source_revision'])->value('id');
                }
                if ($receiptId) {
                    $add('receipt', [$receiptId]);
                    $table = $operation === 'defect.report' ? 'qa_defects' : 'qa_handoffs';
                    $kind = $operation === 'defect.report' ? 'defect' : 'handoff';
                    $add($kind, DB::table($table)->where('project_id', $project)->where('receipt_id', $receiptId)->pluck('id')->all());
                }
            }
            $handoffs = DB::table('qa_handoffs')->where('project_id', $project)->whereIn('id', $refs['handoff'] ?? [])->get();
            foreach ($handoffs as $handoff) {
                $capturedHeads['handoff'][$handoff->id] = ['revision' => $handoff->revision, 'current_event_id' => $handoff->current_event_id];
            }
            $add('receipt', $handoffs->pluck('receipt_id')->all());
            $add('handoff_event', $handoffs->pluck('current_event_id')->all());
            $add('task_version', $handoffs->pluck('task_version_id')->all());
            $add('criterion_version', $handoffs->pluck('criterion_version_id')->all());
            $add('execution', $handoffs->pluck('execution_id')->all());
            $add('defect', $handoffs->pluck('defect_id')->all());
            $handoffEvents = DB::table('qa_handoff_events')->where('project_id', $project)->whereIn('id', $refs['handoff_event'] ?? [])->get(['execution_id', 'defect_event_id']);
            $add('execution', $handoffEvents->pluck('execution_id')->all());
            $add('defect_event', $handoffEvents->pluck('defect_event_id')->all());
            $add('defect', DB::table('qa_defect_events')->where('project_id', $project)->whereIn('id', $refs['defect_event'] ?? [])->pluck('defect_id')->all());
            // Canonical chains are bounded; overflow denies the whole decision rather than hiding a cycle.
            $frontier = $refs['defect'] ?? [];
            $seen = [];
            while ($frontier) {
                $defects = DB::table('qa_defects')->where('project_id', $project)->whereIn('id', $frontier)->get(['id', 'revision', 'receipt_id', 'current_event_id', 'canonical_defect_id', 'execution_id']);
                foreach ($defects as $d) {
                    $seen[$d->id] = true;
                    $capturedHeads['defect'][$d->id] = ['revision' => $d->revision, 'current_event_id' => $d->current_event_id];
                }
                if (count($seen) > 100) {
                    throw new ConflictHttpException;
                }
                $add('defect_event', $defects->pluck('current_event_id')->all());
                $add('receipt', $defects->pluck('receipt_id')->all());
                if ($includeHistoricalExecutions) {
                    $add('execution', $defects->pluck('execution_id')->all());
                }
                $frontier = array_values(array_diff(array_filter($defects->pluck('canonical_defect_id')->all()), array_keys($seen)));
                $add('defect', $frontier);
            }
            $heads = DB::table('qa_defect_events')->where('project_id', $project)->whereIn('id', $refs['defect_event'] ?? [])->get();
            $add('execution', $heads->pluck('execution_id')->all());
            $add('defect_event', $heads->pluck('fix_request_event_id')->all());
            foreach (['definition' => 'definition_id', 'build' => 'build_id', 'environment' => 'environment_id', 'task_version' => 'task_version_id', 'criterion_version' => 'criterion_version_id'] as $kind => $field) {
                $add($kind, $heads->pluck($field)->all());
            }
            $events = DB::table('qa_defect_events')->where('project_id', $project)->whereIn('defect_id', $refs['defect'] ?? [])->whereIn('event_type', ['fix_requested', 'scope_revised', 'reopened'])->selectRaw('distinct on (defect_id,event_type) id,defect_id,event_type,revision')->orderBy('defect_id')->orderBy('event_type')->orderByDesc('revision')->get();
            foreach ($events->groupBy('defect_id') as $group) {
                $fix = $group->firstWhere('event_type', 'fix_requested');
                if ($fix) {
                    $add('defect_event', [$fix->id]);
                }
            }
            $executions = DB::table('qa_execution_versions')->where('project_id', $project)->whereIn('id', $refs['execution'] ?? [])->get(['receipt_id']);
            $add('receipt', $executions->pluck('receipt_id')->all());

            return app(QaPlanService::class)->collectPins($actor, $project, $refs, $source ? [$source] : []) + ['heads' => $capturedHeads];
        });
    }

    /** Compare consumed mutable parents with the heads whose original event proofs were captured. */
    public function assertCapturedHeadsLocked(array $graph, array $rows, ?array $subjects = null): void
    {
        foreach (['defect', 'handoff'] as $kind) {
            foreach ($rows[$kind] ?? [] as $id => $row) {
                if ($subjects !== null && ! in_array($id, $subjects[$kind] ?? [], true)) {
                    continue;
                }
                $captured = $graph['heads'][$kind][$id] ?? null;
                if (! $captured || $captured['revision'] !== $row->revision || $captured['current_event_id'] !== $row->current_event_id) {
                    throw new ConflictHttpException;
                }
            }
        }
    }

    private function closureDefectIds(string $defect, array $rows): array
    {
        $ids = [];
        while ($defect) {
            if (in_array($defect, $ids, true) || count($ids) >= 100) {
                throw new ConflictHttpException;
            }
            $ids[] = $defect;
            $defect = ($rows['defect'][$defect] ?? throw new ConflictHttpException)->canonical_defect_id;
        }

        return $ids;
    }

    private function assertClosureLocked(string $project, object $defect, array $affected, array $pins, array $graph, array $rows, array $proofs): void
    {
        if (! isset($pins['execution_id'], $pins['fix_request_event_id'], $pins['selection_id']) || $pins['selection_id'] !== $graph['selection_id']) {
            throw new ConflictHttpException;
        }
        $this->assertAffectedLocked($project, $affected, $rows);
        $latest = DB::table('qa_defect_events')->where('project_id', $project)->where('defect_id', $defect->id)->whereIn('event_type', ['fix_requested', 'scope_revised', 'reopened'])->orderByDesc('revision')->first();
        if (! $latest || $latest->event_type !== 'fix_requested' || $latest->id !== $pins['fix_request_event_id'] || ! isset($rows['defect_event'][$latest->id])) {
            throw new ConflictHttpException;
        }
        foreach (['criterion_version_id', 'definition_id', 'build_id', 'environment_id', 'task_version_id'] as $field) {
            if ($latest->$field !== ($affected[$field] ?? null)) {
                throw new ConflictHttpException;
            }
        }
        $eligibility = app(QaEligibility::class);
        $inputs = $eligibility->loadInputsLocked($project, $graph);
        $facts = $eligibility->inspectLocked($project, $inputs, $proofs);
        foreach ($facts['criteria'] as $criterion) {
            if (! $criterion['included'] || $criterion['criterion_version_id'] !== $affected['criterion_version_id']) {
                continue;
            }
            foreach ($criterion['tests'] as $test) {
                if ($test['definition_version_id'] !== $affected['definition_id'] || $test['execution_id'] !== $pins['execution_id'] || $test['status'] !== 'passed') {
                    continue;
                }
                $attempt = $inputs['attempts'][$test['attempt_id']] ?? null;
                $execution = $rows['execution'][$pins['execution_id']] ?? null;
                if ($attempt && $execution && $attempt->fix_request_event_id === $latest->id && (json_decode($rows['receipt'][$execution->receipt_id]->envelope, true)['body']['task_version_id'] ?? null) === $affected['task_version_id'] && $facts['selection']['build_id'] === $affected['build_id'] && $facts['selection']['environment_id'] === $affected['environment_id']) {
                    return;
                }
            }
        }
        throw new ConflictHttpException;
    }

    private function assertExecutionLocked(string $executionId, array $affected, array $rows): void
    {
        $execution = $rows['execution'][$executionId] ?? throw new ConflictHttpException;
        foreach (['criterion_version_id', 'definition_id', 'build_id', 'environment_id'] as $field) {
            if ($affected[$field] !== $execution->$field) {
                throw new ConflictHttpException;
            }
        }
        $receipt = $rows['receipt'][$execution->receipt_id] ?? throw new ConflictHttpException;
        if ((json_decode($receipt->envelope, true)['body']['task_version_id'] ?? null) !== $affected['task_version_id']) {
            throw new ConflictHttpException;
        }
    }

    private function assertAffectedLocked(string $project, array $pins, array $rows): void
    {
        $definition = $rows['definition'][$pins['definition_id']] ?? throw new ConflictHttpException;
        if ($definition->criterion_version_id !== $pins['criterion_version_id'] || $definition->criterion_id !== $pins['criterion_id'] || ! isset($pins['task_version_id']) || ! DB::table('task_criterion_links')->where('project_id', $project)->where('task_version_id', $pins['task_version_id'])->where('criterion_version_id', $pins['criterion_version_id'])->exists()) {
            throw new ConflictHttpException;
        }
        // Historical pins remain readable, but only the explicitly affected current subset drives a new decision.
        $current = [];
        foreach (['definition' => $pins['definition_id'], 'build' => $pins['build_id'], 'environment' => $pins['environment_id'], 'criterion_version' => $pins['criterion_version_id']] as $kind => $id) {
            $current[$kind][$id] = $rows[$kind][$id] ?? throw new ConflictHttpException;
            if (isset($current[$kind][$id]->receipt_id)) {
                $receipt = $current[$kind][$id]->receipt_id;
                $current['receipt'][$receipt] = $rows['receipt'][$receipt] ?? throw new ConflictHttpException;
            }
        }
        $current['review'] = $rows['review'] ?? [];
        app(QaPlanService::class)->assertCurrentLocked($project, $current);
        $task = $rows['task_version'][$pins['task_version_id']] ?? throw new ConflictHttpException;
        if (DB::table('lifecycle_tasks')->where('project_id', $project)->where('id', $task->task_id)->value('current_version_id') !== $task->id) {
            throw new ConflictHttpException;
        }
    }

    private function assertScopeLocked(string $project, object $defect, array $old, array $replacement, array $rows, array $pins): void
    {
        if (array_diff(array_values(self::PIN_FIELDS), array_keys($replacement)) || ! $this->hasEvidence($pins) || ($pins['cause'] ?? null) !== 'new_scope' || ! array_diff_assoc($replacement, $old)) {
            throw new ConflictHttpException;
        }
        $original = DB::table('qa_definition_versions')->where('project_id', $project)->where('id', $defect->definition_id)->first(['test_identity_id']);
        $definition = $rows['definition'][$replacement['definition_id']] ?? throw new ConflictHttpException;
        if ($definition->criterion_id !== $defect->criterion_id || $definition->test_identity_id !== $original?->test_identity_id) {
            throw new ConflictHttpException;
        }
        $this->assertAffectedLocked($project, ['criterion_id' => $defect->criterion_id] + $replacement, $rows);
    }

    private function assertDuplicateLocked(string $defect, ?string $target, array $rows): void
    {
        if (! $target || $target === $defect) {
            throw new ConflictHttpException;
        }
        $current = $target;
        $seen = [$defect];
        while ($current) {
            if (in_array($current, $seen, true) || count($seen) > 100) {
                throw new ConflictHttpException;
            }
            $seen[] = $current;
            $row = $rows['defect'][$current] ?? throw new ConflictHttpException;
            if ($row->state === 'open' || $row->state === 'reopened') {
                throw new ConflictHttpException;
            }
            $current = $row->canonical_defect_id;
        }
    }

    private function appendLocked(TrustedActor $actor, string $project, string $defect, int $revision, string $kind, string $state, string $disposition, ?string $canonical, array $pins, string $reason, array $proof): array
    {
        $id = (string) Str::uuid();
        DB::table('qa_defect_events')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'defect_id' => $defect, 'revision' => $revision, 'event_type' => $kind, 'state' => $state, 'disposition' => $disposition, 'canonical_defect_id' => $canonical, 'reason' => $reason, 'actor_id' => $actor->id, 'authority_proof' => QaContract::canonical($proof)] + $pins);
        DB::table('qa_defects')->where('project_id', $project)->where('id', $defect)->update(['revision' => $revision, 'current_event_id' => $id, 'state' => $state, 'disposition' => $disposition, 'canonical_defect_id' => $canonical]);
        app(QaChangeWriter::class)->appendLocked($actor, $project, 'defect_changed', ['defect_event_id' => $id], hash('sha256', QaContract::canonical([$defect, $revision, $kind, $pins, $reason])), $proof);

        return ['id' => $id, 'revision' => $revision, 'state' => $state, 'synthetic' => true];
    }

    private function affectedPins(array $body): array
    {
        $pins = [];
        foreach (self::PIN_FIELDS as $field => $column) {
            if (isset($body[$field])) {
                $pins[$column] = $body[$field];
            }
        }

        return $pins;
    }

    private function bodyRefs(array $body): array
    {
        $refs = [];
        foreach (['criterion_version_id' => 'criterion_version', 'definition_version_id' => 'definition', 'build_id' => 'build', 'environment_version_id' => 'environment', 'task_version_id' => 'task_version', 'execution_id' => 'execution'] as $field => $kind) {
            if (isset($body[$field])) {
                $refs[$kind] = [$body[$field]];
            }
        }

        return $refs;
    }

    private function hasEvidence(array $pins): bool
    {
        return isset($pins['execution_id']) || isset($pins['fix_snapshot_id']);
    }

    private function local(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Synthetic registration is local-only.');
        }
    }
}
