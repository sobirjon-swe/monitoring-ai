<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class QaHandoffService
{
    public function open(TrustedActor $actor, string $project, array $data, string $key): array
    {
        return app(QaInvalidation::class)->guardRequest($actor, $project, 'handoff.open', $key, fn () => $this->openRequest($actor, $project, $data, $key));
    }

    private function openRequest(TrustedActor $actor, string $project, array $data, string $key): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Synthetic registration is local-only.');
        }
        $envelope = app(QaContract::class)->validate('envelope', $data);
        if ($envelope['operation'] !== 'handoff' || $envelope['source_revision'] !== 1 || $envelope['occurred_at'] !== $envelope['body']['occurred_at']) {
            throw new ConflictHttpException;
        }
        $body = $envelope['body'];
        $refs = ['task_version' => [$body['task_version_id']], 'criterion_version' => [$body['criterion_version_id']]];
        foreach (['execution_id' => 'execution', 'defect_id' => 'defect', 'linked_handoff_id' => 'handoff'] as $field => $kind) {
            if (isset($body[$field])) {
                $refs[$kind] = [$body[$field]];
            }
        }
        $graph = app(QaDefectService::class)->collectPins($actor, $project, $refs, $envelope['source_instance_id'], 'handoff.open', $key, $envelope);
        $graph['requests'][] = ['key' => 'responsibility', 'actor_id' => $actor->id, 'purposes' => ['qa.handoff.manage', 'qa.source.read'], 'required' => true, 'responsibility_refs' => [['task_version_id' => $body['task_version_id'], 'occurred_at' => $body['occurred_at']] + (isset($body['actor_identity_id']) ? ['identity_id' => $body['actor_identity_id']] : [])]];
        $graph['requests'][] = ['key' => 'owner', 'actor_id' => $actor->id, 'purposes' => ['qa.handoff.manage', 'qa.source.read'], 'required' => true, 'owner_refs' => [$body['owner_id']]];

        return app(QaCoordination::class)->run($actor, $project, ['qa.handoff.manage', 'qa.source.read'], true, function ($proofs) use ($actor, $project, $body, $envelope, $key, $graph) {
            $plans = app(QaPlanService::class);
            $rows = $plans->loadGraphLocked($project, $graph);
            app(QaDefectService::class)->assertCapturedHeadsLocked($graph, $rows);
            $plans->assertOriginalsLocked($graph, $proofs);
            $writer = app(QaReceiptWriter::class);
            $receipt = $writer->recordLocked($actor, $project, $envelope, $key, 0, $proofs['current']['proof']);
            if ($receipt['replayed']) {
                $handoff = $rows['handoff'][$receipt['record_id']] ?? throw new ConflictHttpException;
                app(QaInvalidation::class)->assertReplayCurrentLocked($project, ['receipt_id' => $receipt['receipt_id'], 'handoff_event_id' => $handoff->current_event_id]);
                $writer->bindRequestLocked($actor, $project, 'handoff.open', $key, $receipt['input_hash'], ['receipt_id' => $receipt['receipt_id']]);

                return $receipt + ['id' => $handoff->id, 'revision' => $handoff->revision, 'state' => $handoff->state, 'synthetic' => true];
            }
            $criterion = $rows['criterion_version'][$body['criterion_version_id']];
            $plans->assertCriteriaCurrentLocked($project, [$criterion->id]);
            $task = $rows['task_version'][$body['task_version_id']];
            if (DB::table('lifecycle_tasks')->where('project_id', $project)->where('id', $task->task_id)->value('current_version_id') !== $task->id || ! DB::table('task_criterion_links')->where('project_id', $project)->where('task_version_id', $task->id)->where('criterion_version_id', $criterion->id)->exists()) {
                throw new ConflictHttpException;
            }
            if (isset($body['execution_id'])) {
                $this->assertExecutionLocked($body['execution_id'], ['criterion_id' => $criterion->criterion_id, 'criterion_version_id' => $criterion->id, 'task_version_id' => $task->id], $rows);
            }
            if (isset($body['defect_id'])) {
                $defect = $rows['defect'][$body['defect_id']] ?? throw new ConflictHttpException;
                $affected = $rows['defect_event'][$defect->current_event_id] ?? throw new ConflictHttpException;
                if ($affected->criterion_id !== $criterion->criterion_id || $affected->criterion_version_id !== $criterion->id || $affected->task_version_id !== $task->id) {
                    throw new ConflictHttpException;
                }
            }
            if (isset($body['linked_handoff_id'])) {
                $linked = $rows['handoff'][$body['linked_handoff_id']] ?? throw new ConflictHttpException;
                if ($linked->criterion_id !== $criterion->criterion_id) {
                    throw new ConflictHttpException;
                }
            }
            $this->assertOwnerCaptured($body['owner_id'], $proofs);
            $responsibility = app(QaResponsibility::class)->captureLocked($project, $task->id, $body['occurred_at'], $body['actor_identity_id'] ?? null);
            $id = (string) Str::uuid();
            DB::table('qa_handoffs')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'receipt_id' => $receipt['receipt_id'], 'task_version_id' => $task->id, 'criterion_id' => $criterion->criterion_id, 'criterion_version_id' => $criterion->id, 'execution_id' => $body['execution_id'] ?? null, 'defect_id' => $body['defect_id'] ?? null, 'linked_handoff_id' => $body['linked_handoff_id'] ?? null, 'handoff_type' => $body['handoff_type'], 'responsibility' => QaContract::canonical($responsibility), 'opened_at' => $body['occurred_at'], 'owner_id' => $body['owner_id']]);
            $this->appendLocked($actor, $project, $id, 1, 'opened', 'open', $body['owner_id'], ['execution_id' => $body['execution_id'] ?? null], $body['reason'], $proofs['current']['proof']);
            $writer->bindRequestLocked($actor, $project, 'handoff.open', $key, $receipt['input_hash'], ['receipt_id' => $receipt['receipt_id']]);

            return array_replace($receipt, ['record_id' => $id]) + ['id' => $id, 'revision' => 1, 'state' => 'open', 'synthetic' => true];
        }, $graph['requests']);
    }

    public function transition(TrustedActor $actor, string $project, string $handoff, array $event): array
    {
        $event = app(QaContract::class)->validate('handoff.transition', $event);
        $pins = $event['pins'];
        $refs = ['handoff' => [$handoff]];
        foreach (['execution_id' => 'execution', 'defect_event_id' => 'defect_event'] as $field => $kind) {
            if (isset($pins[$field])) {
                $refs[$kind] = [$pins[$field]];
            }
        }
        $graph = app(QaDefectService::class)->collectPins($actor, $project, $refs);
        if ($event['kind'] === 'owner_changed' && isset($pins['owner_id'])) {
            $graph['requests'][] = ['key' => 'owner', 'actor_id' => $actor->id, 'purposes' => ['qa.handoff.manage', 'qa.source.read'], 'required' => true, 'owner_refs' => [$pins['owner_id']]];
        }

        return app(QaCoordination::class)->run($actor, $project, ['qa.handoff.manage', 'qa.source.read'], true, function ($proofs) use ($actor, $project, $handoff, $event, $pins, $graph) {
            $plans = app(QaPlanService::class);
            $rows = $plans->loadGraphLocked($project, $graph);
            $proofs = app(QaInvalidation::class)->overlayLocked($project, $rows, $proofs);
            app(QaDefectService::class)->assertCapturedHeadsLocked($graph, $rows);
            $plans->assertOriginalsLocked($graph, $proofs);
            $row = $rows['handoff'][$handoff];
            if ($row->revision !== $event['expected_revision'] || ($row->state === 'resolved' && $event['kind'] !== 'context_added')) {
                throw new ConflictHttpException;
            }
            $kind = $event['kind'];
            $state = $row->state;
            $owner = $row->owner_id;
            if ($kind === 'acknowledged' && $state === 'open') {
                $state = 'acknowledged';
            } elseif ($kind === 'resolved' && in_array($state, ['open', 'acknowledged'], true)) {
                if (! isset($pins['execution_id']) && ! isset($pins['defect_event_id'])) {
                    throw new ConflictHttpException;
                }
                $state = 'resolved';
            } elseif ($kind === 'owner_changed' && in_array($state, ['open', 'acknowledged'], true)) {
                $owner = $pins['owner_id'] ?? throw new ConflictHttpException;
                $this->assertOwnerCaptured($owner, $proofs);
            } elseif ($kind !== 'context_added') {
                throw new ConflictHttpException;
            }
            if ($kind !== 'owner_changed' && isset($pins['owner_id'])) {
                throw new ConflictHttpException;
            }
            if (isset($pins['execution_id'])) {
                $this->assertExecutionLocked($pins['execution_id'], (array) $row, $rows);
            }
            if (isset($pins['defect_event_id'])) {
                $defectEvent = $rows['defect_event'][$pins['defect_event_id']] ?? throw new ConflictHttpException;
                if ($defectEvent->defect_id !== $row->defect_id || $defectEvent->criterion_version_id !== $row->criterion_version_id || $defectEvent->task_version_id !== $row->task_version_id) {
                    throw new ConflictHttpException;
                }
            }
            app(QaReceiptWriter::class)->admit($project);

            return $this->appendLocked($actor, $project, $handoff, $row->revision + 1, $kind, $state, $owner, array_intersect_key($pins, array_flip(['execution_id', 'defect_event_id'])), $event['reason'], $proofs['current']['proof']);
        }, $graph['requests']);
    }

    public function history(TrustedActor $actor, string $project, string $handoff, int $limit = 50, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > 100 || ($cursor !== null && (! preg_match('/\A[0-9]{1,18}\z/D', $cursor) || strlen($cursor) > 128))) {
            throw new \InvalidArgumentException('Invalid QA history page.');
        }
        $ids = app(TenantContext::class)->run($actor->tenantId, fn () => DB::table('qa_handoff_events')->where('project_id', $project)->where('handoff_id', $handoff)->where('revision', '>', $cursor ?? 0)->orderBy('revision')->limit($limit + 1)->pluck('id')->all());
        $graph = app(QaDefectService::class)->collectPins($actor, $project, ['handoff' => [$handoff], 'handoff_event' => $ids]);

        return app(QaCoordination::class)->run($actor, $project, ['qa.read', 'qa.source.read'], false, function ($proofs) use ($project, $handoff, $limit, $cursor, $ids, $graph) {
            $plans = app(QaPlanService::class);
            $rows = $plans->loadGraphLocked($project, $graph);
            app(QaDefectService::class)->assertCapturedHeadsLocked($graph, $rows);
            $plans->assertOriginalsLocked($graph, $proofs);
            $row = $rows['handoff'][$handoff];
            $events = DB::table('qa_handoff_events')->where('project_id', $project)->where('handoff_id', $handoff)->where('revision', '>', $cursor ?? 0)->orderBy('revision')->limit($limit + 1)->get();
            if ($events->pluck('id')->all() !== $ids) {
                throw new ConflictHttpException;
            }
            $hasMore = $events->count() > $limit;
            $events = $events->take($limit)->map(fn ($event) => array_intersect_key((array) $event, array_flip(['id', 'revision', 'event_type', 'state', 'owner_id', 'execution_id', 'defect_event_id', 'reason', 'effective_at'])) + ['responsibility' => json_decode($row->responsibility, true), 'opened_at' => $row->opened_at])->all();

            return ['id' => $handoff, 'revision' => $row->revision, 'state' => $row->state, 'events' => $events, 'next_cursor' => $hasMore ? (string) end($events)['revision'] : null, 'age' => app(QaResponsibility::class)->elapsedAge($row->opened_at, DB::scalar('select clock_timestamp()')), 'synthetic' => true, 'ai_interpretation' => 'unavailable'];
        }, $graph['requests']);
    }

    private function assertExecutionLocked(string $executionId, array $affected, array $rows): void
    {
        $execution = $rows['execution'][$executionId] ?? throw new ConflictHttpException;
        $receipt = $rows['receipt'][$execution->receipt_id] ?? throw new ConflictHttpException;
        if ($execution->criterion_id !== $affected['criterion_id'] || $execution->criterion_version_id !== $affected['criterion_version_id'] || (json_decode($receipt->envelope, true)['body']['task_version_id'] ?? null) !== $affected['task_version_id']) {
            throw new ConflictHttpException;
        }
    }

    private function assertOwnerCaptured(string $owner, array $proofs): void
    {
        $captured = $proofs['owner']['owners'][$owner] ?? null;
        if (! ($proofs['owner']['valid'] ?? false) || ($captured['employee_id'] ?? null) !== $owner || ($captured['membership']['employee_id'] ?? null) !== $owner) {
            throw new ConflictHttpException;
        }
    }

    private function appendLocked(TrustedActor $actor, string $project, string $handoff, int $revision, string $kind, string $state, string $owner, array $pins, string $reason, array $proof): array
    {
        $id = (string) Str::uuid();
        DB::table('qa_handoff_events')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'handoff_id' => $handoff, 'revision' => $revision, 'event_type' => $kind, 'state' => $state, 'owner_id' => $owner, 'reason' => $reason, 'actor_id' => $actor->id, 'authority_proof' => QaContract::canonical($proof)] + $pins);
        DB::table('qa_handoffs')->where('project_id', $project)->where('id', $handoff)->update(['revision' => $revision, 'current_event_id' => $id, 'state' => $state, 'owner_id' => $owner]);
        app(QaChangeWriter::class)->appendLocked($actor, $project, 'handoff_changed', ['handoff_event_id' => $id], hash('sha256', QaContract::canonical([$handoff, $revision, $kind, $pins, $reason, $owner])), $proof);

        return ['id' => $id, 'revision' => $revision, 'state' => $state, 'synthetic' => true];
    }
}
