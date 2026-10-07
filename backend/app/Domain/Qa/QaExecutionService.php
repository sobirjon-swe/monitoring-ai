<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class QaExecutionService
{
    public function register(TrustedActor $actor, string $project, array $envelope, string $key, int $expectedSourceRevision, int $expectedSeriesRevision): array
    {
        return app(QaInvalidation::class)->guardRequest($actor, $project, 'execution', $key, fn () => $this->registerRequest($actor, $project, $envelope, $key, $expectedSourceRevision, $expectedSeriesRevision));
    }

    private function registerRequest(TrustedActor $actor, string $project, array $envelope, string $key, int $expectedSourceRevision, int $expectedSeriesRevision): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Synthetic registration is local-only.');
        }
        $envelope = app(QaContract::class)->validate('envelope', $envelope);
        $body = $envelope['body'];
        if ($envelope['operation'] !== 'execution' || $expectedSourceRevision < 0 || $expectedSeriesRevision < 0 || ($body['outcome'] === 'blocked' && trim($body['blocked_reason'] ?? '') === '') || ($body['outcome'] !== 'blocked' && isset($body['blocked_reason']))) {
            throw new InvalidArgumentException('Invalid QA execution.');
        }
        $refs = ['definition' => [$body['definition_version_id']], 'build' => [$body['build_id']], 'environment' => [$body['environment_version_id']], 'task_version' => [$body['task_version_id']]];
        $hint = app(TenantContext::class)->run($actor->tenantId, function () use ($project, $envelope, $body, &$refs) {
            $sourceHead = DB::table('qa_source_heads')->where('project_id', $project)->where('source_instance_id', $envelope['source_instance_id'])->where('object_id', $envelope['object_id'])->first();
            $receipt = DB::table('qa_receipts')->where('project_id', $project)->where('source_instance_id', $envelope['source_instance_id'])->where('object_id', $envelope['object_id'])->where('source_revision', $envelope['source_revision'])->value('id');
            if ($receipt) {
                $refs['receipt'] = [$receipt];
                $refs['execution'] = DB::table('qa_execution_versions')->where('project_id', $project)->where('receipt_id', $receipt)->pluck('id')->all();
            }
            $series = $this->seriesQuery($project, $envelope)->first();
            $attempt = $series ? DB::table('qa_execution_attempts')->where('project_id', $project)->where('series_id', $series->id)->where('execution_key', $envelope['object_id'])->first() : null;
            $ids = array_filter([$attempt?->id, $body['predecessor_attempt_id'] ?? null, $body['retests_execution_id'] ?? null]);
            $origins = DB::table('qa_execution_attempts')->where('project_id', $project)->whereIn('id', $ids)->get();
            $refs['execution'] = array_values(array_unique([...($refs['execution'] ?? []), ...$origins->pluck('current_version_id')->filter()->all()]));
            $refs['receipt'] = array_values(array_unique(array_filter([...($refs['receipt'] ?? []), $sourceHead?->receipt_id, ...DB::table('qa_execution_versions')->where('project_id', $project)->whereIn('id', $refs['execution'])->pluck('receipt_id')->all()])));
            if (isset($body['fix_request_event_id'])) {
                $refs['defect_event'] = [$body['fix_request_event_id']];
                $refs['defect'] = DB::table('qa_defect_events')->where('project_id', $project)->where('id', $body['fix_request_event_id'])->pluck('defect_id')->all();
                $refs['receipt'] = array_values(array_unique([...$refs['receipt'], ...DB::table('qa_defects')->where('project_id', $project)->whereIn('id', $refs['defect'])->pluck('receipt_id')->all()]));
            }
            $optionalExecutions = $origins->where('id', '!=', $attempt?->id)->pluck('current_version_id')->filter()->all();
            $optionalReceipts = DB::table('qa_execution_versions')->where('project_id', $project)->whereIn('id', $optionalExecutions)->pluck('receipt_id')->all();

            return ['replay_receipt_id' => $receipt, 'series_id' => $series?->id, 'attempt_id' => $attempt?->id, 'current_version_id' => $attempt?->current_version_id, 'source_receipt_id' => $sourceHead?->receipt_id, 'optional_execution_ids' => $optionalExecutions, 'optional_receipt_ids' => $optionalReceipts];
        });
        $plans = app(QaPlanService::class);
        $graph = $plans->collectPins($actor, $project, $refs, [$envelope['source_instance_id']]);
        $graph['requests'][] = ['key' => 'responsibility', 'actor_id' => $actor->id, 'purposes' => ['qa.execution.record', 'qa.source.read'], 'required' => true, 'responsibility_refs' => [['task_version_id' => $body['task_version_id'], 'occurred_at' => $envelope['occurred_at']] + (isset($body['actor_identity_id']) ? ['identity_id' => $body['actor_identity_id']] : [])]];

        return app(QaCoordination::class)->run($actor, $project, ['qa.execution.record', 'qa.source.read'], true, function ($proofs) use ($actor, $project, $envelope, $body, $key, $expectedSourceRevision, $expectedSeriesRevision, $plans, $graph, $hint) {
            $rows = $plans->loadGraphLocked($project, $graph);
            if ($hint['replay_receipt_id'] !== null) {
                // Preserve canonical replay denial, including observation after the unlocked guard.
                app(QaInvalidation::class)->assertReplayCurrentLocked($project, ['receipt_id' => $hint['replay_receipt_id']]);
            }
            $proofs = app(QaInvalidation::class)->overlayLocked($project, $rows, $proofs);
            // A predecessor contributes immutable ordinal lineage, never its old outcome or current authority.
            foreach ($graph['requests'] as $request) {
                [$kind, $id] = array_pad(explode(':', $request['key']), 2, null);
                $optional = ($kind === 'execution' && in_array($id, $hint['optional_execution_ids'], true)) || ($kind === 'receipt' && in_array($id, $hint['optional_receipt_ids'], true));
                if (! $optional && ! ($proofs[$request['key']]['valid'] ?? false)) {
                    throw new ConflictHttpException;
                }
            }
            $writer = app(QaReceiptWriter::class);
            $sourceHead = DB::table('qa_source_heads')->where('project_id', $project)->where('source_instance_id', $envelope['source_instance_id'])->where('object_id', $envelope['object_id'])->first();
            if ($sourceHead?->receipt_id !== $hint['source_receipt_id']) {
                throw new ConflictHttpException;
            }
            if ($sourceHead) {
                $prior = $rows['receipt'][$sourceHead->receipt_id] ?? throw new ConflictHttpException;
                $priorBody = json_decode($prior->envelope, true)['body'];
                foreach (['definition_version_id', 'build_id', 'environment_version_id', 'attempt_ordinal', 'predecessor_attempt_id', 'retests_execution_id', 'fix_request_event_id'] as $field) {
                    if ($prior->kind !== 'execution' || ($priorBody[$field] ?? null) !== ($body[$field] ?? null)) {
                        throw new ConflictHttpException;
                    }
                }
            }
            $result = $writer->recordLocked($actor, $project, $envelope, $key, $expectedSourceRevision, $proofs['current']['proof']);
            if ($result['replayed']) {
                if (! isset($rows['receipt'][$result['receipt_id']]) || ! isset($rows['execution'][$result['record_id']])) {
                    throw new ConflictHttpException;
                }
                app(QaInvalidation::class)->assertReplayCurrentLocked($project, ['receipt_id' => $result['receipt_id']]);
                $writer->bindRequestLocked($actor, $project, 'execution', $key, $result['input_hash'], ['receipt_id' => $result['receipt_id']]);

                return $result;
            }
            // New facts require current immutable source/definition pins, independently of selection.
            $plans->assertCurrentLocked($project, array_intersect_key($rows, array_flip(['definition', 'build', 'environment', 'criterion_version', 'review'])) + ['receipt' => array_filter($rows['receipt'] ?? [], fn ($r) => $r->kind !== 'execution')]);
            $definition = $rows['definition'][$body['definition_version_id']];
            if (! DB::table('task_criterion_links')->where('project_id', $project)->where('task_version_id', $body['task_version_id'])->where('criterion_id', $definition->criterion_id)->where('criterion_version_id', $definition->criterion_version_id)->exists()) {
                throw new ConflictHttpException;
            }
            $series = $this->seriesQuery($project, $envelope)->lockForUpdate()->first();
            if ($series?->id !== $hint['series_id'] || ($series?->revision ?? 0) !== $expectedSeriesRevision) {
                throw new ConflictHttpException;
            }
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
            $pins = ['criterion_id' => $definition->criterion_id, 'criterion_version_id' => $definition->criterion_version_id, 'definition_id' => $definition->id, 'build_id' => $body['build_id'], 'environment_id' => $body['environment_version_id']];
            if (isset($body['fix_request_event_id'])) {
                $request = $rows['defect_event'][$body['fix_request_event_id']] ?? throw new ConflictHttpException;
                if ($request->event_type !== 'fix_requested') {
                    throw new ConflictHttpException;
                }
                foreach ($pins + ['task_version_id' => $body['task_version_id']] as $field => $value) {
                    if ($request->{$field} !== $value) {
                        throw new ConflictHttpException;
                    }
                }
            }
            if (! $series) {
                if ($body['attempt_ordinal'] !== 1 || isset($body['predecessor_attempt_id'])) {
                    throw new ConflictHttpException;
                }
                $id = (string) Str::uuid();
                DB::table('qa_execution_series')->insert(['id' => $id, 'source_instance_id' => $envelope['source_instance_id']] + $scope + $pins);
                $series = DB::table('qa_execution_series')->find($id);
            }
            $attempt = DB::table('qa_execution_attempts')->where('project_id', $project)->where('series_id', $series->id)->where('execution_key', $envelope['object_id'])->lockForUpdate()->first();
            if ($attempt?->id !== $hint['attempt_id'] || $attempt?->current_version_id !== $hint['current_version_id']) {
                throw new ConflictHttpException;
            }
            if ($attempt) {
                if ($attempt->ordinal !== $body['attempt_ordinal'] || $attempt->predecessor_id !== ($body['predecessor_attempt_id'] ?? null) || $attempt->retests_execution_id !== ($body['retests_execution_id'] ?? null) || $attempt->fix_request_event_id !== ($body['fix_request_event_id'] ?? null)) {
                    throw new ConflictHttpException;
                }
            } else {
                if ($body['attempt_ordinal'] !== $series->current_ordinal + 1 || ($body['predecessor_attempt_id'] ?? null) !== $series->current_attempt_id) {
                    throw new ConflictHttpException;
                }
                if (isset($body['retests_execution_id'])) {
                    $origin = DB::table('qa_execution_attempts as a')->join('qa_execution_series as s', 's.id', '=', 'a.series_id')->join('qa_definition_versions as d', 'd.id', '=', 's.definition_id')->where('a.project_id', $project)->where('a.id', $body['retests_execution_id'])->first(['s.criterion_id', 'd.test_identity_id', 's.id as series_id']);
                    if (! $origin) {
                        throw new NotFoundHttpException;
                    }
                    if ($origin->criterion_id !== $definition->criterion_id || $origin->test_identity_id !== $definition->test_identity_id || $origin->series_id === $series->id) {
                        throw new ConflictHttpException;
                    }
                }
                if (isset($body['fix_request_event_id'])) {
                    $defect = $rows['defect'][$request->defect_id] ?? throw new ConflictHttpException;
                    // Context and duplicate events preserve the request; scope or lifecycle boundaries replace it.
                    $latestBoundary = DB::table('qa_defect_events')->where('project_id', $project)->where('defect_id', $defect->id)->whereIn('event_type', ['fix_requested', 'scope_revised', 'reopened'])->orderByDesc('revision')->value('id');
                    if ($latestBoundary !== $request->id || $defect->state !== 'fixed_awaiting_retest') {
                        throw new ConflictHttpException;
                    }
                }
                $id = (string) Str::uuid();
                DB::table('qa_execution_attempts')->insert(['id' => $id, 'series_id' => $series->id, 'ordinal' => $body['attempt_ordinal'], 'execution_key' => $envelope['object_id'], 'predecessor_id' => $body['predecessor_attempt_id'] ?? null, 'retests_execution_id' => $body['retests_execution_id'] ?? null, 'fix_request_event_id' => $body['fix_request_event_id'] ?? null] + $scope);
                $attempt = DB::table('qa_execution_attempts')->find($id);
                DB::table('qa_execution_series')->where('id', $series->id)->update(['revision' => $series->revision + 1, 'current_ordinal' => $body['attempt_ordinal'], 'current_attempt_id' => $id]);
            }
            $id = (string) Str::uuid();
            $responsibility = app(QaResponsibility::class)->captureLocked($project, $body['task_version_id'], $envelope['occurred_at'], $body['actor_identity_id'] ?? null);
            DB::table('qa_execution_versions')->insert(['id' => $id, 'series_id' => $series->id, 'attempt_id' => $attempt->id, 'receipt_id' => $result['receipt_id'], 'source_revision' => $envelope['source_revision'], 'outcome' => $body['outcome'], 'content_hash' => hash('sha256', QaContract::canonical($body)), 'responsibility' => QaContract::canonical($responsibility), 'occurred_at' => $envelope['occurred_at']] + $scope + $pins);
            if ($result['current']) {
                DB::table('qa_execution_attempts')->where('id', $attempt->id)->update(['revision' => $attempt->revision + 1, 'source_revision' => $envelope['source_revision'], 'current_version_id' => $id]);
            }
            $writer->bindRequestLocked($actor, $project, 'execution', $key, $result['input_hash'], ['receipt_id' => $result['receipt_id']]);
            app(QaChangeWriter::class)->appendLocked($actor, $project, 'execution_recorded', ['execution_id' => $id], $result['input_hash'], $proofs['current']['proof']);
            $result['record_id'] = $id;

            return $result;
        }, $graph['requests']);
    }

    private function seriesQuery(string $project, array $envelope): Builder
    {
        return DB::table('qa_execution_series')->where(['project_id' => $project, 'source_instance_id' => $envelope['source_instance_id'], 'definition_id' => $envelope['body']['definition_version_id'], 'build_id' => $envelope['body']['build_id'], 'environment_id' => $envelope['body']['environment_version_id']]);
    }
}
