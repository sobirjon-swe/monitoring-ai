<?php

namespace App\Domain\Management;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class ManagementReceiptWriter
{
    public const SOURCE = ['action.open', 'support.record', 'support.correct', 'support.retract', 'support.decline'];

    public const HUMAN = ['action.revise', 'action.reopen', 'action.withdraw', 'assignment.set', 'context.add', 'outcome.confirm', 'outcome.reject', 'outcome.revoke'];

    public function beginSourceLocked(TrustedActor $actor, string $project, array $envelope, string $key, int $expectedSourceRevision, array $proof): array
    {
        $this->locked();
        $envelope = app(ManagementContract::class)->validate('envelope', $envelope);
        $op = $envelope['operation'];
        $this->key($key);
        if (! in_array($op, self::SOURCE, true) || $expectedSourceRevision < 0 || ! collect($proof['sources'] ?? [])->contains('id', $envelope['source_instance_id'])) {
            throw new AccessDeniedHttpException;
        }
        $this->proof($actor, $project, $proof);
        $hash = app(ManagementContract::class)->contentHash($op, $envelope);
        $series = ['tenant_id' => $actor->tenantId, 'project_id' => $project, 'source_instance_id' => $envelope['source_instance_id'], 'family' => str_starts_with($op, 'action.') ? 'action' : 'support', 'object_id' => $envelope['object_id']];
        $bound = $this->binding($actor, $project, $op, $key, $hash);
        $old = DB::table('management_receipts')->where($series)->where('source_revision', $envelope['source_revision'])->first();
        $head = DB::table('management_source_heads')->where($series)->first();
        if ($old) {
            if ($old->input_hash !== $hash || $old->operation !== $op || ($bound && $bound->receipt_id !== $old->id)) {
                throw new ConflictHttpException;
            }

            return $this->ticket($actor, $project, $op, $key, $hash, $this->output((array) $old), true) + ['current' => $head?->receipt_id === $old->id, 'complete' => $head?->receipt_id === $old->id && $head->complete];
        }
        if ($bound || ($head?->revision ?? 0) !== $expectedSourceRevision) {
            throw new ConflictHttpException;
        }
        $body = $envelope['body'];
        if ($head && $series['family'] === 'support') {
            $established = DB::table('management_support_versions')->where('project_id', $project)->where('receipt_id', $head->receipt_id)->first();
            foreach (['action_id', 'action_version_id', 'assignment_id'] as $field) {
                if (! $established || $established->{$field} !== $body[$field]) {
                    throw new ConflictHttpException;
                }
            }
        }
        $action = $op === 'action.open' ? (string) Str::uuid() : $body['action_id'];
        if ($op === 'action.open' && DB::table('management_actions')->where('project_id', $project)->where('source_instance_id', $envelope['source_instance_id'])->where('object_id', $envelope['object_id'])->exists()) {
            throw new ConflictHttpException;
        }
        $ticket = $this->newTicket($actor, $project, $op, $key, $hash, $action);
        $current = ! $head || $envelope['source_revision'] > $head->source_revision;
        $complete = $envelope['source_revision'] === ($head?->source_revision ?? 0) + 1 && ($head?->complete ?? true);
        if ($head && isset($body['predecessor_support_id'])) {
            $predecessor = DB::table('management_support_versions')->where('project_id', $project)->where('id', $body['predecessor_support_id'])->first();
            if ($current && $predecessor && $predecessor->receipt_id !== $head->receipt_id) {
                throw new ConflictHttpException;
            }
            $complete = $complete && $predecessor?->receipt_id === $head->receipt_id;
        }
        DB::table('management_receipts')->insert(['id' => $ticket['receipt_id'], 'variant' => 'source', 'operation' => $op, 'source_revision' => $envelope['source_revision'], 'input_hash' => $hash, 'envelope' => ManagementContract::canonical($envelope), 'actor_id' => $actor->id, 'authority_proof' => ManagementContract::canonical($proof), 'occurred_at' => $envelope['occurred_at'], 'source_updated_at' => $envelope['source_updated_at']] + $series + array_diff_key($ticket['output'], ['receipt_id' => true]));
        if ($current) {
            $values = ['source_revision' => $envelope['source_revision'], 'revision' => ($head?->revision ?? 0) + 1, 'receipt_id' => $ticket['receipt_id'], 'complete' => $complete];
            if ($head) {
                DB::table('management_source_heads')->where('id', $head->id)->where('revision', $head->revision)->update($values);
            } else {
                DB::table('management_source_heads')->insert(['id' => (string) Str::uuid()] + $series + $values);
            }
        }

        return $ticket + ['current' => $current, 'complete' => $complete];
    }

    public function beginHumanLocked(TrustedActor $actor, string $project, string $operation, array $body, string $key, array $proof): array
    {
        $this->locked();
        $this->key($key);
        $this->proof($actor, $project, $proof);
        if (! in_array($operation, self::HUMAN, true)) {
            throw new InvalidArgumentException;
        }
        // The route identity is supplied internally after closed wire validation.
        $action = $body['action_id'];
        $wire = $body;
        unset($wire['action_id']);
        $wire = app(ManagementContract::class)->validate($operation, $wire);
        $hash = app(ManagementContract::class)->contentHash($operation, $body);
        $bound = $this->binding($actor, $project, $operation, $key, $hash);
        if ($bound) {
            $receipt = DB::table('management_receipts')->where('project_id', $project)->where('id', $bound->receipt_id)->first();
            if (! $receipt || $receipt->action_id !== $action) {
                throw new ConflictHttpException;
            }

            return $this->ticket($actor, $project, $operation, $key, $hash, $this->output((array) $receipt), true);
        }
        $root = $this->root($project, $action);
        if ($root['revision'] !== $body['expected_revision']) {
            throw new ConflictHttpException;
        }
        $ticket = $this->newTicket($actor, $project, $operation, $key, $hash, $action);
        DB::table('management_receipts')->insert(['id' => $ticket['receipt_id'], 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'variant' => 'human', 'operation' => $operation, 'input_hash' => $hash, 'envelope' => ManagementContract::canonical($wire), 'actor_id' => $actor->id, 'authority_proof' => ManagementContract::canonical($proof)] + array_diff_key($ticket['output'], ['receipt_id' => true]));

        return $ticket;
    }

    public function bindOutputLocked(array $ticket, array $output): void
    {
        $this->locked();
        if (ManagementContract::canonical($output) !== ManagementContract::canonical($ticket['output'])) {
            throw new LogicException('Management output differs from preallocated receipt.');
        }
        $receipt = DB::table('management_receipts')->where('project_id', $ticket['project_id'])->where('id', $ticket['receipt_id'])->first();
        if (! $receipt || ManagementContract::canonical($this->output((array) $receipt)) !== ManagementContract::canonical($output)) {
            throw new ConflictHttpException;
        }
        $actor = new TrustedActor($ticket['actor_id'], $ticket['tenant_id']);
        $bound = $this->binding($actor, $ticket['project_id'], $ticket['operation'], $ticket['key'], $ticket['input_hash']);
        if (! $bound) {
            DB::table('management_request_bindings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $actor->tenantId, 'project_id' => $ticket['project_id'], 'requester_id' => $actor->id, 'operation' => $ticket['operation'], 'key' => $ticket['key'], 'input_hash' => $ticket['input_hash'], 'receipt_id' => $ticket['receipt_id']]);
        } elseif ($bound->receipt_id !== $ticket['receipt_id']) {
            throw new ConflictHttpException;
        }
    }

    /** Internal shared workflow: graphs and all named authority are captured before closure. */
    public function run(TrustedActor $actor, string $project, ?string $action, string $operation, array $wire, string $key, array $purposes, array $prospective, array $named, ?string $department, Closure $work): array
    {
        try {
            return $this->runOnce($actor, $project, $action, $operation, $wire, $key, $purposes, $prospective, $named, $department, $work);
        } catch (ConflictHttpException $conflict) {
            // A concurrent exact commit invalidates the captured head. Rebuild
            // once only when its immutable request/natural receipt now exists.
            $hash = app(ManagementContract::class)->contentHash($operation, $wire);
            $exact = app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $operation, $wire, $key, $hash) {
                $binding = $this->binding($actor, $project, $operation, $key, $hash);
                if ($binding) {
                    return true;
                }

                return isset($wire['source_instance_id']) && DB::table('management_receipts')->where('project_id', $project)->where('source_instance_id', $wire['source_instance_id'])->where('family', str_starts_with($operation, 'action.') ? 'action' : 'support')->where('object_id', $wire['object_id'])->where('source_revision', $wire['source_revision'])->where('input_hash', $hash)->where('operation', $operation)->exists();
            });
            if (! $exact) {
                throw $conflict;
            }

            return $this->runOnce($actor, $project, $action, $operation, $wire, $key, $purposes, $prospective, $named, $department, $work);
        }
    }

    private function runOnce(TrustedActor $actor, string $project, ?string $action, string $operation, array $wire, string $key, array $purposes, array $prospective, array $named, ?string $department, Closure $work): array
    {
        $this->key($key);
        $replay = app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $operation, $wire, $key) {
            $bound = $this->binding($actor, $project, $operation, $key, app(ManagementContract::class)->contentHash($operation, $wire));
            if ($bound) {
                return DB::table('management_receipts')->where('project_id', $project)->where('id', $bound->receipt_id)->first();
            }
            if (isset($wire['source_instance_id'])) {
                return DB::table('management_receipts')->where('project_id', $project)->where('source_instance_id', $wire['source_instance_id'])->where('family', str_starts_with($operation, 'action.') ? 'action' : 'support')->where('object_id', $wire['object_id'])->where('source_revision', $wire['source_revision'])->first();
            }

            return null;
        });
        if ($replay) {
            $action ??= $replay->action_id;
        }
        $boundaries = $action ? [$action] : [];
        if ($replay && app(TenantContext::class)->run($actor->tenantId, fn () => DB::table('management_actions')->where('project_id', $project)->where('id', $action)->value('current_event_id')) !== $replay->event_id) {
            $boundaries[] = ['action_id' => $action, 'event_id' => $replay->event_id];
        }
        $graph = app(ManagementPins::class)->collect($actor, $project, $boundaries, $replay ? [] : $prospective, isset($wire['source_instance_id']) ? [$wire['source_instance_id']] : []);
        if (! $replay && str_starts_with($operation, 'support.') && ($graph['coverage'] ?? '') === 'complete') {
            $graph = $this->prospectiveAssignment($actor, $project, $wire['body'], $graph);
        }
        if (($graph['coverage'] ?? 'unknown') !== 'complete') {
            if (($graph['reason_code'] ?? '') === 'source_tuple_invalid') {
                throw new ConflictHttpException;
            }
            throw new ServiceUnavailableHttpException(null, 'Management coverage unavailable.');
        }
        $graph['evaluation_subject'] = $replay ? ['event_id' => $replay->event_id, 'support_id' => $replay->support_id, 'action_version_id' => $replay->action_version_id] : ['prospective' => (bool) $prospective, 'action_version_id' => $wire['body']['action_version_id'] ?? null, 'assignment_id' => $wire['body']['assignment_id'] ?? null, 'support_id' => $wire['support_id'] ?? null];
        foreach (array_unique($named) as $i => $employee) {
            $graph['named_slots'][] = ['key' => 'named:workflow:'.$i, 'employee_id' => $employee, 'origin' => 'management', 'mandatory' => true];
            $graph['budget']['named_slots']++;
        }
        $producerNames = $named;
        if (str_starts_with($operation, 'support.') || str_starts_with($operation, 'outcome.')) {
            $producerNames = app(TenantContext::class)->run($actor->tenantId, function () use ($project, $wire, $operation) {
                if (str_starts_with($operation, 'support.')) {
                    $assignment = DB::table('management_assignments')->where('project_id', $project)->where('id', $wire['body']['assignment_id'])->first();

                    return $assignment ? [$assignment->helper_id] : [];
                }
                $support = DB::table('management_support_versions')->where('project_id', $project)->where('id', $wire['support_id'])->first();

                return $support ? array_values(array_unique([$support->helper_id, $support->recorder_id])) : [];
            });
        }
        $sourceIds = [];
        foreach ($graph['current_requests'] as $request) {
            $sourceIds = [...$sourceIds, ...($request['source_ids'] ?? [])];
        }
        $graph['current_requests'][] = ['key' => 'current:operation', 'actor_id' => $actor->id, 'purposes' => $purposes, 'required' => true, 'owner_refs' => array_values(array_unique($producerNames)), 'source_ids' => array_values(array_unique($sourceIds))];
        $graph['budget']['named_slots'] += count(array_unique($producerNames));
        if ($graph['budget']['boundaries'] > 2 || $graph['budget']['named_slots'] > min(8 * $graph['budget']['boundaries'] + 8 * $graph['budget']['references'], 2000)) {
            throw new ServiceUnavailableHttpException;
        }
        // Invalid original proofs are observed in their own complete transaction;
        // the subsequent denied mutation must not erase that durable observation.
        if ($graph['proof_slots']) {
            $observation = $graph;
            foreach ($observation['proof_slots'] as &$slot) {
                $slot['mandatory'] = false;
            }
            unset($slot);
            app(ManagementCoordination::class)->run($actor, $project, $purposes, false, $observation, function ($proofs) use ($project, $observation) {
                app(ManagementPins::class)->loadLocked($project, $observation);
                foreach ($observation['proof_slots'] as $slot) {
                    $result = $proofs[$slot['key']];
                    if (! $result['valid'] && in_array($result['reason_code'], ['access_revoked', 'role_revoked', 'source_revoked'], true)) {
                        app(ManagementTerminal::class)->observeLocked($project, 'proof', $slot['subject_id'], $slot['proof_hash'], $result['reason_code']);
                    }
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            }, $department);
        }

        return app(ManagementCoordination::class)->run($actor, $project, $purposes, true, $graph, function ($proofs) use ($project, $graph, $work) {
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            $loaded = app(ManagementPins::class)->loadLocked($project, $graph);
            if (app(ManagementTerminal::class)->overlayLocked($project, $graph)) {
                throw new AccessDeniedHttpException;
            }
            $evaluation = app(ManagementSourceProofs::class)->evaluateLocked($project, $graph, $loaded['rows'], $proofs);
            app(ManagementAuthority::class)->requireDeliveryLocked($evaluation['required_proof_keys']);
            $proof = $proofs['current:operation']['proof'];
            $result = $work($proof, $graph, $loaded['rows'], $evaluation);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $result;
        }, $department);
    }

    public function root(string $project, string $action): array
    {
        $root = DB::table('management_actions')->where('project_id', $project)->where('id', $action)->first();
        if (! $root) {
            throw new NotFoundHttpException;
        }

        return (array) $root;
    }

    private function prospectiveAssignment(TrustedActor $actor, string $project, array $body, array $graph): array
    {
        if (in_array($body['assignment_id'], $graph['refs']['management_assignments'] ?? [], true)) {
            return $graph;
        }

        return app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $body, $graph) {
            $adapter = app(ManagementSourceProofs::class);
            $assignment = $adapter->metadata($project, 'management_assignments', [$body['assignment_id']])[0] ?? null;
            if (! $assignment || $assignment['action_id'] !== $body['action_id'] || $assignment['action_version_id'] !== $body['action_version_id'] || $assignment['assignment_hash'] !== $body['assignment_hash']) {
                throw new ConflictHttpException;
            }
            $facts = ['management_assignments' => $assignment];
            if (! in_array($assignment['action_version_id'], $graph['refs']['management_action_versions'] ?? [], true)) {
                $version = $adapter->metadata($project, 'management_action_versions', [$assignment['action_version_id']])[0] ?? null;
                if (! $version || $version['action_id'] !== $body['action_id'] || $version['action_hash'] !== $body['action_hash']) {
                    throw new ConflictHttpException;
                }
                $facts['management_action_versions'] = $version;
                $set = DB::table('management_reference_sets')->where('project_id', $project)->where('action_version_id', $version['id'])->first();
                $refs = $set ? DB::table('management_references')->where('project_id', $project)->where('reference_set_id', $set->id)->orderBy('ordinal')->limit(21)->get() : collect();
                if (! $set || $set->references_hash !== $version['references_hash'] || $refs->isEmpty() || $refs->count() > 20) {
                    throw new ServiceUnavailableHttpException;
                }
                $graph['refs']['management_reference_sets'][] = $set->id;
                $graph['row_hashes']['management_reference_sets'][$set->id] = hash('sha256', ManagementContract::canonical((array) $set));
                $historical = [];
                foreach ($refs as $i => $row) {
                    if ($row->ordinal !== $i + 1) {
                        throw new ConflictHttpException;
                    }
                    $reference = array_filter(array_intersect_key((array) $row, array_flip(['kind', 'task_version_id', 'criterion_id', 'criterion_version_id', 'relation', 'requirement_version_id', 'requirement_hash', 'qa_handoff_event_id', 'qa_defect_event_id', 'qa_execution_version_id', 'ba_request_version_id', 'ba_answer_version_id', 'ba_review_id'])), fn ($v) => $v !== null);
                    $reference['expected_source_snapshot_hash'] = $row->source_snapshot_hash;
                    $reference['occurrence'] = ['phase' => 'retained', 'root_id' => $body['action_id'], 'subject_kind' => 'management_action_versions', 'subject_id' => $version['id'], 'reference_set_id' => $set->id, 'ordinal' => $row->ordinal];
                    $historical[] = $reference;
                    $graph['refs']['management_references'][] = $row->id;
                    $graph['row_hashes']['management_references'][$row->id] = hash('sha256', ManagementContract::canonical((array) $row));
                    $graph['responsibility_slots'][] = ['key' => 'responsibility:prospective-action:'.$i, 'task_version_id' => $row->task_version_id, 'occurred_at' => CarbonImmutable::parse($version['occurred_at'])->utc()->format('Y-m-d\TH:i:s.u\Z'), 'phase' => 'historical', 'mandatory' => true];
                    $graph['responsibility_owners']['responsibility:prospective-action:'.$i] = $reference['occurrence'];
                    $graph['budget']['responsibility_slots']++;
                    $graph['budget']['references']++;
                }
                $sources = [];
                foreach (['lifecycle', 'qa', 'ba'] as $family) {
                    $selected = array_values(array_filter($historical, fn ($r) => $family === 'lifecycle' ? $r['kind'] === 'lifecycle' : str_starts_with($r['kind'], $family.'_')));
                    $part = $adapter->{$family}($project, $selected, $graph['budget']);
                    if ($part['coverage'] !== 'complete') {
                        throw new ServiceUnavailableHttpException;
                    }
                    foreach (['references', 'proof_slots', 'named_slots', 'responsibility_slots'] as $field) {
                        $graph[$field] = [...$graph[$field], ...$part[$field]];
                    }
                    foreach ($part['refs'] as $table => $ids) {
                        $graph['refs'][$table] = array_values(array_unique([...($graph['refs'][$table] ?? []), ...$ids]));
                        sort($graph['refs'][$table], SORT_STRING);
                    }
                    foreach (['heads', 'row_hashes', 'original_source_proof_hashes'] as $field) {
                        foreach ($part[$field] ?? [] as $table => $values) {
                            $graph[$field][$table] = ($graph[$field][$table] ?? []) + $values;
                        }
                    }
                    $graph['responsibility_owners'] = ($graph['responsibility_owners'] ?? []) + ($part['responsibility_owners'] ?? []);
                    $sources = [...$sources, ...$part['source_ids']];
                }
                foreach ($graph['current_requests'] as &$request) {
                    if ($request['key'] === 'current:sources') {
                        $request['source_ids'] = array_values(array_unique([...$request['source_ids'], ...$sources]));
                        $sources = $request['source_ids'];
                    }
                }
                unset($request);
                $connectors = DB::table('ingestion_connectors')->where('project_id', $project)->whereIn('source_instance_id', array_unique($sources))->orderBy('id')->limit(201)->get(['id', 'revision', 'status', 'adapter', 'source_instance_id']);
                $graph['budget']['source_instances'] = count(array_unique($sources));
                $graph['budget']['connector_heads'] = $connectors->count();
                foreach ($connectors as $connector) {
                    $graph['refs']['ingestion_connectors'][] = $connector->id;
                    $graph['refs']['ingestion_connectors'] = array_values(array_unique($graph['refs']['ingestion_connectors']));
                    $graph['heads']['ingestion_connectors'][$connector->id] = (array) $connector;
                }
                foreach (['qa', 'ba'] as $family) {
                    if (array_filter($historical, fn ($r) => str_starts_with($r['kind'], $family.'_')) && ! array_filter($graph['current_requests'], fn ($r) => $r['key'] === 'current:'.$family)) {
                        $graph['current_requests'][] = ['key' => 'current:'.$family, 'actor_id' => $actor->id, 'purposes' => [$family.'.read', $family.'.source.read'], 'required' => true];
                    }
                }
            }
            $receipts = [];
            foreach ($facts as $fact) {
                $receipt = $adapter->metadata($project, 'management_receipts', [$fact['receipt_id']])[0] ?? null;
                if (! $receipt) {
                    throw new ConflictHttpException;
                }
                $receipts[$receipt['id']] = $receipt;
            }
            $typed = [];
            foreach ($facts as $table => $fact) {
                $typed[] = [$table, $fact];
            }
            foreach ($receipts as $receipt) {
                $typed[] = ['management_receipts', $receipt];
            }
            foreach ($typed as [$table, $row]) {
                $graph['refs'][$table][] = $row['id'];
                $graph['refs'][$table] = array_values(array_unique($graph['refs'][$table]));
                sort($graph['refs'][$table], SORT_STRING);
                $graph['row_hashes'][$table][$row['id']] = hash('sha256', ManagementContract::canonical($row));
                $proof = $row['authority_proof'];
                $slot = ['format' => 'management-authority-v1', 'subject_kind' => $table, 'subject_id' => $row['id'], 'proof_hash' => ManagementContract::proofHash($proof), 'actor_id' => $row['actor_id'], 'purposes' => $proof['purposes'], 'pinned_proof' => $proof, 'mandatory' => true];
                $slot['key'] = ManagementContract::proofKey($actor->tenantId, $project, $slot);
                $graph['proof_slots'][] = $slot;
                $graph['budget']['management_proofs']++;
                $graph['budget']['named_slots'] += count($proof['named'] ?? []);
            }
            foreach ($facts as $table => $fact) {
                foreach (['owner_id', 'helper_id', 'requester_id'] as $field) {
                    if (isset($fact[$field])) {
                        $graph['named_slots'][] = ['key' => 'named:prospective:'.hash('sha256', $table.':'.$field), 'employee_id' => $fact[$field], 'origin' => 'management', 'mandatory' => true];
                        $graph['budget']['named_slots']++;
                    }
                }
            }
            $graph['budget']['original_actors'] = count(array_unique(array_column($graph['proof_slots'], 'actor_id')));
            $B = $graph['budget']['boundaries'];
            $L = $graph['budget']['references'];
            foreach (['references' => min(40 * $B, 2000), 'management_proofs' => min(10 * $B, 400), 'source_rows' => min(64 * $L, 4000), 'source_proofs' => min(32 * $L, 2300), 'named_slots' => min(8 * $B + 8 * $L, 2000), 'responsibility_slots' => min(2 * $L, 1000), 'receipts' => min(16 * $L, 200), 'source_instances' => 200, 'connector_heads' => 200, 'original_actors' => 100] as $field => $limit) {
                if ($graph['budget'][$field] > $limit) {
                    throw new ServiceUnavailableHttpException;
                }
            }
            // Every closure slot was charged above; only identical complete
            // authority requests may share a lookup after admission.
            $uniqueProofs = [];
            foreach ($graph['proof_slots'] as $slot) {
                if (isset($uniqueProofs[$slot['key']]) && ManagementContract::canonical($uniqueProofs[$slot['key']]) !== ManagementContract::canonical($slot)) {
                    throw new ConflictHttpException;
                }
                $uniqueProofs[$slot['key']] = $slot;
            }
            $graph['proof_slots'] = array_values($uniqueProofs);

            return $graph;
        });
    }

    public function references(array $ticket, array $wire, array $graph, string $field): string
    {
        $resolved = [];
        foreach ($wire as $reference) {
            $hash = hash('sha256', ManagementContract::canonical($reference));
            $matches = array_values(array_filter($graph['references'], fn ($r) => $r['reference_hash'] === $hash));
            if (! $matches) {
                throw new ConflictHttpException;
            }
            $r = end($matches);
            $resolved[] = $reference + array_intersect_key($r, array_flip(['requirement_version_id', 'requirement_hash', 'source_snapshot_hash', 'reference_hash']));
        }
        usort($resolved, fn ($a, $b) => strcmp(ManagementContract::canonical($a), ManagementContract::canonical($b)));
        $hash = hash('sha256', ManagementContract::canonical($resolved));
        $scope = ['tenant_id' => $ticket['tenant_id'], 'project_id' => $ticket['project_id']];
        $set = (string) Str::uuid();
        DB::table('management_reference_sets')->insert(['id' => $set, 'action_id' => $ticket['output']['action_id'], $field => $ticket['output'][$field], 'references_hash' => $hash] + $scope);
        foreach ($resolved as $i => $reference) {
            DB::table('management_references')->insert(['id' => (string) Str::uuid(), 'reference_set_id' => $set, 'ordinal' => $i + 1] + $reference + $scope);
        }

        return $hash;
    }

    public function event(array $ticket, array $root, array $projection, array $proof, array $extra = []): array
    {
        $projection = array_replace(array_intersect_key($root, array_flip(['episode', 'state', 'support_status', 'review_status', 'current_outcome', 'current_action_version_id', 'current_assignment_id', 'current_support_id', 'current_review_id'])), $projection);
        $event = ['id' => $ticket['output']['event_id'], 'tenant_id' => $ticket['tenant_id'], 'project_id' => $ticket['project_id'], 'action_id' => $root['id'], 'revision' => $root['revision'] + 1, 'operation' => $ticket['operation'], 'receipt_id' => $ticket['receipt_id'], 'actor_id' => $ticket['actor_id'], 'authority_proof' => ManagementContract::canonical($proof)] + $extra;
        foreach ($projection as $key => $value) {
            $event[str_starts_with($key, 'current_') && $key !== 'current_outcome' ? substr($key, 8) : $key] = $value;
        }
        unset($event['episode_opened_at']);
        DB::table('management_events')->insert($event);
        if (DB::table('management_actions')->where('project_id', $ticket['project_id'])->where('id', $root['id'])->where('revision', $root['revision'])->update($projection + ['revision' => $event['revision'], 'current_event_id' => $event['id']]) !== 1) {
            throw new ConflictHttpException;
        }
        app(AuditWriter::class)->record($ticket['actor_id'], ['tenant_id' => $ticket['tenant_id'], 'project_id' => $ticket['project_id']], 'management.event_recorded', ['type' => 'management_events', 'id' => $event['id']], $event['revision'], 'approved_change');

        return $event;
    }

    public function acknowledge(array $ticket, array $evaluation): array
    {
        $this->bindOutputLocked($ticket, $ticket['output']);
        $event = DB::table('management_events')->where('project_id', $ticket['project_id'])->where('id', $ticket['output']['event_id'])->first();

        $root = $this->root($ticket['project_id'], $ticket['output']['action_id']);
        $projection = ['action_version_id' => $root['current_action_version_id'], 'assignment_id' => $root['current_assignment_id'], 'support_id' => $root['current_support_id'], 'review_id' => $root['current_review_id']];

        return $ticket['output'] + ['revision' => $event->revision, 'input_hash' => $ticket['input_hash'], 'replayed' => $ticket['replayed'], 'synthetic' => true, 'ai_interpretation' => 'unavailable', 'applicability' => $evaluation['applicability'], 'subject_applicability' => $evaluation['subject_applicability'] ?? $evaluation['applicability'], 'current_applicability' => $evaluation['current_applicability'] ?? $evaluation['applicability'], 'current_projection' => $projection, 'current_outcome' => $evaluation['current_outcome'], 'coverage_status' => $evaluation['coverage'], 'reason_codes' => $evaluation['reason_codes'], 'current_reason_codes' => $evaluation['current_reason_codes'] ?? $evaluation['reason_codes']];
    }

    private function newTicket(TrustedActor $actor, string $project, string $op, string $key, string $hash, string $action): array
    {
        $output = ['receipt_id' => (string) Str::uuid(), 'action_id' => $action, 'event_id' => (string) Str::uuid()];
        $field = match (true) {
            in_array($op, ['action.open', 'action.revise', 'action.reopen'], true) => 'action_version_id',
            $op === 'assignment.set' => 'assignment_id',
            str_starts_with($op, 'support.') => 'support_id',
            str_starts_with($op, 'outcome.') => 'review_id',
            default => null,
        };
        if ($field) {
            $output[$field] = (string) Str::uuid();
        }

        return $this->ticket($actor, $project, $op, $key, $hash, $output, false);
    }

    private function ticket(TrustedActor $actor, string $project, string $op, string $key, string $hash, array $output, bool $replayed): array
    {
        return ['tenant_id' => $actor->tenantId, 'project_id' => $project, 'actor_id' => $actor->id, 'operation' => $op, 'key' => $key, 'input_hash' => $hash, 'receipt_id' => $output['receipt_id'], 'output' => $output, 'replayed' => $replayed];
    }

    private function output(array $receipt): array
    {
        return array_filter(['receipt_id' => $receipt['id']] + array_intersect_key($receipt, array_flip(['action_id', 'event_id', 'action_version_id', 'assignment_id', 'support_id', 'review_id'])), fn ($v) => $v !== null);
    }

    private function binding(TrustedActor $actor, string $project, string $operation, string $key, string $hash): ?object
    {
        $binding = DB::table('management_request_bindings')->where('project_id', $project)->where('requester_id', $actor->id)->where('operation', $operation)->where('key', $key)->first();
        if ($binding && $binding->input_hash !== $hash) {
            throw new ConflictHttpException;
        }

        return $binding;
    }

    private function proof(TrustedActor $actor, string $project, array $proof): void
    {
        $captured = json_decode(DB::scalar("select current_setting('app.management_proofs')"), true, 64, JSON_THROW_ON_ERROR);
        $expected = $captured['current:operation']['proof'];
        if (($proof['actor_id'] ?? '') !== $actor->id || ($proof['tenant_id'] ?? '') !== $actor->tenantId || ($proof['project_id'] ?? '') !== $project || ManagementContract::canonical($proof) !== ManagementContract::canonical($expected)) {
            throw new AccessDeniedHttpException;
        }
    }

    private function key(string $key): void
    {
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $key)) {
            throw new InvalidArgumentException('Invalid management idempotency key.');
        }
    }

    private function locked(): void
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.management_authority_closed',true),'')") !== '1') {
            throw new LogicException('Management receipt requires coordination.');
        }
    }
}
