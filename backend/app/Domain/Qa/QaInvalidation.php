<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class QaInvalidation
{
    public const COLUMNS = ['receipt' => 'receipt_id', 'definition' => 'definition_id', 'entry' => 'entry_id', 'plan' => 'plan_id', 'selection' => 'selection_id', 'execution' => 'execution_id', 'review' => 'review_id', 'defect_event' => 'defect_event_id', 'handoff_event' => 'handoff_event_id'];

    private const ORIGIN_RECEIPT = 'coalesce(c.receipt_id,x.receipt_id,case when de.revision=1 then d.receipt_id end,case when he.revision=1 then h.receipt_id end)';

    /** The producer already holds project serialization; natural aliases must consult the actual output. */
    public function assertReplayCurrentLocked(string $project, array $output): void
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.qa_authority_closed',true),'')") !== '1' || ! DB::scalar("select nullif(current_setting('app.tenant_id',true),'')")) {
            throw new LogicException('Replay validity requires closed producer coordination.');
        }
        $refs = [];
        foreach ($output as $column => $id) {
            $kind = array_search($column, self::COLUMNS, true);
            if ($kind === false || ! Str::isUuid($id)) {
                throw new LogicException('Invalid canonical replay output.');
            }
            $refs[$kind] = [$id];
        }
        if (! $refs) {
            throw new LogicException('Missing canonical replay output.');
        }
        if ($this->changesQuery($project)->join('qa_applicability as a', 'c.id', '=', 'a.change_id')->where('a.status', '<>', 'current')->where(fn ($q) => $this->subjectPredicate($q, $refs))->exists()) {
            throw new AccessDeniedHttpException;
        }
    }

    public function guardRequest(TrustedActor $actor, string $project, string $operation, string $key, Closure $work): mixed
    {
        try {
            $terminal = app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $operation, $key) {
                $request = $this->request($actor, $project, $operation, $key);
                if (! $request) {
                    return false;
                }
                $refs = $this->outputRefs($request);

                return $this->changesQuery($project)->join('qa_applicability as a', 'c.id', '=', 'a.change_id')->where('a.status', '<>', 'current')->where(fn ($q) => $this->subjectPredicate($q, $refs))->exists();
            });
            if ($terminal) {
                throw new AccessDeniedHttpException;
            }

            return $work();
        } catch (AccessDeniedHttpException|ConflictHttpException $denial) {
            $this->refreshDenied($actor, $project, $operation, $key);
            throw $denial;
        }
    }

    /** Conservative post-rollback observation; it grants no source content or delivery access. */
    public function refreshDenied(TrustedActor $actor, string $project, string $operation, string $key): void
    {
        if (! in_array($operation, QaReceiptWriter::OPERATIONS, true) || ! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $key)) {
            return;
        }
        app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $operation, $key) {
            $request = $this->request($actor, $project, $operation, $key);
            if (! $request) {
                return;
            }
            $refs = $this->outputRefs($request);
            // Producers bind the receipt FK; resolve only that retained output's scoped mutable root.
            $rootKind = match ($operation) {
                'defect.report' => 'defect', 'handoff.open' => 'handoff', default => null,
            };
            if ($rootKind && $request->receipt_id) {
                $rootId = DB::table(QaPins::TABLES[$rootKind])->where('project_id', $project)->where('receipt_id', $request->receipt_id)->value('id');
                if (! $rootId) {
                    return;
                }
                $refs[$rootKind] = [$rootId];
            }
            $graph = $rootKind ? app(QaDefectService::class)->collectPins($actor, $project, $refs) : app(QaPlanService::class)->collectPins($actor, $project, $refs);
            if (! DB::table('projects')->where('id', $project)->lockForUpdate()->first()) {
                return;
            }
            DB::table('lifecycle_project_state')->where('project_id', $project)->lockForUpdate()->first();
            DB::table('qa_project_state')->where('project_id', $project)->lockForUpdate()->first();
            $locked = $this->request($actor, $project, $operation, $key);
            if (! $locked || $locked->id !== $request->id) {
                return;
            }
            $proofs = app(QaAuthority::class)->captureBatch($actor, $project, $graph['requests']);
            DB::select("select set_config('app.qa_authority_closed','1',true)");
            try {
                $rows = app(QaPlanService::class)->loadGraphLocked($project, $graph);
                if ($rootKind) {
                    try {
                        app(QaDefectService::class)->assertCapturedHeadsLocked($graph, $rows);
                    } catch (ConflictHttpException) {
                        // A changed parent needs a fresh bounded observation, never an uncaptured head proof.
                        return;
                    }
                }
                $invalid = $this->proofSubjects($graph, $proofs);
                // Persist only failures on the exact retained output's dependency graph.
                $this->withScope($actor, $project, fn () => $this->persistLocked($invalid, 'denied:'.$request->id));
                app(QaAuthority::class)->assertEffective($proofs, DB::scalar('select clock_timestamp()'));
            } finally {
                DB::select("select set_config('app.qa_authority_closed','',true),set_config('app.qa_responsibility','',true)");
            }
        });
    }

    public function observeProofsLocked(TrustedActor $actor, string $project, array $graph, array $proofs, string $eventKey): int
    {
        $subjects = $this->proofSubjects($graph, $proofs);
        $this->withScope($actor, $project, fn () => $this->persistLocked($subjects, $eventKey));

        return count($subjects);
    }

    public function observeSubjectsLocked(TrustedActor $actor, string $project, array $subjects, string $eventKey): void
    {
        $this->withScope($actor, $project, fn () => $this->persistLocked($subjects, $eventKey));
    }

    public function persistLocked(array $invalidSubjects, string $eventKey): void
    {
        $scope = json_decode(DB::scalar("select nullif(current_setting('app.qa_invalidation',true),'')") ?? 'null', true);
        if (! $scope || DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.tenant_id',true),'')") !== $scope['tenant_id'] || DB::scalar("select nullif(current_setting('app.qa_authority_closed',true),'')") !== '1' || strlen($eventKey) > 256 || ! $eventKey || count($invalidSubjects) > QaPins::MAX_REFERENCE_ROWS * 2) {
            throw new LogicException('Invalid QA invalidation scope.');
        }
        $refs = [];
        $reasons = [];
        foreach ($invalidSubjects as $subject) {
            if (! isset(self::COLUMNS[$subject['kind'] ?? '']) || ! Str::isUuid($subject['id'] ?? '') || ! in_array($subject['reason_code'] ?? '', ['access_revoked', 'source_version_changed', 'criterion_version_changed', 'definition_version_changed', 'definition_review_changed', 'plan_review_changed', 'baseline_changed', 'selection_changed', 'task_version_changed', 'project_closed', 'execution_pins_changed'], true)) {
                throw new LogicException('Invalid typed QA invalidity.');
            }
            $refs[$subject['kind']][] = $subject['id'];
            $reasons[$subject['kind'].':'.$subject['id']] = $subject['reason_code'];
        }
        if (! $refs) {
            return;
        }
        $changes = $this->changesQuery($scope['project_id'])->where(fn ($q) => $this->subjectPredicate($q, $refs))->orderBy('c.change_seq')->selectRaw('c.*,'.self::ORIGIN_RECEIPT.' as origin_receipt_id')->get();
        $states = DB::table('qa_applicability')->where('project_id', $scope['project_id'])->whereIn('change_id', $changes->pluck('id')->all())->orderBy('change_id')->lockForUpdate()->get()->keyBy('change_id');
        foreach ($changes as $change) {
            if (($states->get($change->id)?->status ?? null) !== 'current') {
                continue;
            }
            $reason = null;
            foreach (self::COLUMNS as $kind => $column) {
                if ($change->{$column}) {
                    $reason = $reasons[$kind.':'.$change->{$column}] ?? null;
                }
            }
            $reason ??= $reasons['receipt:'.($change->origin_receipt_id ?? '')] ?? null;
            if (! $reason) {
                continue;
            }
            $id = (string) Str::uuid();
            DB::table('qa_invalidations')->insert(['id' => $id, 'tenant_id' => $scope['tenant_id'], 'project_id' => $scope['project_id'], 'change_id' => $change->id, 'input_hash' => hash('sha256', QaContract::canonical(['change' => $change->id, 'event' => $eventKey, 'reason' => $reason])), 'reason_code' => $reason, 'actor_id' => $scope['actor_id']]);
            DB::table('qa_applicability')->where('change_id', $change->id)->update(['status' => $reason === 'access_revoked' ? 'revoked' : 'stale', 'reason_code' => $reason, 'observed_at' => DB::raw('clock_timestamp()')]);
            // Observations share the reserved catch-up path; they cannot consume mutation admission.
            $this->reserveLocked($scope, $change->change_seq);
        }
    }

    public function overlayLocked(string $project, array $rows, array $proofs): array
    {
        $refs = [];
        foreach (self::COLUMNS as $kind => $column) {
            if (! empty($rows[$kind])) {
                $refs[$kind] = array_keys($rows[$kind]);
            }
        }
        if (! $refs) {
            return $proofs;
        }
        $changes = $this->changesQuery($project)->join('qa_applicability as a', 'a.change_id', '=', 'c.id')->where('a.status', '<>', 'current')->where(fn ($q) => $this->subjectPredicate($q, $refs))->selectRaw('c.*,a.reason_code,'.self::ORIGIN_RECEIPT.' as origin_receipt_id')->get();
        $receipts = [];
        $invalid = [];
        foreach ($rows as $kind => $group) {
            if (! is_array($group) || ! isset(QaPins::TABLES[$kind])) {
                continue;
            } foreach ($group as $row) {
                if ($row->receipt_id ?? null) {
                    $receipts[$row->receipt_id][] = $kind.':'.$row->id;
                }
            }
        }
        foreach ($changes as $change) {
            $affected = [];
            foreach (self::COLUMNS as $kind => $column) {
                if ($change->{$column}) {
                    $affected[] = $kind.':'.$change->{$column};
                }
            }
            if ($change->origin_receipt_id && ($change->receipt_id || $change->reason_code === 'access_revoked')) {
                $affected[] = 'receipt:'.$change->origin_receipt_id;
                $affected = [...$affected, ...($receipts[$change->origin_receipt_id] ?? [])];
            }
            foreach ($affected as $prefix) {
                $invalid[$prefix] = $change->reason_code;
            }
        }
        foreach (array_keys($proofs) as $key) {
            $parts = explode(':', $key);
            $prefix = ($parts[0] ?? '').':'.($parts[1] ?? '');
            if (isset($invalid[$prefix])) {
                $proofs[$key] = ['valid' => false, 'reason_code' => $invalid[$prefix]];
            }
        }

        return $proofs;
    }

    /** Called only in the final outbox phase of an existing trusted lifecycle mutation. */
    public function observeLocked(TrustedActor $actor, string $project, string $eventKey, array $affected): void
    {
        $binding = json_decode(DB::scalar("select nullif(current_setting('app.lifecycle_mutation',true),'')") ?? 'null', true);
        if (! $binding || $binding[0] !== $actor->tenantId || $binding[1] !== $project || $binding[2] !== $actor->id || DB::transactionLevel() === 0 || ! $eventKey || strlen($eventKey) > 256 || array_diff(array_keys($affected), ['lifecycle_mutation_id', 'task_mutation_version_id', 'criterion_version_id', 'task_version_id', 'baseline_id', 'lifecycle_change_id']) || count(array_intersect(array_keys($affected), ['lifecycle_mutation_id', 'task_mutation_version_id'])) !== 1) {
            throw new LogicException('QA hook requires trusted lifecycle binding.');
        }
        $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
        $hash = hash('sha256', QaContract::canonical(['event_key' => $eventKey, 'affected' => $affected]));
        if (DB::table('qa_changes')->where($scope)->where('origin', 'lifecycle')->where('input_hash', $hash)->exists()) {
            return;
        }
        $id = (string) Str::uuid();
        DB::table('qa_changes')->insert(['id' => $id, 'kind' => 'lifecycle_changed', 'origin' => 'lifecycle', 'input_hash' => $hash, 'actor_id' => $actor->id, 'authority_proof' => null] + $scope + $affected);
        $seq = DB::table('qa_changes')->where('id', $id)->value('change_seq');
        DB::table('qa_applicability')->insert(['id' => (string) Str::uuid(), 'change_id' => $id, 'change_seq' => $seq] + $scope);
        $this->reserveLocked($scope, $seq);
    }

    public function reserveLocked(array $scope, int $target): void
    {
        DB::statement("INSERT INTO qa_outbox (id,tenant_id,project_id,is_catchup,watermark) VALUES (?::uuid,?::uuid,?::uuid,true,?) ON CONFLICT (tenant_id,project_id) WHERE is_catchup AND status IN ('pending','processing','retry') DO UPDATE SET watermark=greatest(qa_outbox.watermark,excluded.watermark)", [(string) Str::uuid(), $scope['tenant_id'], $scope['project_id'], $target]);
    }

    /** At most twenty terminal subjects for one durable canonical change. Repeated pages skip marked rows. */
    public function affectedLocked(string $project, object $event, int $limit = 20): array
    {
        $reason = null;
        $predicate = null;
        $bindings = [];
        if ($event->criterion_version_id) {
            $reason = 'criterion_version_changed';
            $value = $event->criterion_version_id;
            $predicate = 'c.entry_id in (select id from qa_plan_entry_snapshots where criterion_version_id=?) OR c.execution_id in (select id from qa_execution_versions where criterion_version_id=?) OR c.defect_event_id in (select id from qa_defect_events where criterion_version_id=?) OR c.handoff_event_id in (select e.id from qa_handoff_events e join qa_handoffs h on h.id=e.handoff_id where h.criterion_version_id=?) OR c.receipt_id in (select receipt_id from qa_definition_versions where criterion_version_id=?)';
            $bindings = array_fill(0, 5, $value);
        } elseif ($event->task_version_id) {
            $reason = 'task_version_changed';
            $value = $event->task_version_id;
            $predicate = "c.execution_id in (select e.id from qa_execution_versions e join qa_receipts r on r.id=e.receipt_id where r.envelope->'body'->>'task_version_id'=?) OR c.defect_event_id in (select id from qa_defect_events where task_version_id=?::uuid) OR c.handoff_event_id in (select e.id from qa_handoff_events e join qa_handoffs h on h.id=e.handoff_id where h.task_version_id=?::uuid)";
            $bindings = array_fill(0, 3, $value);
        } elseif ($event->baseline_id) {
            $lifecycle = DB::table('lifecycle_changes')->where('project_id', $project)->find($event->lifecycle_mutation_id);
            $closed = $lifecycle?->subject_kind === 'project_closed';
            $reason = $closed ? 'project_closed' : 'baseline_changed';
            if ($closed) {
                $predicate = 'c.selection_id is not null';
            } else {
                $predicate = 'c.plan_id in (select id from qa_plan_versions where baseline_id<>?::uuid) OR c.entry_id in (select id from qa_plan_entry_snapshots where baseline_id<>?::uuid) OR c.selection_id in (select s.id from qa_selection_events s join qa_plan_versions p on p.id=s.plan_id where p.baseline_id<>?::uuid)';
                $bindings = array_fill(0, 3, $event->baseline_id);
            }
        } elseif ($event->receipt_id) {
            $receipt = DB::table('qa_receipts')->where('project_id', $project)->find($event->receipt_id);
            if ($receipt) {
                $reason = 'source_version_changed';
                $predicate = 'c.receipt_id in (select id from qa_receipts where source_instance_id=?::uuid AND object_id=? AND source_revision<?)';
                $bindings = [$receipt->source_instance_id, $receipt->object_id, $receipt->source_revision];
            }
        } elseif ($event->execution_id) {
            $execution = DB::table('qa_execution_versions')->where('project_id', $project)->find($event->execution_id);
            if ($execution) {
                $reason = 'source_version_changed';
                $predicate = 'c.execution_id in (select id from qa_execution_versions where attempt_id=?::uuid AND source_revision<?)';
                $bindings = [$execution->attempt_id, $execution->source_revision];
            }
        } elseif ($event->review_id) {
            $review = DB::table('qa_reviews')->where('project_id', $project)->find($event->review_id);
            if ($review?->definition_id) {
                $reason = 'definition_review_changed';
                $predicate = 'c.entry_id in (select entry_id from qa_plan_entry_definitions where definition_id=?::uuid AND definition_review_id<>?::uuid)';
                $bindings = [$review->definition_id, $review->id];
            } elseif ($review?->plan_id) {
                $reason = 'plan_review_changed';
                $predicate = 'c.selection_id in (select id from qa_selection_events where plan_id=?::uuid AND plan_review_id<>?::uuid)';
                $bindings = [$review->plan_id, $review->id];
            }
        } elseif ($event->selection_id) {
            $reason = 'selection_changed';
            $predicate = 'c.selection_id is not null AND c.selection_id<>?::uuid';
            $bindings = [$event->selection_id];
        }
        if ($predicate === null) {
            return ['subjects' => [], 'complete' => true];
        }
        if ($limit < 0 || $limit > 20) {
            throw new LogicException('Invalid maintenance bound.');
        }
        $rows = DB::table('qa_changes as c')->join('qa_applicability as a', 'a.change_id', '=', 'c.id')->where('c.project_id', $project)->where('a.status', 'current')->where('c.origin', 'qa')->where('c.change_seq', '<', $event->change_seq)->whereRaw('('.$predicate.')', $bindings)->orderBy('c.change_seq')->limit($limit + 1)->get(['c.*']);
        $subjects = [];
        foreach ($rows->take($limit) as $row) {
            foreach (self::COLUMNS as $kind => $column) {
                if ($row->{$column}) {
                    $subjects[] = ['kind' => $kind, 'id' => $row->{$column}, 'reason_code' => $reason];
                }
            }
        }

        return ['subjects' => $subjects, 'complete' => $rows->count() <= $limit];
    }

    private function proofSubjects(array $graph, array $proofs): array
    {
        $subjects = [];
        foreach ($graph['requests'] as $request) {
            $key = $request['key'];
            if (($proofs[$key]['valid'] ?? false) || ($proofs[$key]['reason_code'] ?? null) !== 'access_revoked') {
                continue;
            } [$kind,$id] = array_pad(explode(':', $key), 2, null);
            if (isset(self::COLUMNS[$kind]) && Str::isUuid($id ?? '')) {
                $subjects[$kind.':'.$id] = ['kind' => $kind, 'id' => $id, 'reason_code' => 'access_revoked'];
            }
        }

        return array_values($subjects);
    }

    private function changesQuery(string $project): Builder
    {
        return DB::table('qa_changes as c')->leftJoin('qa_execution_versions as x', 'x.id', '=', 'c.execution_id')->leftJoin('qa_defect_events as de', 'de.id', '=', 'c.defect_event_id')->leftJoin('qa_defects as d', 'd.id', '=', 'de.defect_id')->leftJoin('qa_handoff_events as he', 'he.id', '=', 'c.handoff_event_id')->leftJoin('qa_handoffs as h', 'h.id', '=', 'he.handoff_id')->where('c.project_id', $project);
    }

    private function subjectPredicate(Builder $q, array $refs): void
    {
        foreach ($refs as $kind => $ids) {
            $q->orWhereIn('c.'.self::COLUMNS[$kind], array_unique($ids));
        }
        if (! empty($refs['receipt'])) {
            $q->orWhereIn(DB::raw(self::ORIGIN_RECEIPT), array_unique($refs['receipt']));
        }
    }

    private function withScope(TrustedActor $actor, string $project, Closure $work): mixed
    {
        $previous = DB::scalar("select current_setting('app.qa_invalidation',true)");
        DB::select("select set_config('app.qa_invalidation',?,true)", [QaContract::canonical(['tenant_id' => $actor->tenantId, 'project_id' => $project, 'actor_id' => $actor->id])]);
        try {
            return $work();
        } finally {
            DB::select("select set_config('app.qa_invalidation',?,true)", [$previous ?? '']);
        }
    }

    private function request(TrustedActor $actor, string $project, string $operation, string $key): ?object
    {
        return DB::table('qa_request_keys')->where(['tenant_id' => $actor->tenantId, 'project_id' => $project, 'requester_id' => $actor->id, 'operation' => $operation, 'key' => $key])->first();
    }

    private function outputRefs(object $request): array
    {
        foreach (['receipt', 'entry', 'plan', 'defect', 'handoff'] as $kind) {
            if ($request->{$kind.'_id'}) {
                return [$kind => [$request->{$kind.'_id'}]];
            }
        }
        throw new LogicException('Invalid retained QA output.');
    }
}
