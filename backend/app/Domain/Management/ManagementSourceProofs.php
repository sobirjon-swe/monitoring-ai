<?php

namespace App\Domain\Management;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Read-only typed source adapters; authority is evaluated by the management fence. */
final class ManagementSourceProofs
{
    /** Fixed v1 metadata columns preserve the prior safe snapshot representation. */
    private const METADATA_COLUMNS = [
        'ba_answer_versions' => ['id', 'tenant_id', 'project_id', 'clarification_id', 'request_version_id', 'request_hash', 'links_hash', 'receipt_id', 'source_instance_id', 'object_id', 'source_revision', 'operation', 'predecessor_answer_version_id', 'author_party_version_id', 'response_at', 'answer_hash', 'actor_id', 'authority_proof'],
        'ba_clarifications' => ['id', 'tenant_id', 'project_id', 'source_instance_id', 'object_id', 'revision', 'current_event_id', 'current_request_id', 'current_answer_id', 'current_review_id', 'state', 'original_requested_at'],
        'ba_events' => ['id', 'tenant_id', 'project_id', 'clarification_id', 'revision', 'kind', 'state', 'request_version_id', 'answer_version_id', 'review_id', 'receipt_id', 'actor_id', 'authority_proof', 'created_at', 'subject_answer_version_id'],
        'ba_receipts' => ['id', 'tenant_id', 'project_id', 'variant', 'operation', 'family', 'source_instance_id', 'object_id', 'source_revision', 'input_hash', 'actor_id', 'authority_proof', 'occurred_at', 'source_updated_at', 'received_at'],
        'ba_request_links' => ['id', 'tenant_id', 'project_id', 'clarification_id', 'request_version_id', 'task_version_id', 'criterion_id', 'criterion_version_id', 'relation', 'requirement_version_id', 'requirement_hash'],
        'ba_request_versions' => ['id', 'tenant_id', 'project_id', 'clarification_id', 'revision', 'episode', 'receipt_id', 'requester_id', 'coordinator_id', 'decision_party', 'decision_owner_id', 'decision_owner_party_version_id', 'requested_at', 'request_hash', 'links_hash', 'actor_id', 'authority_proof'],
        'ba_reviews' => ['id', 'tenant_id', 'project_id', 'clarification_id', 'request_version_id', 'answer_version_id', 'request_hash', 'links_hash', 'answer_hash', 'receipt_id', 'kind', 'disposition', 'predecessor_review_id', 'actor_id', 'authority_proof'],
        'ba_scope_obligations' => ['id', 'tenant_id', 'project_id', 'clarification_id', 'origin_review_id', 'origin_request_version_id', 'origin_answer_version_id', 'request_hash', 'links_hash', 'answer_hash', 'created_at'],
        'ba_source_heads' => ['id', 'tenant_id', 'project_id', 'source_instance_id', 'family', 'object_id', 'source_revision', 'revision', 'receipt_id', 'complete'],
        'ba_source_party_versions' => ['id', 'tenant_id', 'project_id', 'receipt_id', 'source_instance_id', 'object_id', 'source_revision', 'party_reference', 'party_kind', 'predecessor_party_version_id', 'content_hash', 'synthetic'],
        'ba_terminal_applicability' => ['id', 'tenant_id', 'project_id', 'kind', 'subject_id', 'proof_hash', 'reason_code', 'observed_at'],
        'baselines' => ['id', 'tenant_id', 'project_id', 'policy_id', 'previous_baseline_id', 'content_hash', 'approved_by', 'effective_at', 'is_reopen'],
        'criterion_versions' => ['id', 'tenant_id', 'project_id', 'criterion_id', 'version', 'required_test_keys', 'context_hash', 'content_hash'],
        'developer_snapshots' => ['id', 'tenant_id', 'project_id', 'source_instance_id', 'repository_id', 'mr_id', 'revision_ordinal', 'base_sha', 'head_sha', 'source_status', 'safe_diff', 'content_hash', 'paths', 'redaction', 'actor_id', 'occurred_at', 'received_at', 'source_updated_at', 'registered_at'],
        'dod_policy_versions' => ['id', 'tenant_id', 'project_id', 'version', 'policy', 'content_hash', 'approved_by', 'approved_at'],
        'human_acceptance_decisions' => ['id', 'tenant_id', 'project_id', 'baseline_id', 'item_id', 'policy_id', 'criterion_version_id', 'pins', 'pins_hash', 'input_generation', 'actor_id', 'decided_at', 'authority', 'authority_hash'],
        'ingestion_connectors' => ['id', 'tenant_id', 'project_id', 'source_instance_id', 'adapter', 'status', 'revision', 'created_at'],
        'lifecycle_project_state' => ['tenant_id', 'project_id', 'generation', 'current_baseline_id', 'current_dod_policy_id', 'is_closed'],
        'lifecycle_tasks' => ['id', 'tenant_id', 'project_id', 'revision', 'current_version_id', 'created_at'],
        'management_action_versions' => ['id', 'tenant_id', 'project_id', 'action_id', 'revision', 'episode', 'owner_id', 'requester_id', 'occurred_at', 'action_hash', 'references_hash', 'receipt_id', 'actor_id', 'authority_proof'],
        'management_actions' => ['id', 'tenant_id', 'project_id', 'source_instance_id', 'object_id', 'revision', 'episode', 'state', 'support_status', 'review_status', 'current_outcome', 'current_event_id', 'current_action_version_id', 'current_assignment_id', 'current_support_id', 'current_review_id', 'original_opened_at', 'episode_opened_at'],
        'management_assignments' => ['id', 'tenant_id', 'project_id', 'action_id', 'action_version_id', 'action_hash', 'owner_id', 'helper_id', 'department_id', 'assignment_hash', 'receipt_id', 'actor_id', 'authority_proof', 'assigned_at'],
        'management_events' => ['id', 'tenant_id', 'project_id', 'action_id', 'revision', 'episode', 'operation', 'state', 'support_status', 'review_status', 'current_outcome', 'action_version_id', 'assignment_id', 'support_id', 'subject_support_id', 'review_id', 'receipt_id', 'actor_id', 'authority_proof', 'created_at', 'project_generation'],
        'management_project_state' => ['id', 'tenant_id', 'project_id', 'generation', 'current_event_id', 'current_terminal_id'],
        'management_receipts' => ['id', 'tenant_id', 'project_id', 'variant', 'operation', 'family', 'source_instance_id', 'object_id', 'source_revision', 'input_hash', 'actor_id', 'authority_proof', 'occurred_at', 'source_updated_at', 'received_at', 'action_id', 'action_version_id', 'assignment_id', 'support_id', 'review_id', 'event_id'],
        'management_reference_sets' => ['id', 'tenant_id', 'project_id', 'action_id', 'action_version_id', 'support_id', 'references_hash'],
        'management_references' => ['id', 'tenant_id', 'project_id', 'reference_set_id', 'ordinal', 'kind', 'task_version_id', 'criterion_id', 'criterion_version_id', 'relation', 'requirement_version_id', 'requirement_hash', 'source_snapshot_hash', 'reference_hash', 'qa_handoff_event_id', 'qa_defect_event_id', 'qa_execution_version_id', 'ba_request_version_id', 'ba_answer_version_id', 'ba_review_id'],
        'management_reviews' => ['id', 'tenant_id', 'project_id', 'action_id', 'action_version_id', 'action_hash', 'assignment_id', 'assignment_hash', 'support_id', 'support_hash', 'references_hash', 'operation', 'predecessor_review_id', 'reviewer_id', 'review_hash', 'receipt_id', 'actor_id', 'authority_proof', 'reviewed_at'],
        'management_source_heads' => ['id', 'tenant_id', 'project_id', 'source_instance_id', 'family', 'object_id', 'source_revision', 'revision', 'receipt_id', 'complete'],
        'management_support_versions' => ['id', 'tenant_id', 'project_id', 'action_id', 'action_version_id', 'action_hash', 'assignment_id', 'assignment_hash', 'helper_id', 'recorder_id', 'operation', 'outcome', 'support_hash', 'references_hash', 'source_instance_id', 'object_id', 'source_revision', 'predecessor_support_id', 'receipt_id', 'actor_id', 'authority_proof', 'occurred_at', 'source_updated_at', 'received_at'],
        'management_terminal_applicability' => ['id', 'tenant_id', 'project_id', 'kind', 'subject_id', 'proof_hash', 'reason_code', 'observed_at', 'project_generation'],
        'qa_applicability' => ['id', 'tenant_id', 'project_id', 'change_id', 'change_seq', 'status', 'reason_code', 'observed_at'],
        'qa_build_versions' => ['id', 'tenant_id', 'project_id', 'receipt_id', 'receipt_kind', 'logical_key', 'revision', 'content_hash', 'mr_snapshot_id', 'base_sha', 'head_sha'],
        'qa_changes' => ['id', 'tenant_id', 'project_id', 'change_seq', 'kind', 'receipt_id', 'definition_id', 'entry_id', 'plan_id', 'selection_id', 'execution_id', 'review_id', 'defect_event_id', 'handoff_event_id', 'criterion_version_id', 'task_version_id', 'baseline_id', 'lifecycle_change_id', 'input_hash', 'actor_id', 'authority_proof', 'origin', 'created_at', 'lifecycle_mutation_id', 'task_mutation_version_id'],
        'qa_defect_events' => ['id', 'tenant_id', 'project_id', 'defect_id', 'revision', 'event_type', 'state', 'disposition', 'canonical_defect_id', 'criterion_id', 'criterion_version_id', 'definition_id', 'build_id', 'environment_id', 'task_version_id', 'selection_id', 'execution_id', 'fix_snapshot_id', 'fix_request_event_id', 'cause', 'actor_id', 'authority_proof', 'effective_at'],
        'qa_defects' => ['id', 'tenant_id', 'project_id', 'receipt_id', 'receipt_kind', 'logical_key', 'criterion_id', 'criterion_version_id', 'definition_id', 'build_id', 'environment_id', 'execution_id', 'severity', 'reproducibility', 'revision', 'current_event_id', 'state', 'disposition', 'canonical_defect_id'],
        'qa_definition_identities' => ['id', 'tenant_id', 'project_id', 'criterion_id', 'logical_key', 'revision', 'current_definition_id'],
        'qa_definition_versions' => ['id', 'tenant_id', 'project_id', 'test_identity_id', 'criterion_id', 'criterion_version_id', 'revision', 'receipt_id', 'receipt_kind', 'content_hash', 'test_type', 'release_critical', 'review_revision', 'current_review_id', 'review_state'],
        'qa_environment_versions' => ['id', 'tenant_id', 'project_id', 'receipt_id', 'receipt_kind', 'logical_key', 'revision', 'content_hash'],
        'qa_execution_attempts' => ['id', 'tenant_id', 'project_id', 'series_id', 'ordinal', 'execution_key', 'predecessor_id', 'retests_execution_id', 'fix_request_event_id', 'revision', 'source_revision', 'current_version_id'],
        'qa_execution_series' => ['id', 'tenant_id', 'project_id', 'source_instance_id', 'criterion_id', 'criterion_version_id', 'definition_id', 'build_id', 'environment_id', 'revision', 'current_ordinal', 'current_attempt_id'],
        'qa_execution_versions' => ['id', 'tenant_id', 'project_id', 'series_id', 'attempt_id', 'criterion_id', 'criterion_version_id', 'definition_id', 'build_id', 'environment_id', 'source_revision', 'receipt_id', 'receipt_kind', 'outcome', 'content_hash', 'responsibility', 'occurred_at'],
        'qa_handoff_events' => ['id', 'tenant_id', 'project_id', 'handoff_id', 'revision', 'event_type', 'state', 'owner_id', 'execution_id', 'defect_event_id', 'actor_id', 'authority_proof', 'effective_at'],
        'qa_handoffs' => ['id', 'tenant_id', 'project_id', 'receipt_id', 'receipt_kind', 'task_version_id', 'criterion_id', 'criterion_version_id', 'execution_id', 'defect_id', 'linked_handoff_id', 'handoff_type', 'responsibility', 'opened_at', 'revision', 'current_event_id', 'state', 'owner_id'],
        'qa_invalidations' => ['id', 'tenant_id', 'project_id', 'change_id', 'input_hash', 'reason_code', 'actor_id', 'observed_at'],
        'qa_plan_entries' => ['id', 'tenant_id', 'project_id', 'plan_id', 'baseline_id', 'criterion_version_id', 'entry_id'],
        'qa_plan_entry_definitions' => ['id', 'tenant_id', 'project_id', 'entry_id', 'criterion_id', 'criterion_version_id', 'definition_id', 'source_instance_id', 'required', 'definition_review_id'],
        'qa_plan_entry_snapshots' => ['id', 'tenant_id', 'project_id', 'baseline_id', 'policy_id', 'baseline_item_id', 'criterion_id', 'criterion_version_id', 'included', 'exclusion_reason', 'content_hash', 'actor_id', 'authority_proof', 'created_at'],
        'qa_plan_versions' => ['id', 'tenant_id', 'project_id', 'baseline_id', 'policy_id', 'revision', 'logical_key', 'content_hash', 'actor_id', 'authority_proof', 'created_at', 'review_revision', 'current_review_id', 'review_state'],
        'qa_project_state' => ['tenant_id', 'project_id', 'selection_revision', 'current_selection_id', 'maintenance_watermark'],
        'qa_receipts' => ['id', 'tenant_id', 'project_id', 'source_instance_id', 'object_id', 'source_revision', 'kind', 'payload_hash', 'actor_id', 'authority_proof', 'occurred_at', 'source_updated_at', 'received_at'],
        'qa_reviews' => ['id', 'tenant_id', 'project_id', 'definition_id', 'plan_id', 'revision', 'decision', 'subject_hash', 'actor_id', 'authority_proof', 'effective_at'],
        'qa_selection_events' => ['id', 'tenant_id', 'project_id', 'revision', 'plan_id', 'build_id', 'environment_id', 'actor_id', 'authority_proof', 'selected_at', 'plan_review_id'],
        'qa_source_heads' => ['id', 'tenant_id', 'project_id', 'source_instance_id', 'object_id', 'source_revision', 'revision', 'receipt_id'],
        'requirement_version_criteria' => ['id', 'tenant_id', 'project_id', 'requirement_version_id', 'criterion_id', 'criterion_version_id', 'requirement_id'],
        'requirement_versions' => ['id', 'tenant_id', 'project_id', 'requirement_id', 'version', 'draft_revision', 'content_hash', 'approved_by', 'approved_at'],
        'requirements' => ['id', 'tenant_id', 'project_id', 'created_at'],
        'task_criterion_links' => ['id', 'tenant_id', 'project_id', 'task_id', 'task_version_id', 'criterion_id', 'criterion_version_id'],
        'task_versions' => ['id', 'tenant_id', 'project_id', 'task_id', 'revision', 'assignee_id', 'remaining_effort', 'expected_test_keys', 'status', 'content_hash', 'actor_id', 'effective_at', 'source_connector_id', 'source_instance_id', 'source_object_type', 'source_object_id', 'source_receipt_id'],
    ];

    public const SUBJECTS = ['qa_handoff_event' => 'qa_handoff_events', 'qa_defect_event' => 'qa_defect_events', 'qa_execution_version' => 'qa_execution_versions', 'ba_request_version' => 'ba_request_versions', 'ba_answer_version' => 'ba_answer_versions', 'ba_review' => 'ba_reviews'];

    public function lifecycle(string $project, array $references, array &$budget): array
    {
        if (! $references) {
            return $this->empty();
        }
        $points = [];
        foreach ($references as $i => $ref) {
            $points[] = ['ordinal' => $i, 'task_version_id' => $ref['task_version_id'], 'criterion_id' => $ref['criterion_id'], 'criterion_version_id' => $ref['criterion_version_id'], 'requirement_version_id' => $ref['requirement_version_id'] ?? null];
        }
        $matches = DB::select(<<<'SQL'
SELECT p.ordinal, l.id AS task_link_id, r.id AS requirement_link_id,
 t.id AS task_version_id,t.task_id,t.assignee_id,t.content_hash AS task_hash,t.effective_at,
 h.current_version_id AS task_current_version_id,h.revision AS task_current_revision,
 c.id AS criterion_version_id,c.content_hash AS criterion_hash,
 v.id AS requirement_version_id,v.requirement_id,v.content_hash AS requirement_hash,
 curr.id AS requirement_current_version_id
FROM jsonb_to_recordset(?::jsonb) p(ordinal integer,task_version_id uuid,criterion_id uuid,criterion_version_id uuid,requirement_version_id uuid)
JOIN task_criterion_links l ON l.project_id=?::uuid AND l.task_version_id=p.task_version_id AND l.criterion_id=p.criterion_id AND l.criterion_version_id=p.criterion_version_id
JOIN task_versions t ON t.project_id=l.project_id AND t.id=l.task_version_id
JOIN lifecycle_tasks h ON h.project_id=t.project_id AND h.id=t.task_id
JOIN criterion_versions c ON c.project_id=l.project_id AND c.id=l.criterion_version_id AND c.criterion_id=l.criterion_id
JOIN criteria k ON k.project_id=c.project_id AND k.id=c.criterion_id
JOIN requirement_versions curr ON curr.project_id=k.project_id AND curr.requirement_id=k.requirement_id
 AND NOT EXISTS(SELECT 1 FROM requirement_versions n WHERE n.project_id=curr.project_id AND n.requirement_id=curr.requirement_id AND n.version>curr.version)
JOIN requirement_versions v ON v.project_id=k.project_id AND v.requirement_id=k.requirement_id AND v.id=coalesce(p.requirement_version_id,curr.id)
JOIN requirement_version_criteria r ON r.project_id=v.project_id AND r.requirement_version_id=v.id AND r.criterion_id=k.id AND r.criterion_version_id=c.id
ORDER BY p.ordinal
SQL, [json_encode($points, JSON_THROW_ON_ERROR), $project]);
        if (count($matches) !== count($references)) {
            return $this->empty('source_tuple_invalid');
        }
        $result = $this->empty();
        $lifecycleState = DB::table('lifecycle_project_state')->where('project_id', $project)->first(['current_baseline_id', 'current_dod_policy_id', 'is_closed']);
        if (! $lifecycleState) {
            return $this->empty('source_missing');
        }
        $result['refs']['lifecycle_project_state'] = [$project];
        $result['heads']['lifecycle_project_state'][$project] = (array) $lifecycleState;
        foreach ($matches as $row) {
            $ref = $references[$row->ordinal];
            foreach (['task_version_id', 'criterion_version_id', 'requirement_version_id'] as $field) {
                if (isset($ref[$field]) && $ref[$field] !== $row->{$field}) {
                    return $this->empty('source_tuple_invalid');
                }
            }
            $wire = array_intersect_key($ref, array_flip(['kind', 'task_version_id', 'criterion_id', 'criterion_version_id', 'relation', 'qa_handoff_event_id', 'qa_defect_event_id', 'qa_execution_version_id', 'ba_request_version_id', 'ba_answer_version_id', 'ba_review_id']));
            $result['references'][] = $ref + (array) $row + ['reference_hash' => hash('sha256', ManagementContract::canonical($wire)), 'source_snapshot_hash' => hash('sha256', ManagementContract::canonical(array_diff_key((array) $row, ['ordinal' => true])))];
            foreach (['task_versions' => $row->task_version_id, 'task_criterion_links' => $row->task_link_id, 'criterion_versions' => $row->criterion_version_id, 'requirement_versions' => $row->requirement_version_id, 'requirement_version_criteria' => $row->requirement_link_id, 'lifecycle_tasks' => $row->task_id] as $table => $id) {
                $result['refs'][$table][] = $id;
                $budget['source_rows']++;
            }
            $result['heads']['lifecycle_tasks'][$row->task_id] = ['current_version_id' => $row->task_current_version_id, 'revision' => $row->task_current_revision];
            $result['named_slots'][] = ['key' => 'named:'.hash('sha256', ManagementContract::canonical([$ref, $row->assignee_id])), 'employee_id' => $row->assignee_id, 'origin' => 'lifecycle', 'mandatory' => true];
            $budget['named_slots']++;
            $budget['source_rows']++;
        }

        return $result;
    }

    public function qa(string $project, array $references, array &$budget): array
    {
        return $this->source($project, $references, $budget, 'qa');
    }

    public function ba(string $project, array $references, array &$budget): array
    {
        return $this->source($project, $references, $budget, 'ba');
    }

    private function source(string $project, array $references, array &$budget, string $family): array
    {
        if (! $references) {
            return $this->empty();
        }
        $result = $this->lifecycle($project, $references, $budget);
        if ($result['coverage'] !== 'complete') {
            return $result;
        }
        $clock = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'));
        $pending = $seen = $closures = $explicitNamed = [];
        foreach ($references as $i => $reference) {
            $kind = $reference['kind'];
            $table = self::SUBJECTS[$kind] ?? null;
            if (! $table || ! str_starts_with($kind, $family.'_')) {
                return $this->empty('source_tuple_invalid');
            }
            $pending[$table][$reference[$kind.'_id']][$i] = true;
            $closures[$i] = [];
        }
        $selectionOwners = [];
        if ($family === 'qa' && array_filter($references, fn ($r) => $r['kind'] !== 'qa_handoff_event')) {
            $state = DB::table('qa_project_state')->where('project_id', $project)->first(['selection_revision', 'current_selection_id']);
            if (! $state || ! $state->current_selection_id) {
                return $this->empty('source_selection_missing');
            }
            $result['refs']['qa_project_state'] = [$project];
            $result['heads']['qa_project_state'][$project] = (array) $state;
            foreach ($references as $i => $reference) {
                if ($reference['kind'] !== 'qa_handoff_event') {
                    $selectionOwners[$i] = true;
                    $budget['source_rows']++;
                    $pending['qa_selection_events'][$state->current_selection_id][$i] = true;
                }
            }
        }
        // Each frontier is loaded once per fixed table, independent of root count.
        // A row shared by references still consumes a slot in each closure.
        $cached = $perReferenceProofs = [];
        for ($step = 0; $pending && $step < 64; $step++) {
            $next = [];
            foreach ($pending as $table => $points) {
                if ($family === 'qa' && $table === 'qa_execution_versions') {
                    $state ??= DB::table('qa_project_state')->where('project_id', $project)->first(['selection_revision', 'current_selection_id']);
                    if (! $state || ! $state->current_selection_id) {
                        return $this->empty('source_selection_missing');
                    }
                    $result['refs']['qa_project_state'] = [$project];
                    $result['heads']['qa_project_state'][$project] = (array) $state;
                    foreach ($points as $owners) {
                        foreach (array_keys($owners) as $i) {
                            if (! isset($selectionOwners[$i])) {
                                $selectionOwners[$i] = true;
                                $budget['source_rows']++;
                                $next['qa_selection_events'][$state->current_selection_id][$i] = true;
                            }
                        }
                    }
                }
                // Reserve each owner's semantic occurrence before lookup cache
                // deduplication and before materializing its original proof.
                $frontier = $this->frontier($project, $table, array_keys($points));
                if (count($frontier) !== count($points)) {
                    return $this->empty('source_missing');
                }
                $waveRows = [];
                foreach ($points as $id => $owners) {
                    foreach (array_keys($owners) as $i) {
                        if (isset($seen[$i][$table.':'.$id])) {
                            continue;
                        }
                        $waveRows[$i] = ($waveRows[$i] ?? 0) + 1;
                        $budget['source_rows']++;
                        $proofCount = (int) $frontier[$id]->proof_count;
                        $budget['source_proofs'] += $proofCount;
                        $perReferenceProofs[$i] = ($perReferenceProofs[$i] ?? 0) + $proofCount;
                        $budget['receipts'] += (int) str_ends_with($table, '_receipts');
                        $budget['named_slots'] += (int) $frontier[$id]->nested_names + (int) $frontier[$id]->explicit_names;
                        $explicitNamed[$i] = ($explicitNamed[$i] ?? 0) + (int) $frontier[$id]->explicit_names;
                        $baseRows = 7 + (int) isset($selectionOwners[$i]);
                        if (count($seen[$i] ?? []) + $waveRows[$i] + $baseRows > 64 || $perReferenceProofs[$i] > 32 || $explicitNamed[$i] > 8 || $budget['source_rows'] > min(64 * $budget['references'], 4000) || $budget['source_proofs'] > min(32 * $budget['references'], 2300) || $budget['receipts'] > min(16 * $budget['references'], 200) || $budget['named_slots'] > min(8 * $budget['boundaries'] + 8 * $budget['references'], 2000)) {
                            return $this->empty('coverage_limit');
                        }
                    }
                }
                $missing = array_values(array_diff(array_keys($points), array_keys($cached[$table] ?? [])));
                foreach ($this->metadata($project, $table, $missing) as $row) {
                    $cached[$table][$row['id']] = $row;
                }
                foreach ($points as $id => $owners) {
                    if (! isset($cached[$table][$id])) {
                        return $this->empty('source_missing');
                    }
                    $row = $cached[$table][$id];
                    foreach (array_keys($owners) as $i) {
                        $typed = $table.':'.$id;
                        if (isset($seen[$i][$typed])) {
                            continue;
                        }
                        $seen[$i][$typed] = true;
                        $closures[$i][$table][$id] = $row;
                        $baseRows = 7 + (int) isset($selectionOwners[$i]);
                        if (count($seen[$i]) + $baseRows > 64 || $budget['source_rows'] > min(64 * $budget['references'], 4000)) {
                            return $this->empty('coverage_limit');
                        }
                        $result['refs'][$table][] = $id;
                        if (in_array($table, ManagementCoordination::TABLES, true)) {
                            $result['heads'][$table][$id] = array_intersect_key($row, array_flip(['id', 'revision', 'source_revision', 'receipt_id', 'complete', 'current_event_id', 'current_request_id', 'current_answer_id', 'current_review_id', 'review_revision', 'review_state', 'current_attempt_id', 'current_ordinal', 'current_version_id', 'current_definition_id', 'state', 'disposition', 'canonical_defect_id']));
                        }
                        if (isset($row['authority_proof'])) {
                            $proof = is_array($row['authority_proof']) ? $row['authority_proof'] : json_decode($row['authority_proof'], true, 32, JSON_THROW_ON_ERROR);
                            $slot = ['format' => $proof['version'], 'subject_kind' => $table, 'subject_id' => $id, 'proof_hash' => ManagementContract::proofHash($proof), 'actor_id' => $proof['actor_id'], 'purposes' => $proof['purposes'], 'pinned_proof' => $proof, 'mandatory' => true];
                            $slot['key'] = ManagementContract::proofKey($proof['tenant_id'], $project, $slot);
                            $result['proof_slots'][] = $slot;
                            $result['original_source_proof_hashes'][$table][$id] = hash('sha256', ManagementContract::canonical($proof));
                            if ($perReferenceProofs[$i] > 32) {
                                return $this->empty('coverage_limit');
                            }
                            $result['source_ids'] = [...$result['source_ids'], ...array_column($proof['sources'] ?? [], 'id')];
                        }
                        foreach (['owner_id', 'requester_id', 'coordinator_id', 'decision_owner_id'] as $field) {
                            if (isset($row[$field])) {
                                $this->named($result, $row[$field], $family, [$typed, $i, $field]);
                            }
                        }
                        if (isset($row['source_instance_id'])) {
                            $result['source_ids'][] = $row['source_instance_id'];
                        }
                        foreach ($this->edges($table, $row) as [$target, $targetId]) {
                            if (! isset($seen[$i][$target.':'.$targetId])) {
                                $next[$target][$targetId][$i] = true;
                            }
                        }
                    }
                }
                // Fixed relationship queries admit full plan/request/origin closure.
                $relationships = $this->related($project, $table, array_keys($points), $cached[$table] ?? [], max(0, min(64 * $budget['references'], 4000) - $budget['source_rows']));
                if ($relationships === null) {
                    return $this->empty('coverage_limit');
                }
                foreach ($relationships as [$parent, $target, $row]) {
                    foreach (array_keys($points[$parent] ?? []) as $i) {
                        if (! isset($seen[$i][$target.':'.$row['id']])) {
                            $next[$target][$row['id']][$i] = true;
                        }
                    }
                }
            }
            $pending = $next;
        }
        if ($pending) {
            return $this->empty('coverage_limit');
        }
        $remainingRows = max(0, min(64 * $budget['references'], 4000) - $budget['source_rows']);
        if ($family === 'qa') {
            $terminalProbe = $this->qaTerminalMetadata($project, array_map(fn ($rows) => array_keys($rows), $cached), $remainingRows, false);
            $reservedRows = $budget['source_rows'];
            $reservedProofs = $budget['source_proofs'];
            $reservedNamed = $budget['named_slots'];
            foreach ($references as $i => $reference) {
                $part = $this->terminalForClosure($terminalProbe, $closures[$i], $cached);
                $rowCharge = array_sum(array_map('count', $part));
                $proofCharge = count($part['qa_changes'] ?? []);
                foreach ($part['qa_changes'] ?? [] as $change) {
                    if ($change['origin'] !== 'qa' || ! $change['_proof_present']) {
                        return $this->empty('source_invalidation_provenance_unknown');
                    }
                    $reservedNamed += $change['_nested_names'];
                }
                $reservedRows += $rowCharge;
                $reservedProofs += $proofCharge;
                if (count($seen[$i]) + 7 + (int) isset($selectionOwners[$i]) + $rowCharge > 64 || ($perReferenceProofs[$i] ?? 0) + $proofCharge > 32 || $reservedRows > min(64 * $budget['references'], 4000) || $reservedProofs > min(32 * $budget['references'], 2300) || $reservedNamed > min(8 * $budget['boundaries'] + 8 * $budget['references'], 2000)) {
                    return $this->empty('coverage_limit');
                }
            }
            $terminalUnion = $terminalProbe ? $this->qaTerminalMetadata($project, array_map(fn ($rows) => array_keys($rows), $cached), $remainingRows, true, $terminalProbe) : [];
            foreach ($terminalProbe as $table => $rows) {
                if (array_keys($rows) !== array_keys($terminalUnion[$table] ?? [])) {
                    return $this->empty('source_terminal_changed');
                }
                foreach ($rows as $id => $row) {
                    $result['qa_terminal_signatures'][$table][$id] = hash('sha256', ManagementContract::canonical($row));
                }
            }
        } else {
            $terminalUnion = $this->baTerminalClosure($project, array_map(fn ($rows) => array_keys($rows), $cached), $remainingRows);
        }
        if ($family === 'ba' && $terminalUnion['overflow']) {
            return $this->empty('coverage_limit');
        }
        foreach ($references as $i => $reference) {
            if ($family === 'ba') {
                $remaining = min(64 - count($seen[$i]) - 7, min(64 * $budget['references'], 4000) - $budget['source_rows']);
                $terminalIds = [];
                foreach ($closures[$i] as $table => $rows) {
                    foreach ($rows as $id => $row) {
                        $terminalIds = [...$terminalIds, ...($terminalUnion['subjects'][$table.':'.$id] ?? [])];
                    }
                }
                foreach (array_unique($terminalIds) as $id) {
                    $budget['source_rows']++;
                    if (--$remaining < 0) {
                        return $this->empty('coverage_limit');
                    }
                    $closures[$i]['ba_terminal_applicability'][$id] = $cached['ba_terminal_applicability'][$id] = $terminalUnion['rows'][$id];
                    $result['refs']['ba_terminal_applicability'][] = $id;
                }
                $result['references'][$i]['source_terminal_invalidity'] = (bool) $terminalIds;
            }
            if ($family === 'qa') {
                $remaining = min(64 - count($seen[$i]) - 7 - (int) isset($selectionOwners[$i]), min(64 * $budget['references'], 4000) - $budget['source_rows']);
                $terminal = $this->terminalForClosure($terminalUnion, $closures[$i], $cached);
                foreach ($terminal as $table => $rows) {
                    foreach ($rows as $id => $row) {
                        $budget['source_rows']++;
                        $closures[$i][$table][$id] = $cached[$table][$id] = $row;
                        if (--$remaining < 0) {
                            return $this->empty('coverage_limit');
                        }
                        $result['refs'][$table][] = $id;
                        if ($table === 'qa_applicability') {
                            $result['heads'][$table][$id] = array_intersect_key($row, array_flip(['change_id', 'change_seq', 'status', 'reason_code']));
                        }
                        if ($table === 'qa_changes') {
                            if ($row['origin'] !== 'qa' || ! is_array($row['authority_proof'])) {
                                return $this->empty('source_invalidation_provenance_unknown');
                            }
                            $proof = $row['authority_proof'];
                            $slot = ['format' => $proof['version'], 'subject_kind' => $table, 'subject_id' => $id, 'proof_hash' => ManagementContract::proofHash($proof), 'actor_id' => $proof['actor_id'], 'purposes' => $proof['purposes'], 'pinned_proof' => $proof, 'mandatory' => true];
                            $slot['key'] = ManagementContract::proofKey($proof['tenant_id'], $project, $slot);
                            $result['proof_slots'][] = $slot;
                            $result['original_source_proof_hashes'][$table][$id] = hash('sha256', ManagementContract::canonical($proof));
                            $budget['source_proofs']++;
                            $perReferenceProofs[$i] = ($perReferenceProofs[$i] ?? 0) + 1;
                            if ($perReferenceProofs[$i] > 32) {
                                return $this->empty('coverage_limit');
                            }
                            foreach (['named', 'coordinators', 'request_subjects'] as $field) {
                                $budget['named_slots'] += count($proof[$field] ?? []);
                            }
                        }
                    }
                }
            }
            if ($this->historyCycle($closures[$i])) {
                return $this->empty('source_cycle');
            }
            if (! $this->semantic($reference, $closures[$i])) {
                return $this->empty('source_tuple_invalid');
            }
            $result['references'][$i]['source_refs'] = array_map(fn ($rows) => array_keys($rows), $closures[$i]);
            $result['references'][$i]['source_current'] = $this->current($reference, $closures[$i], $result['heads']['lifecycle_project_state'][$project]['current_baseline_id']);
            $result['references'][$i]['source_time_known'] = $this->timeKnown($closures[$i], $clock);
            $result['references'][$i]['source_snapshot_hash'] = hash('sha256', ManagementContract::canonical($closures[$i]));
            $subject = $closures[$i][self::SUBJECTS[$reference['kind']]][$reference[$reference['kind'].'_id']];
            $times = [];
            if ($family === 'ba') {
                $request = $reference['kind'] === 'ba_request_version' ? $subject : $closures[$i]['ba_request_versions'][$subject['request_version_id']];
                // Historical responsibility follows the exact immutable subject;
                // current-head eligibility is evaluated independently above.
                $answer = $reference['kind'] === 'ba_answer_version' ? $subject : ($closures[$i]['ba_answer_versions'][$subject['answer_version_id'] ?? ''] ?? null);
                $time = $reference['kind'] === 'ba_request_version' ? $request['requested_at'] : ($answer['response_at'] ?? null);
                if ($time !== null) {
                    $times[] = $time;
                } else {
                    $result['references'][$i]['source_current'] = false;
                    $result['references'][$i]['source_time_known'] = false;
                }
            } else {
                $times[] = match ($reference['kind']) {
                    'qa_handoff_event' => $closures[$i]['qa_handoffs'][$subject['handoff_id']]['opened_at'],
                    'qa_defect_event' => $subject['effective_at'],
                    'qa_execution_version' => $subject['occurred_at'],
                };
            }
            foreach ($times as $phase => $time) {
                $at = CarbonImmutable::parse($time)->utc()->format('Y-m-d\TH:i:s.u\Z');
                $key = 'responsibility:'.hash('sha256', ManagementContract::canonical([$reference, $phase, $at]));
                $result['responsibility_slots'][] = ['key' => $key, 'task_version_id' => $reference['task_version_id'], 'occurred_at' => $at, 'phase' => 'historical', 'mandatory' => true];
                if (isset($reference['occurrence'])) {
                    $result['responsibility_owners'][$key] = $reference['occurrence'];
                }
                $budget['responsibility_slots']++;
            }
        }
        $result['row_hashes'] = [];
        foreach ($cached as $table => $rows) {
            foreach ($rows as $id => $row) {
                $result['row_hashes'][$table][$id] = hash('sha256', ManagementContract::canonical($row));
                $people = [];
                foreach (['owner_id', 'requester_id', 'coordinator_id', 'decision_owner_id'] as $field) {
                    if (isset($row[$field])) {
                        $people[] = $row[$field];
                    }
                }
                $result['privacy_metadata'][$table][$id] = ['employee_ids' => $people, 'source_ids' => isset($row['source_instance_id']) ? [$row['source_instance_id']] : []];
            }
        }

        return $result;
    }

    /** Exact native QA durable invalidity, including access-revoked origin receipt fanout. */
    /** Native terminal hashes bind the full stored JSONB proof, including capture time. */
    public function baTerminalClosure(string $project, array $refs, int $remaining): array
    {
        $query = null;
        foreach (['ba_receipts' => 'receipt', 'ba_request_versions' => 'request', 'ba_answer_versions' => 'answer', 'ba_reviews' => 'review', 'ba_events' => 'event', 'ba_source_party_versions' => 'party'] as $table => $kind) {
            if (! ($refs[$table] ?? [])) {
                continue;
            }
            $part = DB::table('ba_terminal_applicability as t')->join($table.' as s', fn ($q) => $q->on('s.id', '=', 't.subject_id')->on('s.tenant_id', '=', 't.tenant_id')->on('s.project_id', '=', 't.project_id'))
                ->where('t.project_id', $project)->whereIn('s.id', $refs[$table])->whereIn('t.kind', [$kind, 'proof']);
            // Party identity owns its immutable registration receipt proof.
            $proofAlias = 's';
            if ($table === 'ba_source_party_versions') {
                $part->join('ba_receipts as p', fn ($q) => $q->on('p.id', '=', 's.receipt_id')->on('p.tenant_id', '=', 's.tenant_id')->on('p.project_id', '=', 's.project_id'));
                $proofAlias = 'p';
            }
            $part->selectRaw("to_jsonb(t)::text AS terminal,encode(sha256(convert_to($proofAlias.authority_proof::text,'UTF8')),'hex') AS subject_proof_hash,? AS subject_table", [$table]);
            $query = $query === null ? $part : $query->unionAll($part);
        }
        $result = ['rows' => [], 'subjects' => [], 'overflow' => false];
        if ($query === null) {
            return $result;
        }
        $found = DB::query()->fromSub($query, 'terminal_points')->orderByRaw("terminal::jsonb->>'id'")->limit(min(4001, $remaining + 1))->get();
        if ($found->count() > $remaining) {
            $result['overflow'] = true;

            return $result;
        }
        foreach ($found as $point) {
            $row = json_decode($point->terminal, true, 32, JSON_THROW_ON_ERROR);
            if ($point->subject_proof_hash === null || $row['proof_hash'] !== $point->subject_proof_hash) {
                continue;
            }
            $result['rows'][$row['id']] = $row;
            $result['subjects'][$point->subject_table.':'.$row['subject_id']][] = $row['id'];
        }
        ksort($result['rows']);

        return $result;
    }

    public function qaTerminalClosure(string $project, array $refs, int $remaining): array
    {
        return $this->qaTerminalMetadata($project, $refs, $remaining, true);
    }

    /** Native invalidity signatures include its exact stored JSONB proof hash. */
    public function qaTerminalSignatures(string $project, array $refs, int $remaining): array
    {
        $result = $this->qaTerminalMetadata($project, $refs, $remaining, false);
        foreach ($result as &$rows) {
            foreach ($rows as &$row) {
                $row = hash('sha256', ManagementContract::canonical($row));
            }
            unset($row);
        }
        unset($rows);

        return $result;
    }

    private function qaTerminalMetadata(string $project, array $refs, int $remaining, bool $withProof, ?array $captured = null): array
    {
        $columns = ['qa_receipts' => 'receipt_id', 'qa_definition_versions' => 'definition_id', 'qa_plan_entry_snapshots' => 'entry_id', 'qa_plan_versions' => 'plan_id', 'qa_selection_events' => 'selection_id', 'qa_execution_versions' => 'execution_id', 'qa_reviews' => 'review_id', 'qa_defect_events' => 'defect_event_id', 'qa_handoff_events' => 'handoff_event_id'];
        $origin = 'coalesce(c.receipt_id,x.receipt_id,case when de.revision=1 then d.receipt_id end,case when he.revision=1 then h.receipt_id end)';
        $query = DB::table('qa_changes as c')->join('qa_applicability as a', 'a.change_id', '=', 'c.id')
            ->leftJoin('qa_execution_versions as x', 'x.id', '=', 'c.execution_id')->leftJoin('qa_defect_events as de', 'de.id', '=', 'c.defect_event_id')->leftJoin('qa_defects as d', 'd.id', '=', 'de.defect_id')
            ->leftJoin('qa_handoff_events as he', 'he.id', '=', 'c.handoff_event_id')->leftJoin('qa_handoffs as h', 'h.id', '=', 'he.handoff_id')->leftJoin('qa_invalidations as i', 'i.change_id', '=', 'c.id')
            ->where('c.project_id', $project)->where('a.status', '<>', 'current')->where(function ($q) use ($columns, $refs, $origin) {
                foreach ($columns as $table => $column) {
                    $q->orWhereIn('c.'.$column, $refs[$table] ?? []);
                }
                $q->orWhere(function ($q) use ($refs, $origin) {
                    $q->where('a.reason_code', 'access_revoked')->whereIn(DB::raw($origin), $refs['qa_receipts'] ?? []);
                });
            })->orderBy('c.id')->orderBy('i.id')->limit(min(4001, $remaining + 1));
        if ($captured !== null) {
            $query->whereIn('c.id', array_keys($captured['qa_changes'] ?? []))->whereIn('a.id', array_keys($captured['qa_applicability'] ?? []))->where(fn ($q) => $q->whereNull('i.id')->orWhereIn('i.id', array_keys($captured['qa_invalidations'] ?? [])));
        }
        $safeColumns = array_filter(self::METADATA_COLUMNS['qa_changes'], fn ($field) => $field !== 'authority_proof');
        $pairs = array_map(fn ($field) => "'$field',c.$field", $safeColumns);
        $probe = 'jsonb_build_object('.implode(',', $pairs).",'_proof_present',c.authority_proof IS NOT NULL,'_native_proof_hash',encode(sha256(convert_to(c.authority_proof::text,'UTF8')),'hex'),'_nested_names',coalesce(jsonb_array_length(c.authority_proof->'named'),0)+coalesce(jsonb_array_length(c.authority_proof->'coordinators'),0)+coalesce(jsonb_array_length(c.authority_proof->'request_subjects'),0))";
        $query->selectRaw(($withProof ? 'to_jsonb(c)' : $probe).'::text AS change,to_jsonb(a)::text AS applicability,to_jsonb(i)::text AS invalidation');
        $result = [];
        foreach ($query->get() as $row) {
            foreach (['change' => 'qa_changes', 'applicability' => 'qa_applicability', 'invalidation' => 'qa_invalidations'] as $field => $table) {
                $metadata = $row->$field === null ? null : json_decode($row->$field, true, 40, JSON_THROW_ON_ERROR);
                if ($metadata !== null) {
                    $result[$table][$metadata['id']] = $metadata;
                }
            }
        }
        ksort($result);
        foreach ($result as &$rows) {
            ksort($rows);
        }
        unset($rows);

        return $result;
    }

    private function terminalForClosure(array $terminal, array $closure, array $all): array
    {
        $columns = ['receipt_id' => 'qa_receipts', 'definition_id' => 'qa_definition_versions', 'entry_id' => 'qa_plan_entry_snapshots', 'plan_id' => 'qa_plan_versions', 'selection_id' => 'qa_selection_events', 'execution_id' => 'qa_execution_versions', 'review_id' => 'qa_reviews', 'defect_event_id' => 'qa_defect_events', 'handoff_event_id' => 'qa_handoff_events'];
        $selected = [];
        $states = array_column($terminal['qa_applicability'] ?? [], null, 'change_id');
        foreach ($terminal['qa_changes'] ?? [] as $id => $change) {
            foreach ($columns as $column => $table) {
                if (isset($change[$column], $closure[$table][$change[$column]])) {
                    $selected[$id] = true;
                }
            }
            if (($states[$id]['reason_code'] ?? null) === 'access_revoked') {
                $execution = $all['qa_execution_versions'][$change['execution_id'] ?? ''] ?? [];
                $defectEvent = $all['qa_defect_events'][$change['defect_event_id'] ?? ''] ?? [];
                $handoffEvent = $all['qa_handoff_events'][$change['handoff_event_id'] ?? ''] ?? [];
                $receipt = $change['receipt_id'] ?? $execution['receipt_id'] ?? (($defectEvent['revision'] ?? null) === 1 ? ($all['qa_defects'][$defectEvent['defect_id']]['receipt_id'] ?? null) : null) ?? (($handoffEvent['revision'] ?? null) === 1 ? ($all['qa_handoffs'][$handoffEvent['handoff_id']]['receipt_id'] ?? null) : null);
                if ($receipt && isset($closure['qa_receipts'][$receipt])) {
                    $selected[$id] = true;
                }
            }
        }
        $result = [];
        foreach ($terminal as $table => $rows) {
            foreach ($rows as $id => $row) {
                if (isset($selected[$table === 'qa_changes' ? $id : $row['change_id']])) {
                    $result[$table][$id] = $row;
                }
            }
        }

        return $result;
    }

    /** Metadata excludes source text/envelopes until current delivery permission. */
    public function metadata(string $project, string $table, array $ids): array
    {
        if (! $ids || count($ids) > 4000) {
            return [];
        }
        if (! in_array($table, [...ManagementCoordination::TABLES, ...ManagementCoordination::SUBJECTS, ...ManagementCoordination::IMMUTABLE_REFS], true)) {
            throw new LogicException('Unknown source table.');
        }
        $q = DB::table($table.' as s')->where('s.project_id', $project)->whereIn('s.id', $ids)->orderBy('s.id');
        $fields = self::METADATA_COLUMNS[$table] ?? throw new LogicException('Unsupported source metadata shape.');
        $pairs = array_map(fn ($field) => "'$field',s.$field", $fields);
        $q->selectRaw('jsonb_build_object('.implode(',', $pairs).')::text AS metadata');
        if ($table === 'qa_receipts') {
            $q->selectRaw("envelope->'body'->>'task_version_id' AS source_task_version_id");
        }
        if ($table === 'qa_source_heads') {
            $q->selectRaw('(SELECT count(*)=s.source_revision AND min(r.source_revision)=1 AND max(r.source_revision)=s.source_revision FROM qa_receipts r WHERE r.project_id=s.project_id AND r.source_instance_id=s.source_instance_id AND r.object_id=s.object_id AND r.source_revision<=s.source_revision) AS source_complete');
        }

        return $q->get()->map(function ($r) {
            $row = json_decode($r->metadata, true, 40, JSON_THROW_ON_ERROR);
            if (isset($r->source_task_version_id)) {
                $row['source_task_version_id'] = $r->source_task_version_id;
            }
            if (isset($r->source_complete)) {
                $row['source_complete'] = (bool) $r->source_complete;
            }

            return $row;
        })->all();
    }

    /** Typed cost metadata only; this query never selects original proof JSON. */
    private function frontier(string $project, string $table, array $ids): array
    {
        if (count($ids) > 4000 || ! isset(self::METADATA_COLUMNS[$table])) {
            return [];
        }
        $hasProof = in_array('authority_proof', self::METADATA_COLUMNS[$table], true);
        $nested = $hasProof ? "coalesce(jsonb_array_length(s.authority_proof->'named'),0)+coalesce(jsonb_array_length(s.authority_proof->'coordinators'),0)+coalesce(jsonb_array_length(s.authority_proof->'request_subjects'),0)" : '0';
        $namedFields = array_values(array_intersect(['owner_id', 'requester_id', 'coordinator_id', 'decision_owner_id'], self::METADATA_COLUMNS[$table]));
        $explicit = $namedFields ? 'num_nonnulls('.implode(',', array_map(fn ($field) => 's.'.$field, $namedFields)).')' : '0';

        return DB::table($table.' as s')->where('s.project_id', $project)->whereIn('s.id', $ids)->orderBy('s.id')->limit(4001)->selectRaw('s.id,'.($hasProof ? '(s.authority_proof IS NOT NULL)::integer' : '0').' AS proof_count,'.$nested.' AS nested_names,'.$explicit.' AS explicit_names')->get()->keyBy('id')->all();
    }

    private function edges(string $table, array $row): array
    {
        $map = match ($table) {
            'qa_handoff_events' => ['handoff_id' => 'qa_handoffs', 'execution_id' => 'qa_execution_versions', 'defect_event_id' => 'qa_defect_events'],
            'qa_handoffs' => ['receipt_id' => 'qa_receipts', 'current_event_id' => 'qa_handoff_events', 'execution_id' => 'qa_execution_versions', 'defect_id' => 'qa_defects', 'linked_handoff_id' => 'qa_handoffs'],
            'qa_defect_events' => ['defect_id' => 'qa_defects', 'definition_id' => 'qa_definition_versions', 'build_id' => 'qa_build_versions', 'environment_id' => 'qa_environment_versions', 'selection_id' => 'qa_selection_events', 'execution_id' => 'qa_execution_versions', 'fix_snapshot_id' => 'developer_snapshots', 'fix_request_event_id' => 'qa_defect_events', 'canonical_defect_id' => 'qa_defects'],
            'qa_defects' => ['receipt_id' => 'qa_receipts', 'current_event_id' => 'qa_defect_events', 'canonical_defect_id' => 'qa_defects', 'execution_id' => 'qa_execution_versions', 'definition_id' => 'qa_definition_versions', 'build_id' => 'qa_build_versions', 'environment_id' => 'qa_environment_versions'],
            'qa_execution_versions' => ['receipt_id' => 'qa_receipts', 'attempt_id' => 'qa_execution_attempts', 'series_id' => 'qa_execution_series', 'definition_id' => 'qa_definition_versions', 'build_id' => 'qa_build_versions', 'environment_id' => 'qa_environment_versions'],
            'qa_execution_attempts' => ['current_version_id' => 'qa_execution_versions', 'predecessor_id' => 'qa_execution_attempts', 'retests_execution_id' => 'qa_execution_attempts', 'fix_request_event_id' => 'qa_defect_events'],
            'qa_execution_series' => ['current_attempt_id' => 'qa_execution_attempts'],
            'qa_definition_versions' => ['receipt_id' => 'qa_receipts', 'current_review_id' => 'qa_reviews', 'test_identity_id' => 'qa_definition_identities'],
            'qa_build_versions', 'qa_environment_versions' => ['receipt_id' => 'qa_receipts'],
            'qa_selection_events' => ['plan_id' => 'qa_plan_versions', 'plan_review_id' => 'qa_reviews', 'build_id' => 'qa_build_versions', 'environment_id' => 'qa_environment_versions'],
            'qa_plan_versions' => ['current_review_id' => 'qa_reviews', 'baseline_id' => 'baselines', 'policy_id' => 'dod_policy_versions'],
            'qa_plan_entries' => ['entry_id' => 'qa_plan_entry_snapshots'],
            'qa_plan_entry_definitions' => ['definition_id' => 'qa_definition_versions', 'definition_review_id' => 'qa_reviews'],
            'ba_request_versions' => ['clarification_id' => 'ba_clarifications', 'receipt_id' => 'ba_receipts', 'decision_owner_party_version_id' => 'ba_source_party_versions'],
            'ba_answer_versions' => ['clarification_id' => 'ba_clarifications', 'request_version_id' => 'ba_request_versions', 'receipt_id' => 'ba_receipts', 'author_party_version_id' => 'ba_source_party_versions', 'predecessor_answer_version_id' => 'ba_answer_versions'],
            'ba_reviews' => ['clarification_id' => 'ba_clarifications', 'request_version_id' => 'ba_request_versions', 'answer_version_id' => 'ba_answer_versions', 'receipt_id' => 'ba_receipts', 'predecessor_review_id' => 'ba_reviews'],
            'ba_clarifications' => ['current_request_id' => 'ba_request_versions', 'current_answer_id' => 'ba_answer_versions', 'current_review_id' => 'ba_reviews', 'current_event_id' => 'ba_events'],
            'ba_events' => ['request_version_id' => 'ba_request_versions', 'answer_version_id' => 'ba_answer_versions', 'review_id' => 'ba_reviews', 'receipt_id' => 'ba_receipts', 'subject_answer_version_id' => 'ba_answer_versions'],
            'ba_source_party_versions' => ['receipt_id' => 'ba_receipts', 'predecessor_party_version_id' => 'ba_source_party_versions'],
            'ba_scope_obligations' => ['origin_review_id' => 'ba_reviews', 'origin_request_version_id' => 'ba_request_versions', 'origin_answer_version_id' => 'ba_answer_versions'],
            default => [],
        };
        $edges = [];
        foreach ($map as $field => $target) {
            if (isset($row[$field])) {
                $edges[] = [$target, $row[$field]];
            }
        }

        return $edges;
    }

    private function related(string $project, string $table, array $ids, array $parents, int $remaining): ?array
    {
        $relation = match ($table) {
            'qa_plan_versions' => ['qa_plan_entries', 'plan_id'], 'qa_plan_entry_snapshots' => ['qa_plan_entry_definitions', 'entry_id'],
            'ba_request_versions' => ['ba_request_links', 'request_version_id'], 'ba_clarifications' => ['ba_scope_obligations', 'clarification_id'], default => null,
        };
        $result = [];
        if ($relation) {
            [$target, $field] = $relation;
            $rows = DB::table($target)->where('project_id', $project)->whereIn($field, $ids)->orderBy('id')->limit(min(4001, $remaining + 1))->get(['id', $field]);
            if ($rows->count() > $remaining) {
                return null;
            }
            foreach ($rows as $row) {
                $result[] = [$row->$field, $target, ['id' => $row->id]];
            }
        }
        if (in_array($table, ['qa_receipts', 'ba_receipts'], true)) {
            $family = substr($table, 0, 2);
            $receipts = array_values(array_intersect_key($parents, array_flip($ids)));
            $points = array_values(array_filter($receipts, fn ($r) => isset($r['source_instance_id'])));
            if ($points) {
                $key = $family === 'qa' ? 'kind' : 'family';
                $familyJoin = $family === 'ba' ? ' AND h.family=p.family' : '';
                $rows = DB::select("SELECT p.id AS parent_id,h.id FROM jsonb_to_recordset(?::jsonb) p(id uuid,source_instance_id uuid,$key text,object_id text) JOIN {$family}_source_heads h ON h.project_id=?::uuid AND h.source_instance_id=p.source_instance_id$familyJoin AND h.object_id=p.object_id ORDER BY h.id LIMIT ?", [json_encode($points, JSON_THROW_ON_ERROR), $project, min(4001, $remaining + 1)]);
                if (count($rows) > $remaining) {
                    return null;
                }
                foreach ($rows as $row) {
                    $result[] = [$row->parent_id, $family.'_source_heads', ['id' => $row->id]];
                }
            }
        }

        return $result;
    }

    private function named(array &$result, string $employee, string $origin, array $identity): void
    {
        $result['named_slots'][] = ['key' => 'named:'.hash('sha256', ManagementContract::canonical($identity)), 'employee_id' => $employee, 'origin' => $origin, 'mandatory' => true];
    }

    private function timeKnown(array $rows, CarbonImmutable $clock): bool
    {
        foreach (['qa_receipts', 'ba_receipts'] as $table) {
            foreach ($rows[$table] ?? [] as $receipt) {
                if (($receipt['variant'] ?? 'source') !== 'source') {
                    continue;
                }
                foreach (['occurred_at', 'source_updated_at', 'received_at'] as $field) {
                    if (! isset($receipt[$field])) {
                        return false;
                    }
                }
                $occurred = CarbonImmutable::parse($receipt['occurred_at']);
                $updated = CarbonImmutable::parse($receipt['source_updated_at']);
                $received = CarbonImmutable::parse($receipt['received_at']);
                if ($occurred->greaterThan($updated) || $updated->greaterThan($received) || $received->greaterThan($clock)) {
                    return false;
                }
            }
        }
        foreach ($rows['ba_answer_versions'] ?? [] as $answer) {
            if ($answer['operation'] === 'answer.retract') {
                continue;
            }
            $request = $rows['ba_request_versions'][$answer['request_version_id']] ?? null;
            if (! $request || ! $answer['response_at'] || CarbonImmutable::parse($answer['response_at'])->lessThan(CarbonImmutable::parse($request['requested_at'])) || CarbonImmutable::parse($answer['response_at'])->greaterThan($clock)) {
                return false;
            }
        }

        return true;
    }

    private function historyCycle(array $rows): bool
    {
        $edges = [];
        foreach ($rows as $table => $group) {
            $fields = match ($table) {
                'qa_handoffs' => ['linked_handoff_id'], 'qa_defects' => ['canonical_defect_id'],
                'qa_execution_attempts' => ['predecessor_id', 'retests_execution_id'],
                'ba_answer_versions' => ['predecessor_answer_version_id'], 'ba_reviews' => ['predecessor_review_id'],
                'ba_source_party_versions' => ['predecessor_party_version_id'], default => [],
            };
            foreach ($group as $id => $row) {
                foreach ($fields as $field) {
                    if (isset($row[$field])) {
                        $edges[$table.':'.$id][] = $table.':'.$row[$field];
                    }
                }
            }
        }
        $color = [];
        $visit = function (string $key) use (&$visit, &$color, $edges): bool {
            if (($color[$key] ?? 0) === 1) {
                return true;
            }
            if (($color[$key] ?? 0) === 2) {
                return false;
            }
            $color[$key] = 1;
            foreach ($edges[$key] ?? [] as $next) {
                if ($visit($next)) {
                    return true;
                }
            }
            $color[$key] = 2;

            return false;
        };
        foreach (array_keys($edges) as $key) {
            if ($visit($key)) {
                return true;
            }
        }

        return false;
    }

    private function semantic(array $ref, array $rows): bool
    {
        $kind = $ref['kind'];
        $subject = $rows[self::SUBJECTS[$kind]][$ref[$kind.'_id']] ?? null;
        if (! $subject) {
            return false;
        }
        if (str_starts_with($kind, 'ba_')) {
            $request = $kind === 'ba_request_version' ? $subject : ($rows['ba_request_versions'][$subject['request_version_id']] ?? null);
            foreach ($rows['ba_request_links'] ?? [] as $link) {
                if ($link['request_version_id'] === ($request['id'] ?? null) && $link['task_version_id'] === $ref['task_version_id'] && $link['criterion_id'] === $ref['criterion_id'] && $link['criterion_version_id'] === $ref['criterion_version_id']) {
                    return true;
                }
            }

            return false;
        }
        $tuple = $kind === 'qa_handoff_event' ? ($rows['qa_handoffs'][$subject['handoff_id']] ?? []) : $subject;
        $task = $tuple['task_version_id'] ?? null;
        if ($kind === 'qa_execution_version') {
            $task = $rows['qa_receipts'][$subject['receipt_id']]['source_task_version_id'] ?? null;
        }

        return $task === $ref['task_version_id'] && ($tuple['criterion_id'] ?? null) === $ref['criterion_id'] && ($tuple['criterion_version_id'] ?? null) === $ref['criterion_version_id'];
    }

    private function current(array $ref, array $rows, ?string $baseline): bool
    {
        if (($rows['qa_applicability'] ?? []) || ($rows['ba_terminal_applicability'] ?? [])) {
            return false;
        }
        $kind = $ref['kind'];
        $id = $ref[$kind.'_id'];
        $subject = $rows[self::SUBJECTS[$kind]][$id];
        $head = match ($kind) {
            'qa_handoff_event' => $rows['qa_handoffs'][$subject['handoff_id']]['current_event_id'] ?? null,
            'qa_defect_event' => $rows['qa_defects'][$subject['defect_id']]['current_event_id'] ?? null,
            'qa_execution_version' => $rows['qa_execution_attempts'][$subject['attempt_id']]['current_version_id'] ?? null,
            'ba_request_version' => $rows['ba_clarifications'][$subject['clarification_id']]['current_request_id'] ?? null,
            'ba_answer_version' => $rows['ba_clarifications'][$subject['clarification_id']]['current_answer_id'] ?? null,
            'ba_review' => $rows['ba_clarifications'][$subject['clarification_id']]['current_review_id'] ?? null,
        };
        if ($head !== $id) {
            return false;
        }
        if ($kind === 'qa_execution_version') {
            $attempt = $rows['qa_execution_attempts'][$subject['attempt_id']] ?? [];
            $series = $rows['qa_execution_series'][$subject['series_id']] ?? [];
            if (($attempt['series_id'] ?? null) !== $subject['series_id'] || ($series['current_attempt_id'] ?? null) !== $subject['attempt_id'] || ($series['current_ordinal'] ?? null) !== ($attempt['ordinal'] ?? null)) {
                return false;
            }
        }
        if (str_starts_with($kind, 'qa_')) {
            foreach ($rows['qa_definition_versions'] ?? [] as $definition) {
                $identity = $rows['qa_definition_identities'][$definition['test_identity_id']] ?? [];
                $review = $rows['qa_reviews'][$definition['current_review_id'] ?? ''] ?? [];
                if (($identity['current_definition_id'] ?? null) !== $definition['id'] || $definition['review_state'] !== 'reviewed' || ($review['definition_id'] ?? null) !== $definition['id'] || ($review['subject_hash'] ?? null) !== $definition['content_hash'] || ($review['decision'] ?? null) !== 'reviewed') {
                    return false;
                }
            }
            foreach ($rows['qa_selection_events'] ?? [] as $selection) {
                $plan = $rows['qa_plan_versions'][$selection['plan_id']] ?? [];
                $review = $rows['qa_reviews'][$selection['plan_review_id']] ?? [];
                if (($plan['baseline_id'] ?? null) !== $baseline || ($plan['current_review_id'] ?? null) !== $selection['plan_review_id'] || ($plan['review_state'] ?? null) !== 'approved' || ($review['plan_id'] ?? null) !== $selection['plan_id'] || ($review['subject_hash'] ?? null) !== ($plan['content_hash'] ?? null) || ($review['decision'] ?? null) !== 'approved') {
                    return false;
                }
                if (isset($subject['build_id']) && ($subject['build_id'] !== $selection['build_id'] || $subject['environment_id'] !== $selection['environment_id'])) {
                    return false;
                }
            }
            foreach ($rows['qa_plan_entry_definitions'] ?? [] as $link) {
                $definition = $rows['qa_definition_versions'][$link['definition_id']] ?? [];
                if (($definition['current_review_id'] ?? null) !== $link['definition_review_id']) {
                    return false;
                }
            }
        }
        $latestReceipts = [];
        foreach (['qa_receipts', 'ba_receipts'] as $table) {
            foreach ($rows[$table] ?? [] as $receipt) {
                if (($receipt['variant'] ?? 'source') === 'source') {
                    $natural = $table.':'.$receipt['source_instance_id'].':'.($receipt['family'] ?? '').':'.$receipt['object_id'];
                    $latestReceipts[$natural] = max($latestReceipts[$natural] ?? 0, $receipt['source_revision']);
                }
            }
        }
        foreach (['qa_receipts', 'ba_receipts'] as $table) {
            foreach ($rows[$table] ?? [] as $receipt) {
                if (($receipt['variant'] ?? 'source') !== 'source') {
                    continue;
                }
                $natural = $table.':'.$receipt['source_instance_id'].':'.($receipt['family'] ?? '').':'.$receipt['object_id'];
                if ($receipt['source_revision'] !== $latestReceipts[$natural]) {
                    continue;
                }
                $heads = $rows[substr($table, 0, 2).'_source_heads'] ?? [];
                $match = array_filter($heads, fn ($h) => $h['source_instance_id'] === $receipt['source_instance_id'] && $h['object_id'] === $receipt['object_id'] && ($table === 'qa_receipts' || $h['family'] === $receipt['family']));
                if (! $match || ! (($h = reset($match))['complete'] ?? $h['source_complete'] ?? false) || $h['receipt_id'] !== $receipt['id']) {
                    return false;
                }
            }
        }

        return true;
    }

    public function evaluateLocked(string $project, array $graph, array $rows, array $proofs): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.management_authority_closed',true),'')") !== '1') {
            throw new LogicException('Management source evaluation requires coordination.');
        }

        return $this->evaluatePrepared($graph, $rows, $this->prepareEvaluation($project, $graph, $rows, $proofs));
    }

    /** All database guards and responsibility capture occur once for the page. */
    public function evaluateBatchLocked(string $project, array $graph, array $rows, array $proofs): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.management_authority_closed',true),'')") !== '1') {
            throw new LogicException('Management source evaluation requires coordination.');
        }
        $prepared = $this->prepareEvaluation($project, $graph, $rows, $proofs);
        $results = [];
        foreach ($graph['boundaries'] as $boundary) {
            $part = $graph;
            $part['boundaries'] = [$boundary];
            $part['evaluation_subject'] = ['event_id' => $boundary['event_id']];
            $part['references'] = array_filter($graph['references'], fn ($r) => ($r['occurrence']['root_id'] ?? null) === $boundary['root_id']);
            $part['responsibility_owners'] = array_filter($graph['responsibility_owners'] ?? [], fn ($o) => ($o['root_id'] ?? null) === $boundary['root_id']);
            $boundaryPrepared = $prepared;
            // Evaluation belongs to this root; coordination still fences every
            // original in the independently admitted whole-page privacy union.
            $keys = [];
            foreach ($graph['boundaries'] as $retained) {
                if ($retained['root_id'] === $boundary['root_id']) {
                    $keys = [...$keys, ...($graph['boundary_origins'][$retained['event_id']]['proof_keys'] ?? [])];
                }
            }
            $sourceRefs = [];
            foreach ($part['references'] as $reference) {
                foreach ($reference['source_refs'] ?? [] as $table => $ids) {
                    foreach ($ids as $id) {
                        $sourceRefs[$table][$id] = true;
                    }
                }
            }
            foreach ($graph['proof_slots'] as $slot) {
                if (isset($sourceRefs[$slot['subject_kind']][$slot['subject_id']])) {
                    $keys[] = $slot['key'];
                }
            }
            $boundaryPrepared['required'] = array_values(array_filter($prepared['required'], fn ($key) => in_array($key, $keys, true)));
            $boundaryPrepared['proofReasons'] = array_values(array_intersect_key($prepared['proofReasonsByKey'], array_flip($boundaryPrepared['required'])));
            $selectedReferences = array_filter($part['references'], fn ($reference) => ($reference['occurrence']['event_id'] ?? null) === $boundary['event_id']);
            $selectedResponsibility = array_filter($part['responsibility_owners'], fn ($owner) => ($owner['event_id'] ?? null) === $boundary['event_id']);
            $boundaryPrepared['results'] = array_values(array_intersect_key($prepared['results'], $selectedReferences));
            $boundaryPrepared['responsibility'] = array_intersect_key($prepared['responsibility'], $selectedResponsibility);
            $boundaryPrepared['responsibilityReasons'] = array_intersect_key($prepared['responsibilityReasons'], $part['responsibility_owners']);
            $results[$boundary['event_id']] = $this->evaluatePrepared($part, $rows, $boundaryPrepared);
            $root = $rows['management_actions'][$boundary['root_id']];
            if (! in_array($root['current_event_id'], array_column($graph['boundaries'], 'event_id'), true)) {
                $results[$boundary['event_id']]['current_outcome'] = null;
                $results[$boundary['event_id']]['current_applicability'] = 'unknown';
                $results[$boundary['event_id']]['current_reason_codes'][] = 'current_projection_unavailable';
            }
        }

        return $results;
    }

    private function prepareEvaluation(string $project, array $graph, array $rows, array $proofs): array
    {
        $results = [];
        $reason = [];
        $required = [];
        $proofReasonsByKey = [];
        foreach ($graph['proof_slots'] ?? [] as $slot) {
            if ($slot['mandatory']) {
                $required[] = $slot['key'];
                if (! ($proofs[$slot['key']]['valid'] ?? false)) {
                    $reason[] = $proofReasonsByKey[$slot['key']] = $proofs[$slot['key']]['reason_code'] ?? 'historical_proof_missing';
                }
            }
        }
        $proofReasons = $reason;
        $referenceReasons = [];
        foreach ($graph['references'] ?? [] as $i => $ref) {
            $referenceReasons[$i] = [];
            $current = $ref['task_current_version_id'] === $ref['task_version_id'] && $ref['requirement_current_version_id'] === $ref['requirement_version_id'] && ($ref['source_current'] ?? true) && (! isset($ref['expected_source_snapshot_hash']) || $ref['expected_source_snapshot_hash'] === $ref['source_snapshot_hash']);
            if (! ($ref['source_time_known'] ?? true)) {
                $current = false;
                $referenceReasons[$i][] = 'source_time_unknown';
            }
            $dependency = 'unresolved';
            if ($ref['kind'] !== 'lifecycle') {
                $subject = $rows[self::SUBJECTS[$ref['kind']]][$ref[$ref['kind'].'_id']] ?? [];
                if (str_starts_with($ref['kind'], 'ba_')) {
                    $root = $rows['ba_clarifications'][$subject['clarification_id']] ?? [];
                    $origin = array_filter($rows['ba_scope_obligations'] ?? [], fn ($o) => $o['clarification_id'] === $subject['clarification_id']);
                    $dependency = $origin ? 'scope_change_required' : ($root['state'] ?? 'unknown');
                } else {
                    $dependency = $subject['state'] ?? $subject['outcome'] ?? 'unknown';
                }
            }
            $results[] = ['kind' => $ref['kind'], 'task_version_id' => $ref['task_version_id'], 'criterion_id' => $ref['criterion_id'], 'criterion_version_id' => $ref['criterion_version_id'], 'requirement_version_id' => $ref['requirement_version_id'], 'applicability' => ($ref['source_terminal_invalidity'] ?? false) ? 'revoked' : ($current ? 'current' : 'stale'), 'underlying_dependency_status' => $dependency];
            if (! $current) {
                $referenceReasons[$i][] = ($ref['source_terminal_invalidity'] ?? false) ? 'source_revoked' : 'source_stale';
            }
        }
        $responsibility = app(ManagementResponsibility::class)->captureBatchLocked($project, $graph['responsibility_slots'] ?? [], $rows, $proofs);
        $responsibilityReasons = [];
        foreach ($graph['responsibility_slots'] ?? [] as $slot) {
            if ($slot['mandatory'] && $responsibility[$slot['key']]['attribution'] !== 'known') {
                $responsibilityReasons[$slot['key']] = 'responsibility_unknown';
            }
        }

        return compact('results', 'proofReasons', 'proofReasonsByKey', 'referenceReasons', 'responsibility', 'responsibilityReasons', 'required');
    }

    private function evaluatePrepared(array $graph, array $rows, array $prepared): array
    {
        ['results' => $results, 'proofReasons' => $proofReasons, 'referenceReasons' => $referenceReasons, 'responsibility' => $responsibility, 'responsibilityReasons' => $responsibilityReasons, 'required' => $required] = $prepared;
        $event = $graph['boundaries'][0] ?? null;
        $root = is_array($event) ? ($rows['management_actions'][$event['root_id']] ?? null) : null;
        // Privacy still covers the union. Eligibility belongs to the exact fact
        // that owns each occurrence, rather than the first root's entire history.
        $selector = $graph['evaluation_subject'] ?? null;
        $prospective = (bool) ($selector['prospective'] ?? false);
        $targetEvent = $rows['management_events'][$selector['event_id'] ?? ''] ?? $event;
        $targetSupport = $selector['support_id'] ?? ($prospective ? null : ($targetEvent['subject_support_id'] ?? $targetEvent['support_id'] ?? null));
        $targetVersion = $selector['action_version_id'] ?? ($prospective ? null : ($rows['management_support_versions'][$targetSupport ?? '']['action_version_id'] ?? $targetEvent['action_version_id'] ?? null));
        $currentFacts = ['management_action_versions' => $root['current_action_version_id'] ?? null, 'management_support_versions' => $root['current_support_id'] ?? null];
        $subjectFacts = ['management_action_versions' => $targetVersion, 'management_support_versions' => $targetSupport];
        $currentProspective = $prospective && ($root === null || ($targetVersion !== null && $targetVersion === $root['current_action_version_id'] && ($selector['assignment_id'] ?? null) === ($root['current_assignment_id'] ?? null)));
        $matches = static fn (?array $owner, array $facts, bool $includeProspective) => $selector === null || $owner === null || (($owner['phase'] ?? null) === 'prospective' ? $includeProspective : (($facts[$owner['subject_kind']] ?? null) !== null && $facts[$owner['subject_kind']] === $owner['subject_id']));
        $assess = function (array $facts, bool $includeProspective) use ($graph, $rows, $proofReasons, $referenceReasons, $responsibilityReasons, $matches) {
            $reasons = $proofReasons;
            $selected = 0;
            foreach ($graph['references'] ?? [] as $i => $ref) {
                if ($matches($ref['occurrence'] ?? null, $facts, $includeProspective)) {
                    $selected++;
                    $reasons = [...$reasons, ...$referenceReasons[$i]];
                }
            }
            if ($selected === 0) {
                $reasons[] = 'source_missing';
            }
            foreach ($responsibilityReasons as $key => $value) {
                if ($matches($graph['responsibility_owners'][$key] ?? null, $facts, $includeProspective)) {
                    $reasons[] = $value;
                }
            }
            foreach ($facts as $table => $id) {
                $fact = $rows[$table][$id ?? ''] ?? null;
                $receipt = $rows['management_receipts'][$fact['receipt_id'] ?? ''] ?? null;
                if ($receipt && $receipt['variant'] === 'source') {
                    $head = array_values(array_filter($rows['management_source_heads'] ?? [], fn ($h) => $h['source_instance_id'] === $receipt['source_instance_id'] && $h['family'] === $receipt['family'] && $h['object_id'] === $receipt['object_id']))[0] ?? null;
                    if (! $head || ! $head['complete'] || $head['receipt_id'] !== $receipt['id'] || $head['source_revision'] !== $receipt['source_revision']) {
                        $reasons[] = 'management_source_stale';
                    }
                }
            }
            $reasons = array_values(array_unique($reasons));
            $applicability = $reasons ? (in_array('source_revoked', $reasons, true) ? 'revoked' : (array_diff($reasons, ['source_stale', 'management_source_stale']) ? 'unknown' : 'stale')) : 'current';

            return ['applicability' => $applicability, 'reason_codes' => $reasons];
        };
        $current = $assess($currentFacts, $currentProspective);
        $subject = $selector === null ? $current : $assess($subjectFacts, $prospective);

        return ['subject_fact' => $rows['management_support_versions'][$targetSupport ?? ''] ?? null, 'current_projection' => $root, 'historical_review_status' => $targetEvent['review_status'] ?? null, 'applicability' => $subject['applicability'], 'subject_applicability' => $subject['applicability'], 'current_applicability' => $current['applicability'], 'current_outcome' => $current['applicability'] === 'current' ? ($root['current_outcome'] ?? null) : null, 'reference_results' => $results, 'underlying_dependency_status' => array_values(array_unique(array_column($results, 'underlying_dependency_status'))), 'required_proof_keys' => $required, 'terminal_observations' => [], 'responsibility' => $responsibility, 'coverage' => ($graph['coverage'] ?? 'unknown'), 'reason_codes' => $subject['reason_codes'], 'current_reason_codes' => $current['reason_codes']];
    }

    private function empty(?string $reason = null): array
    {
        return ['coverage' => $reason === null ? 'complete' : 'unknown', 'reason_code' => $reason, 'references' => [], 'refs' => [], 'heads' => [], 'proof_slots' => [], 'named_slots' => [], 'responsibility_slots' => [], 'source_ids' => []];
    }
}
