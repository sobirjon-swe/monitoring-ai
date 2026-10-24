<?php

namespace App\Domain\Management;

use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;

final class ManagementReadEligibility
{
    /** Typed, server-owned metadata certificates; no source payload is selected. */
    public function certificates(array $graph): array
    {
        $certificates = [];
        foreach ($graph['boundaries'] as $boundary) {
            $origin = $graph['boundary_origins'][$boundary['event_id']];
            $named = $this->canonicalNames($origin['facts']);
            $sources = $origin['source_ids'];
            $people = $origin['employee_ids'];
            $keys = $origin['proof_keys'];
            $subjects = $origin['subjects'];
            $purposes = ['requirements.read', 'progress.read'];
            $sourceRefs = [];
            $referenceNamedCounts = [];
            $actorMappingCharge = 0;
            foreach ($graph['references'] as $reference) {
                if (($reference['occurrence']['event_id'] ?? null) !== $boundary['event_id']) {
                    continue;
                }
                $people[] = $reference['assignee_id'];
                $mappings = $this->actorMappings($reference, $graph);
                $actorMappingCharge += count($mappings);
                $referenceNamed = 1 + count($mappings);
                $people = [...$people, ...array_column($mappings, 'employee_id')];
                foreach (['qa', 'ba'] as $family) {
                    if (str_starts_with($reference['kind'], $family.'_')) {
                        $purposes = [...$purposes, $family.'.read', $family.'.source.read'];
                    }
                }
                foreach ($reference['source_refs'] ?? [] as $table => $ids) {
                    foreach ($ids as $id) {
                        $sourceRefs[$table][$id] = true;
                        $metadata = $graph['privacy_metadata'][$table][$id] ?? [];
                        $people = [...$people, ...($metadata['employee_ids'] ?? [])];
                        $referenceNamed += count($metadata['employee_ids'] ?? []);
                        $sources = [...$sources, ...($metadata['source_ids'] ?? [])];
                    }
                }
                $referenceNamedCounts[] = $referenceNamed;
            }
            foreach ($graph['proof_slots'] as $slot) {
                if (! isset($sourceRefs[$slot['subject_kind']][$slot['subject_id']])) {
                    continue;
                }
                $keys[] = $slot['key'];
                $subjects[] = ['table' => $slot['subject_kind'], 'id' => $slot['subject_id']];
                $proof = $slot['pinned_proof'];
                $people[] = $proof['employee_id'];
                foreach (['named', 'coordinators', 'request_subjects'] as $field) {
                    $people = [...$people, ...array_column($proof[$field] ?? [], 'employee_id')];
                }
                $sources = [...$sources, ...array_column($proof['sources'] ?? [], 'id')];
            }
            $points = [];
            foreach ($graph['proof_slots'] as $slot) {
                if (in_array($slot['key'], $keys, true)) {
                    $points[] = ['subject_id' => $slot['subject_id'], 'proof_hash' => $slot['proof_hash']];
                }
            }
            $certificates[] = ['ordinal' => count($certificates) + 1, 'root_id' => $boundary['root_id'], 'event_id' => $boundary['event_id'], 'revision' => (int) $boundary['revision'], 'proof_keys' => array_values(array_unique($keys)), 'proof_points' => $points, 'employee_ids' => array_values(array_unique($people)), 'source_ids' => array_values(array_unique($sources)), 'purposes' => array_values(array_unique($purposes)), 'subjects' => $subjects, 'boundary_named_occurrences' => count($named), 'named_occurrences' => $named, 'reference_named_counts' => $referenceNamedCounts, 'actor_mapping_charge' => $actorMappingCharge];
        }

        return $certificates;
    }

    /** Exact captured actor-mapping occurrences, reused only within this reference. */
    public function actorMappings(array $reference, array $graph): array
    {
        $mappings = [];
        foreach ($graph['proof_slots'] as $slot) {
            if (! in_array($slot['subject_id'], $reference['source_refs'][$slot['subject_kind']] ?? [], true)) {
                continue;
            }
            $proof = $slot['pinned_proof'];
            $mapping = ['format' => $slot['format'], 'actor_id' => $proof['actor_id'], 'employee_id' => $proof['employee_id'], 'project_id' => $proof['project_id'], 'membership' => $proof['membership'], 'original_department_id' => $proof['department_id'] ?? null];
            if (array_key_exists('roles', $proof)) {
                $mapping['roles'] = $proof['roles'];
            }
            if (array_key_exists('department_membership', $proof)) {
                $mapping['department_membership'] = $proof['department_membership'];
            }
            $mappings[hash('sha256', ManagementContract::canonical($mapping))] = $mapping;
        }

        return array_values($mappings);
    }

    /** Alias only an identical role on the exact immutable support/proof origin. */
    private function canonicalNames(array $facts): array
    {
        $named = [];
        foreach ($facts as $table => $rows) {
            foreach ($rows as $fact) {
                foreach (['owner_id', 'helper_id', 'recorder_id', 'requester_id', 'reviewer_id'] as $field) {
                    if (! isset($fact[$field])) {
                        continue;
                    }
                    $subject = $fact['id'];
                    $kind = $table;
                    $named[$kind.':'.$subject.':'.$field] = ['subject_kind' => $kind, 'subject_id' => $subject, 'role' => $field, 'employee_id' => $fact[$field]];
                }
            }
        }
        $actors = [];
        foreach ($facts as $table => $rows) {
            foreach ($rows as $fact) {
                $proof = $fact['authority_proof'];
                $receipt = $table === 'management_receipts' ? $fact['id'] : ($fact['receipt_id'] ?? $fact['id']);
                $identity = $receipt.':'.hash('sha256', ManagementContract::canonical($proof));
                $actors[$identity] = ['subject_kind' => 'management_receipts', 'subject_id' => $receipt, 'role' => 'actor', 'employee_id' => $proof['employee_id']];
            }
        }
        foreach ($actors as $identity => $actor) {
            $represented = false;
            foreach ($named as $occurrence) {
                if ($occurrence['role'] === 'actor') {
                    continue;
                }
                $actorRole = match ($occurrence['subject_kind']) {
                    'management_action_versions' => 'requester_id',
                    'management_support_versions' => 'recorder_id',
                    'management_reviews' => 'reviewer_id',
                    default => null,
                };
                if ($occurrence['role'] !== $actorRole) {
                    continue;
                }
                $fact = $facts[$occurrence['subject_kind']][$occurrence['subject_id']];
                if ($fact['receipt_id'] === $actor['subject_id'] && $occurrence['employee_id'] === $actor['employee_id'] && $identity === $fact['receipt_id'].':'.hash('sha256', ManagementContract::canonical($fact['authority_proof']))) {
                    $represented = true;
                    break;
                }
            }
            if (! $represented) {
                $named['actor:'.$identity] = $actor;
            }
        }

        return array_values($named);
    }

    /** SQL eligibility runs over the complete mandatory union before public LIMIT. */
    public function selectLocked(TrustedActor $actor, string $project, array $certificates, array $proofs, ?string $department, int $limit): array
    {
        return $this->select($actor, $project, $certificates, $proofs, $department, $limit, false);
    }

    /** Current privacy only: no original-invalidity observation precedes this gate. */
    public function currentVisibleLocked(TrustedActor $actor, string $project, array $certificates, array $proofs, ?string $department): array
    {
        return $this->select($actor, $project, $certificates, $proofs, $department, 50, true);
    }

    private function select(TrustedActor $actor, string $project, array $certificates, array $proofs, ?string $department, int $limit, bool $currentOnly): array
    {
        $current = $proofs['current']['proof'] ?? [];
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.management_authority_closed',true),'')") !== '1' || ! ($proofs['current']['valid'] ?? false) || ($current['actor_id'] ?? null) !== $actor->id || ($current['tenant_id'] ?? null) !== $actor->tenantId || ($current['project_id'] ?? null) !== $project || ($current['department_id'] ?? null) !== $department || count($certificates) > 50 || $limit < 1 || $limit > 50) {
            throw new \LogicException('Management admission requires matching coordination.');
        }
        $valid = [];
        foreach ($proofs as $key => $result) {
            if ($result['valid']) {
                $valid[] = $key;
            }
        }

        return array_column(DB::select(<<<'SQL'
WITH clock AS (SELECT clock_timestamp() AS at), candidates AS (
 SELECT c.* FROM jsonb_to_recordset(?::jsonb) AS c(ordinal integer,root_id uuid,event_id uuid,revision integer,proof_keys jsonb,proof_points jsonb,employee_ids jsonb,source_ids jsonb,purposes jsonb,subjects jsonb)
)
SELECT c.event_id FROM candidates c CROSS JOIN clock
WHERE (?::boolean OR NOT EXISTS(SELECT 1 FROM jsonb_array_elements_text(c.proof_keys) k WHERE NOT (k.value=ANY(?::text[]))))
 AND (?::boolean OR NOT EXISTS(SELECT 1 FROM jsonb_to_recordset(c.proof_points) p(subject_id uuid,proof_hash text) JOIN management_terminal_applicability t ON t.project_id=?::uuid AND t.kind='proof' AND t.subject_id=p.subject_id AND t.proof_hash=p.proof_hash))
 AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements_text(c.employee_ids) person
  WHERE NOT EXISTS(SELECT 1 FROM employees e WHERE e.id=person.value::uuid)
  OR (SELECT count(*) FROM project_memberships m WHERE m.project_id=?::uuid AND m.employee_id=person.value::uuid AND m.valid_from<=clock.at AND (m.valid_to IS NULL OR m.valid_to>clock.at))<>1
  OR (?::uuid IS NOT NULL AND (SELECT count(*) FROM department_memberships m WHERE m.department_id=?::uuid AND m.employee_id=person.value::uuid AND m.valid_from<=clock.at AND (m.valid_to IS NULL OR m.valid_to>clock.at))<>1))
 AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements_text(c.source_ids) source
  WHERE NOT EXISTS(SELECT 1 FROM source_instances s JOIN ingestion_connectors h ON h.source_instance_id=s.id AND h.project_id=?::uuid WHERE s.id=source.value::uuid AND s.kind='manual' AND h.status='active' AND h.adapter='synthetic-v1'))
 AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements_text(c.purposes) purpose
  WHERE (SELECT count(*) FROM access_grants g JOIN policy_versions p ON p.id=g.policy_version_id AND p.status='active' WHERE g.user_id=?::uuid AND g.project_id=?::uuid AND g.scope_type='project' AND g.purpose=purpose.value AND g.revoked_at IS NULL AND g.valid_from<=clock.at AND (g.valid_to IS NULL OR g.valid_to>clock.at))<>1)
 AND (?::uuid IS NULL OR NOT EXISTS(SELECT 1 FROM jsonb_array_elements_text(c.purposes) purpose WHERE purpose.value IN ('qa.read','ba.read')
  AND (SELECT count(*) FROM access_grants g JOIN policy_versions p ON p.id=g.policy_version_id AND p.status='active' WHERE g.user_id=?::uuid AND g.department_id=?::uuid AND g.scope_type='department' AND g.purpose=replace(purpose.value,'.read','.department.read') AND g.revoked_at IS NULL AND g.valid_from<=clock.at AND (g.valid_to IS NULL OR g.valid_to>clock.at))<>1))
ORDER BY c.ordinal LIMIT ?
SQL, [json_encode($certificates, JSON_THROW_ON_ERROR), $currentOnly, '{'.implode(',', $valid).'}', $currentOnly, $project, $project, $department, $department, $project, $actor->id, $project, $department, $actor->id, $department, $limit]), 'event_id');
    }
}
