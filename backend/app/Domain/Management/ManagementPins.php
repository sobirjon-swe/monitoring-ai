<?php

namespace App\Domain\Management;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ManagementPins
{
    /**
     * Build a complete server-owned graph before entering the authority fence.
     *
     * @param  list<string|array{action_id:string,event_id:string}>  $boundaries  Current action IDs or exact historical selectors.
     * @param  list<array|array{reference:array,occurred_at:string,assignment_id?:string}>  $prospectiveReferences  Validated wire tuples, optionally wrapped with trusted event-time metadata.
     * @param  list<string>  $sourceIds  Additional validated source IDs for the prospective operation.
     * @return array{coverage:string,reason_code:?string,boundaries:list<array>,references:list<array>,refs:array<string,list<string>>,heads:array<string,array<string,array>>,proof_slots:list<array>,current_requests:list<array>,named_slots:list<array>,responsibility_slots:list<array>,budget:array<string,int>}
     *                                                                                                                                                                                                                                                                                                                 Complete graphs additionally retain row_hashes and original_source_proof_hashes
     *                                                                                                                                                                                                                                                                                                                 as internal audit bindings. Unknown graphs contain no deliverable partial rows.
     */
    public function collect(TrustedActor $actor, string $project, array $boundaries, array $prospectiveReferences = [], array $sourceIds = []): array
    {
        $B = max(1, count($boundaries) + (int) (bool) $prospectiveReferences);
        $budget = ['boundaries' => $B, 'references' => count($prospectiveReferences), 'management_proofs' => 0, 'source_rows' => 0, 'source_proofs' => 0, 'named_slots' => 0, 'responsibility_slots' => 0, 'receipts' => 0, 'source_instances' => count(array_unique($sourceIds)), 'connector_heads' => 0, 'original_actors' => 0];
        if ($B > 50 || count($prospectiveReferences) > 20) {
            return $this->unknown($budget);
        }

        return app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $boundaries, $prospectiveReferences, $sourceIds, $budget) {
            $originals = $this->managementCost($project, $boundaries);
            if ($originals > min(10 * $budget['boundaries'], 400)) {
                $budget['management_proofs'] = $originals;

                return $this->unknown($budget);
            }
            $graph = ['coverage' => 'complete', 'reason_code' => null, 'boundaries' => $boundaries, 'references' => [], 'refs' => [], 'heads' => [], 'row_hashes' => [], 'original_source_proof_hashes' => [], 'proof_slots' => [], 'current_requests' => [], 'named_slots' => [], 'responsibility_slots' => [], 'budget' => $budget];
            $retained = $this->management($actor, $project, $boundaries, $graph);
            if ($retained === null) {
                return $this->unknown($graph['budget'], 'management_boundary_missing');
            }
            $prospective = [];
            foreach ($prospectiveReferences as $i => $item) {
                if (isset($item['reference'])) {
                    if (array_diff(array_keys($item), ['reference', 'occurred_at', 'assignment_id']) || ! is_array($item['reference']) || ! is_string($item['occurred_at'] ?? null) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $item['occurred_at']) || (isset($item['assignment_id']) && ! Str::isUuid($item['assignment_id']))) {
                        throw new LogicException('Invalid trusted management time wrapper.');
                    }
                    $graph['responsibility_slots'][] = ['key' => 'responsibility:prospective:'.$i, 'task_version_id' => $item['reference']['task_version_id'], 'occurred_at' => $item['occurred_at'], 'phase' => 'historical', 'mandatory' => true] + (isset($item['assignment_id']) ? ['assignment_id' => $item['assignment_id']] : []);
                    $graph['budget']['responsibility_slots']++;
                    $reference = $item['reference'];
                } else {
                    $reference = $item;
                }
                $reference['occurrence'] = ['phase' => 'prospective', 'subject_kind' => 'prospective', 'subject_id' => null, 'reference_set_id' => null, 'ordinal' => $i + 1];
                $prospective[] = $reference;
                $graph['responsibility_owners']['responsibility:prospective:'.$i] = $reference['occurrence'];
            }
            $references = [...$retained, ...$prospective];
            foreach ($graph['boundary_origins'] ?? [] as $origin) {
                $sourceIds = [...$sourceIds, ...$origin['source_ids']];
            }
            $graph['budget']['references'] = count($references);
            $adapter = app(ManagementSourceProofs::class);
            foreach (['lifecycle', 'qa', 'ba'] as $family) {
                $refs = array_values(array_filter($references, fn ($r) => $family === 'lifecycle' ? $r['kind'] === 'lifecycle' : str_starts_with($r['kind'], $family.'_')));
                $part = $adapter->{$family}($project, $refs, $graph['budget']);
                if ($part['coverage'] !== 'complete') {
                    return $this->unknown($graph['budget'], $part['reason_code']);
                }
                foreach (['references', 'proof_slots', 'named_slots', 'responsibility_slots'] as $field) {
                    $graph[$field] = [...$graph[$field], ...$part[$field]];
                }
                foreach ($part['refs'] as $table => $ids) {
                    $graph['refs'][$table] = [...($graph['refs'][$table] ?? []), ...$ids];
                }
                foreach ($part['heads'] as $table => $heads) {
                    $graph['heads'][$table] = ($graph['heads'][$table] ?? []) + $heads;
                }
                foreach ($part['row_hashes'] ?? [] as $table => $hashes) {
                    $graph['row_hashes'][$table] = ($graph['row_hashes'][$table] ?? []) + $hashes;
                }
                foreach ($part['original_source_proof_hashes'] ?? [] as $table => $hashes) {
                    $graph['original_source_proof_hashes'][$table] = ($graph['original_source_proof_hashes'][$table] ?? []) + $hashes;
                }
                $graph['responsibility_owners'] = ($graph['responsibility_owners'] ?? []) + ($part['responsibility_owners'] ?? []);
                foreach ($part['privacy_metadata'] ?? [] as $table => $metadata) {
                    $graph['privacy_metadata'][$table] = ($graph['privacy_metadata'][$table] ?? []) + $metadata;
                }
                foreach ($part['qa_terminal_signatures'] ?? [] as $table => $signatures) {
                    $graph['qa_terminal_signatures'][$table] = ($graph['qa_terminal_signatures'][$table] ?? []) + $signatures;
                }
                $sourceIds = [...$sourceIds, ...$part['source_ids']];
            }
            $graph['budget']['source_instances'] = count(array_unique($sourceIds));
            $connectors = $sourceIds ? DB::table('ingestion_connectors')->where('project_id', $project)->whereIn('source_instance_id', array_unique($sourceIds))->orderBy('id')->limit(201)->get(['id', 'revision', 'status', 'adapter', 'source_instance_id']) : collect();
            $graph['budget']['connector_heads'] = $connectors->count();
            foreach ($connectors as $connector) {
                $graph['refs']['ingestion_connectors'][] = $connector->id;
                $graph['heads']['ingestion_connectors'][$connector->id] = (array) $connector;
            }
            $graph['budget']['original_actors'] = count(array_unique(array_column($graph['proof_slots'], 'actor_id')));
            if (! $this->admitted($graph['budget'])) {
                return $this->unknown($graph['budget']);
            }
            foreach ($graph['refs'] as &$ids) {
                $ids = array_values(array_unique($ids));
                sort($ids, SORT_STRING);
            }
            unset($ids);
            if (array_sum(array_map('count', $graph['refs'])) > 4000 || array_filter($graph['heads'], fn ($heads) => count($heads) > 200)) {
                return $this->unknown($graph['budget']);
            }
            $graph['proof_slots'] = array_values(array_column($graph['proof_slots'], null, 'key'));
            if ($graph['references']) {
                $graph['current_requests'][] = ['key' => 'current:lifecycle', 'actor_id' => $actor->id, 'purposes' => ['progress.read'], 'required' => true];
            }
            if ($sourceIds) {
                $graph['current_requests'][] = ['key' => 'current:sources', 'actor_id' => $actor->id, 'purposes' => ['management.source.read'], 'required' => true, 'source_ids' => array_values(array_unique($sourceIds))];
            }
            foreach (['qa', 'ba'] as $family) {
                if (array_filter($graph['references'], fn ($r) => str_starts_with($r['kind'], $family.'_'))) {
                    $graph['current_requests'][] = ['key' => 'current:'.$family, 'actor_id' => $actor->id, 'purposes' => [$family.'.read', $family.'.source.read'], 'required' => true];
                }
            }

            return $graph;
        });
    }

    /** Boundaries are action UUIDs (current) or exact {action_id,event_id} history selectors. */
    private function managementCost(string $project, array $boundaries): int
    {
        if (! $boundaries) {
            return 0;
        }
        $points = [];
        foreach ($boundaries as $i => $boundary) {
            $selector = is_string($boundary) ? ['action_id' => $boundary] : $boundary;
            if (! is_array($selector) || array_diff(array_keys($selector), ['action_id', 'event_id']) || ! Str::isUuid($selector['action_id'] ?? '') || (isset($selector['event_id']) && ! Str::isUuid($selector['event_id']))) {
                throw new LogicException('Invalid management boundary selector.');
            }
            $points[] = ['ordinal' => $i, 'action_id' => $selector['action_id'], 'event_id' => $selector['event_id'] ?? null];
        }

        return (int) DB::scalar(<<<'SQL'
WITH selected AS (
 SELECT p.ordinal,a.id AS root_id,e.id,e.action_version_id,e.assignment_id,e.support_id,e.subject_support_id,e.review_id,e.receipt_id
 FROM jsonb_to_recordset(?::jsonb) p(ordinal integer,action_id uuid,event_id uuid)
 JOIN management_actions a ON a.project_id=?::uuid AND a.id=p.action_id
 JOIN management_events e ON e.project_id=a.project_id AND e.action_id=a.id AND e.id=coalesce(p.event_id,a.current_event_id)
), facts AS (
 SELECT ordinal,'management_events' AS kind,id,receipt_id FROM selected
 UNION SELECT e.ordinal,'management_action_versions',v.id,v.receipt_id FROM selected e JOIN management_action_versions v ON v.project_id=?::uuid AND v.action_id=e.root_id AND v.id=e.action_version_id
 UNION SELECT e.ordinal,'management_assignments',a.id,a.receipt_id FROM selected e JOIN management_assignments a ON a.project_id=?::uuid AND a.action_id=e.root_id AND a.id=e.assignment_id
 UNION SELECT e.ordinal,'management_support_versions',s.id,s.receipt_id FROM selected e JOIN management_support_versions s ON s.project_id=?::uuid AND s.action_id=e.root_id AND s.id IN (e.support_id,e.subject_support_id)
 UNION SELECT e.ordinal,'management_reviews',r.id,r.receipt_id FROM selected e JOIN management_reviews r ON r.project_id=?::uuid AND r.action_id=e.root_id AND r.id=e.review_id
 UNION SELECT e.ordinal,'management_assignments',a.id,a.receipt_id FROM selected e JOIN management_support_versions s ON s.project_id=?::uuid AND s.action_id=e.root_id AND s.id IN (e.support_id,e.subject_support_id) JOIN management_assignments a ON a.project_id=s.project_id AND a.action_id=e.root_id AND a.id=s.assignment_id
 UNION SELECT e.ordinal,'management_action_versions',v.id,v.receipt_id FROM selected e JOIN management_support_versions s ON s.project_id=?::uuid AND s.action_id=e.root_id AND s.id IN (e.support_id,e.subject_support_id) JOIN management_action_versions v ON v.project_id=s.project_id AND v.action_id=e.root_id AND v.id=s.action_version_id
)
SELECT (SELECT count(*) FROM facts)+(SELECT count(*) FROM (SELECT DISTINCT ordinal,receipt_id FROM facts WHERE receipt_id IS NOT NULL) receipts)
SQL, [json_encode($points, JSON_THROW_ON_ERROR), $project, $project, $project, $project, $project, $project, $project]);
    }

    /** Boundaries are action UUIDs (current) or exact {action_id,event_id} history selectors. */
    private function management(TrustedActor $actor, string $project, array $boundaries, array &$graph): ?array
    {
        if (! $boundaries) {
            return [];
        }
        $selectors = [];
        foreach ($boundaries as $boundary) {
            $selector = is_string($boundary) ? ['action_id' => $boundary] : $boundary;
            if (! is_array($selector) || array_diff(array_keys($selector), ['action_id', 'event_id']) || ! Str::isUuid($selector['action_id'] ?? '') || (isset($selector['event_id']) && ! Str::isUuid($selector['event_id']))) {
                throw new LogicException('Invalid management boundary selector.');
            }
            $selectors[] = $selector;
        }
        $adapter = app(ManagementSourceProofs::class);
        $roots = array_column($adapter->metadata($project, 'management_actions', array_unique(array_column($selectors, 'action_id'))), null, 'id');
        $eventIds = [];
        foreach ($selectors as $selector) {
            if (! isset($roots[$selector['action_id']])) {
                return null;
            }
            $eventIds[] = $selector['event_id'] ?? $roots[$selector['action_id']]['current_event_id'];
        }
        $events = array_column($adapter->metadata($project, 'management_events', array_unique($eventIds)), null, 'id');
        $ids = ['management_actions' => array_keys($roots), 'management_events' => $eventIds];
        foreach ($events as $event) {
            foreach (['action_version_id' => 'management_action_versions', 'assignment_id' => 'management_assignments', 'support_id' => 'management_support_versions', 'subject_support_id' => 'management_support_versions', 'review_id' => 'management_reviews', 'receipt_id' => 'management_receipts'] as $field => $table) {
                if (isset($event[$field])) {
                    $ids[$table][] = $event[$field];
                }
            }
        }
        $rows = ['management_actions' => $roots, 'management_events' => $events];
        foreach ($ids as $table => $values) {
            $rows[$table] = array_column($adapter->metadata($project, $table, array_unique($values)), null, 'id');
        }
        $assignments = array_unique([...array_keys($rows['management_assignments'] ?? []), ...array_column($rows['management_support_versions'] ?? [], 'assignment_id')]);
        $rows['management_assignments'] = array_column($adapter->metadata($project, 'management_assignments', $assignments), null, 'id');
        $versions = array_unique([...array_keys($rows['management_action_versions'] ?? []), ...array_column($rows['management_assignments'], 'action_version_id'), ...array_column($rows['management_support_versions'] ?? [], 'action_version_id')]);
        $rows['management_action_versions'] = array_column($adapter->metadata($project, 'management_action_versions', $versions), null, 'id');
        $receiptIds = $ids['management_receipts'] ?? [];
        foreach ($rows as $group) {
            foreach ($group as $row) {
                if (isset($row['receipt_id'])) {
                    $receiptIds[] = $row['receipt_id'];
                }
            }
        }
        $rows['management_receipts'] = array_column($adapter->metadata($project, 'management_receipts', array_unique($receiptIds)), null, 'id');
        $sourceReceipts = array_values(array_filter($rows['management_receipts'], fn ($r) => $r['variant'] === 'source'));
        if ($sourceReceipts) {
            $headIds = DB::select('SELECT DISTINCT h.id FROM jsonb_to_recordset(?::jsonb) r(source_instance_id uuid,family text,object_id text) JOIN management_source_heads h ON h.project_id=?::uuid AND h.source_instance_id=r.source_instance_id AND h.family=r.family AND h.object_id=r.object_id', [json_encode($sourceReceipts, JSON_THROW_ON_ERROR), $project]);
            $rows['management_source_heads'] = array_column($adapter->metadata($project, 'management_source_heads', array_column($headIds, 'id')), null, 'id');
            foreach ($rows['management_source_heads'] as $id => $head) {
                $graph['heads']['management_source_heads'][$id] = array_intersect_key($head, array_flip(['id', 'source_instance_id', 'family', 'object_id', 'source_revision', 'revision', 'receipt_id', 'complete']));
            }
        }
        $sets = DB::table('management_reference_sets')->where('project_id', $project)->where(fn ($q) => $q->whereIn('action_version_id', array_keys($rows['management_action_versions'] ?? []))->orWhereIn('support_id', array_keys($rows['management_support_versions'] ?? [])))->orderBy('id')->limit(101)->get();
        $refs = DB::table('management_references')->where('project_id', $project)->whereIn('reference_set_id', $sets->pluck('id')->all())->orderBy('ordinal')->limit(2001)->get();
        $rows['management_reference_sets'] = $sets->map(fn ($r) => (array) $r)->keyBy('id')->all();
        $rows['management_references'] = $refs->map(fn ($r) => (array) $r)->keyBy('id')->all();
        foreach ($rows as $table => $group) {
            $graph['refs'][$table] = array_keys($group);
            foreach ($group as $id => $row) {
                $graph['row_hashes'][$table][$id] = hash('sha256', ManagementContract::canonical($row));
            }
        }
        $references = $resolved = [];
        foreach ($selectors as $i => $selector) {
            $root = $roots[$selector['action_id']];
            $event = $events[$eventIds[$i]] ?? null;
            if (! $event || $event['action_id'] !== $root['id']) {
                return null;
            }
            $graph['heads']['management_actions'][$root['id']] = array_intersect_key($root, array_flip(['revision', 'current_event_id', 'current_action_version_id', 'current_assignment_id', 'current_support_id', 'current_review_id', 'state', 'support_status', 'review_status', 'current_outcome']));
            $resolved[] = ['root_id' => $root['id'], 'event_id' => $event['id']] + $event;
            $facts = ['management_events' => $event];
            foreach (['action_version_id' => 'management_action_versions', 'assignment_id' => 'management_assignments', 'support_id' => 'management_support_versions', 'subject_support_id' => 'management_support_versions', 'review_id' => 'management_reviews'] as $field => $table) {
                if (isset($event[$field])) {
                    $fact = $rows[$table][$event[$field]] ?? null;
                    if (! $fact || $fact['action_id'] !== $root['id']) {
                        return null;
                    }
                    $facts[$table.':'.$fact['id']] = $fact;
                }
            }
            foreach (['support_id', 'subject_support_id'] as $field) {
                $support = $rows['management_support_versions'][$event[$field] ?? ''] ?? null;
                if ($support === null) {
                    continue;
                }
                $assignment = $rows['management_assignments'][$support['assignment_id']] ?? null;
                $version = $rows['management_action_versions'][$support['action_version_id']] ?? null;
                if (! $assignment || ! $version || $assignment['action_id'] !== $root['id'] || $version['action_id'] !== $root['id'] || $assignment['action_version_id'] !== $support['action_version_id']) {
                    return null;
                }
                $facts['management_assignments:'.$assignment['id']] = $assignment;
                $facts['management_action_versions:'.$version['id']] = $version;
            }
            $boundaryReceipts = [];
            $origin = ['proof_keys' => [], 'employee_ids' => [], 'source_ids' => [], 'subjects' => [], 'facts' => []];
            foreach ($facts as $typed => $fact) {
                $table = explode(':', $typed)[0];
                $this->managementProof($actor, $project, $table, $fact, $graph);
                $this->origin($origin, $table, $fact);
                foreach (['owner_id', 'helper_id', 'recorder_id', 'requester_id', 'reviewer_id'] as $field) {
                    if (isset($fact[$field])) {
                        $graph['named_slots'][] = ['key' => 'named:'.hash('sha256', $i.':'.$table.':'.$fact['id'].':'.$field), 'employee_id' => $fact[$field], 'origin' => 'management', 'mandatory' => true];
                        $graph['budget']['named_slots']++;
                    }
                }
                $receipt = isset($fact['receipt_id']) ? ($rows['management_receipts'][$fact['receipt_id']] ?? null) : null;
                if ($receipt) {
                    $boundaryReceipts[$receipt['id']] = $receipt;
                }
            }
            foreach ($boundaryReceipts as $receipt) {
                $this->managementProof($actor, $project, 'management_receipts', $receipt, $graph);
                $this->origin($origin, 'management_receipts', $receipt);
                if ($receipt['variant'] === 'source') {
                    $origin['source_ids'][] = $receipt['source_instance_id'];
                }
            }
            $graph['boundary_origins'][$event['id']] = $origin;
            foreach (['action_version_id', 'support_id', 'subject_support_id'] as $field) {
                $set = array_values(array_filter($rows['management_reference_sets'], fn ($s) => $field === 'action_version_id' ? $s['action_version_id'] === ($event[$field] ?? null) && $s['action_version_id'] !== null : $s['support_id'] === ($event[$field] ?? null) && $s['support_id'] !== null));
                if ($field === 'subject_support_id' && ($event[$field] ?? null) === ($event['support_id'] ?? null)) {
                    continue;
                }
                foreach ($set as $s) {
                    $group = array_values(array_filter($rows['management_references'], fn ($r) => $r['reference_set_id'] === $s['id']));
                    if (! $group || count($group) > 20) {
                        return null;
                    }
                    foreach ($group as $ref) {
                        $reference = array_filter(array_intersect_key($ref, array_flip(['kind', 'task_version_id', 'criterion_id', 'criterion_version_id', 'relation', 'qa_handoff_event_id', 'qa_defect_event_id', 'qa_execution_version_id', 'ba_request_version_id', 'ba_answer_version_id', 'ba_review_id', 'requirement_version_id', 'requirement_hash'])), fn ($v) => $v !== null);
                        $reference['expected_source_snapshot_hash'] = $ref['source_snapshot_hash'];
                        $reference['occurrence'] = ['phase' => 'retained', 'root_id' => $root['id'], 'event_id' => $event['id'], 'subject_kind' => $field === 'action_version_id' ? 'management_action_versions' : 'management_support_versions', 'subject_id' => $event[$field], 'reference_set_id' => $s['id'], 'ordinal' => $ref['ordinal']];
                        $references[] = $reference;
                        $occurred = $field === 'action_version_id' ? ($rows['management_action_versions'][$event[$field]]['occurred_at'] ?? null) : ($rows['management_support_versions'][$event[$field]]['occurred_at'] ?? null);
                        if ($occurred !== null) {
                            $assignment = $field === 'action_version_id' ? null : ($rows['management_support_versions'][$event[$field]]['assignment_id'] ?? null);
                            $key = 'responsibility:'.hash('sha256', $event['id'].':'.$s['id'].':'.$ref['id']);
                            $graph['responsibility_slots'][] = ['key' => $key, 'task_version_id' => $ref['task_version_id'], 'occurred_at' => $occurred, 'phase' => 'historical', 'mandatory' => true] + ($assignment !== null ? ['assignment_id' => $assignment] : []);
                            $graph['responsibility_owners'][$key] = $reference['occurrence'];
                            $graph['budget']['responsibility_slots']++;
                        }
                    }
                }
            }
        }
        $graph['boundaries'] = $resolved;

        return $references;
    }

    private function managementProof(TrustedActor $actor, string $project, string $table, array $fact, array &$graph): void
    {
        $proof = $fact['authority_proof'];
        $slot = ['format' => 'management-authority-v1', 'subject_kind' => $table, 'subject_id' => $fact['id'], 'proof_hash' => ManagementContract::proofHash($proof), 'actor_id' => $proof['actor_id'], 'purposes' => $proof['purposes'], 'pinned_proof' => $proof, 'mandatory' => true];
        $slot['key'] = ManagementContract::proofKey($actor->tenantId, $project, $slot);
        $graph['proof_slots'][] = $slot;
        $graph['budget']['management_proofs']++;
        $graph['budget']['named_slots'] += count($proof['named'] ?? []) + count($proof['coordinators'] ?? []) + count($proof['request_subjects'] ?? []);
    }

    /** Metadata provenance only; no payload text is retained for admission. */
    private function origin(array &$origin, string $table, array $fact): void
    {
        $proof = $fact['authority_proof'];
        $slot = ['format' => 'management-authority-v1', 'subject_kind' => $table, 'subject_id' => $fact['id'], 'proof_hash' => ManagementContract::proofHash($proof), 'actor_id' => $proof['actor_id'], 'purposes' => $proof['purposes'], 'pinned_proof' => $proof];
        $origin['proof_keys'][] = ManagementContract::proofKey($proof['tenant_id'], $proof['project_id'], $slot);
        $origin['subjects'][] = ['table' => $table, 'id' => $fact['id']];
        $origin['facts'][$table][$fact['id']] = $fact;
        $origin['employee_ids'][] = $proof['employee_id'];
        foreach (['owner_id', 'helper_id', 'recorder_id', 'requester_id', 'reviewer_id'] as $field) {
            if (isset($fact[$field])) {
                $origin['employee_ids'][] = $fact[$field];
            }
        }
        foreach (['named', 'coordinators', 'request_subjects'] as $field) {
            $origin['employee_ids'] = [...$origin['employee_ids'], ...array_column($proof[$field] ?? [], 'employee_id')];
        }
        $origin['source_ids'] = [...$origin['source_ids'], ...array_column($proof['sources'] ?? [], 'id')];
    }

    public function loadLocked(string $project, array $graph): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.management_authority_closed',true),'')") !== '1') {
            throw new LogicException('Management pins require coordination.');
        }
        if (($graph['coverage'] ?? 'unknown') !== 'complete') {
            return ['coverage' => 'unknown', 'rows' => []];
        }
        $adapter = app(ManagementSourceProofs::class);
        if (array_filter($graph['references'] ?? [], fn ($r) => str_starts_with($r['kind'], 'qa_'))) {
            $actual = $adapter->qaTerminalSignatures($project, $graph['refs'], 4000);
            if (ManagementContract::canonical($graph['qa_terminal_signatures'] ?? []) !== ManagementContract::canonical($actual)) {
                throw new ConflictHttpException('Management QA durable applicability changed.');
            }
        }
        if (array_filter($graph['references'] ?? [], fn ($r) => str_starts_with($r['kind'], 'ba_'))) {
            $captured = $graph['row_hashes']['ba_terminal_applicability'] ?? [];
            $actual = $adapter->baTerminalClosure($project, $graph['refs'], 4000);
            $hashes = array_map(fn ($row) => hash('sha256', ManagementContract::canonical($row)), $actual['rows']);
            if ($actual['overflow'] || ManagementContract::canonical($captured) !== ManagementContract::canonical($hashes)) {
                throw new ConflictHttpException('Management BA durable applicability changed.');
            }
        }
        $metadata = [];
        foreach ($graph['refs'] ?? [] as $table => $ids) {
            if (! in_array($table, [...ManagementCoordination::TABLES, ...ManagementCoordination::SUBJECTS, ...ManagementCoordination::IMMUTABLE_REFS], true)) {
                throw new LogicException('Unknown management graph table.');
            }
            if (in_array($table, ['lifecycle_project_state', 'qa_project_state'], true)) {
                continue;
            }
            $metadata[$table] = array_column($adapter->metadata($project, $table, $ids), null, 'id');
            if (count($metadata[$table]) !== count($ids)) {
                throw new ConflictHttpException('Management source missing.');
            }
            foreach ($metadata[$table] as $id => $row) {
                if (isset($graph['row_hashes'][$table][$id]) && hash('sha256', ManagementContract::canonical($row)) !== $graph['row_hashes'][$table][$id]) {
                    throw new ConflictHttpException('Management source snapshot changed.');
                }
            }
        }
        foreach ($graph['proof_slots'] ?? [] as $slot) {
            $fact = $metadata[$slot['subject_kind']][$slot['subject_id']] ?? null;
            $saved = $fact['authority_proof'] ?? null;
            if (! is_array($saved) || ManagementContract::canonical($saved) !== ManagementContract::canonical($slot['pinned_proof']) || ManagementContract::proofHash($saved) !== $slot['proof_hash'] || ($fact['actor_id'] ?? $saved['actor_id']) !== $slot['actor_id']) {
                throw new ConflictHttpException('Management original proof does not bind its subject.');
            }
        }
        // Full text/envelopes are fetched only after negative native lookups,
        // exact safe metadata hashes and every pinned original have closed.
        $rows = [];
        foreach ($graph['refs'] ?? [] as $table => $ids) {
            $identity = in_array($table, ['lifecycle_project_state', 'qa_project_state'], true) ? 'project_id' : 'id';
            $found = DB::table($table)->where('project_id', $project)->whereIn($identity, $ids)->orderBy($identity)->get()->keyBy($identity);
            if ($found->count() !== count($ids)) {
                throw new ConflictHttpException('Management source missing.');
            }
            $rows[$table] = $found->map(fn ($r) => (array) $r)->all();
        }
        if ($rows['management_assignments'] ?? []) {
            $intervals = DB::select('SELECT a.id,min(n.assigned_at) AS next_assignment_at FROM management_assignments a LEFT JOIN management_assignments n ON n.project_id=a.project_id AND n.action_id=a.action_id AND n.id<>a.id AND n.assigned_at>=a.assigned_at WHERE a.project_id=?::uuid AND a.id=ANY(?::uuid[]) GROUP BY a.id', [$project, '{'.implode(',', array_keys($rows['management_assignments'])).'}']);
            foreach ($intervals as $interval) {
                $assignment = &$rows['management_assignments'][$interval->id];
                $assignment['next_assignment_at'] = $interval->next_assignment_at;
                $root = $rows['management_actions'][$assignment['action_id']] ?? null;
                $assignment['interval_known'] = $interval->next_assignment_at !== null || ($root['current_assignment_id'] ?? null) === $assignment['id'];
                unset($assignment);
            }
        }

        return ['coverage' => 'complete', 'rows' => $rows];
    }

    /** @return array<string,array> Exact selected event ID to independent evaluation. */
    public function evaluateBatchLocked(string $project, array $graph, array $rows, array $proofs): array
    {
        return app(ManagementSourceProofs::class)->evaluateBatchLocked($project, $graph, $rows, $proofs);
    }

    private function admitted(array $b): bool
    {
        $B = $b['boundaries'];
        $L = $b['references'];
        foreach (['references' => min(40 * $B, 2000), 'management_proofs' => min(10 * $B, 400), 'source_rows' => min(64 * $L, 4000), 'source_proofs' => min(32 * $L, 2300), 'named_slots' => min(8 * $B + 8 * $L, 2000), 'responsibility_slots' => min(2 * $L, 1000), 'receipts' => min(16 * $L, 200), 'source_instances' => 200, 'connector_heads' => 200, 'original_actors' => 100] as $field => $limit) {
            if ($b[$field] > $limit) {
                return false;
            }
        }

        return true;
    }

    private function unknown(array $budget, string $reason = 'coverage_limit'): array
    {
        return ['coverage' => 'unknown', 'reason_code' => $reason, 'boundaries' => [], 'references' => [], 'refs' => [], 'heads' => [], 'proof_slots' => [], 'current_requests' => [], 'named_slots' => [], 'responsibility_slots' => [], 'budget' => $budget];
    }
}
