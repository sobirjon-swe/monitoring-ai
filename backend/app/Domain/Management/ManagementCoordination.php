<?php

namespace App\Domain\Management;

use App\Domain\Access\TrustedActor;
use App\Support\LocalManagementDatabase;
use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class ManagementCoordination
{
    /** Fixed tables only; a graph never supplies a table, callable or SQL expression. */
    public const TABLES = ['management_actions', 'management_source_heads', 'management_project_state', 'lifecycle_tasks', 'requirements', 'lifecycle_project_state', 'qa_project_state', 'qa_applicability', 'qa_source_heads', 'qa_execution_series', 'qa_execution_attempts', 'qa_defects', 'qa_handoffs', 'qa_plan_versions', 'qa_definition_versions', 'ba_clarifications', 'ba_source_heads', 'ingestion_connectors'];

    public const SUBJECTS = ['management_receipts', 'management_action_versions', 'management_assignments', 'management_support_versions', 'management_reviews', 'management_events', 'qa_receipts', 'qa_changes', 'qa_reviews', 'qa_selection_events', 'qa_plan_entry_snapshots', 'qa_plan_versions', 'qa_defect_events', 'qa_handoff_events', 'ba_receipts', 'ba_request_versions', 'ba_answer_versions', 'ba_reviews', 'ba_events'];

    public const IMMUTABLE_REFS = ['management_reference_sets', 'management_references', 'management_terminal_applicability', 'task_versions', 'task_criterion_links', 'criterion_versions', 'requirement_versions', 'requirement_version_criteria', 'dod_policy_versions', 'baselines', 'human_acceptance_decisions', 'developer_snapshots', 'qa_build_versions', 'qa_environment_versions', 'qa_definition_identities', 'qa_plan_entry_snapshots', 'qa_plan_entries', 'qa_plan_entry_definitions', 'qa_execution_versions', 'qa_changes', 'qa_applicability', 'qa_invalidations', 'ba_source_party_versions', 'ba_request_links', 'ba_scope_obligations', 'ba_terminal_applicability'];

    public function __construct(private readonly TenantContext $context, private readonly ManagementAuthority $authority) {}

    /**
     * @param  array{coverage:string,refs?:array<string,list<string>>,heads?:array<string,array<string,array>>,proof_slots?:list<array{key:string,format:string,subject_kind:string,subject_id:string,proof_hash:string,actor_id:string,purposes:list<string>,pinned_proof:array,mandatory:bool}>,current_requests?:list<array>,named_slots?:list<array{key:string,employee_id:string,origin:string,mandatory:bool}>,responsibility_slots?:list<array{key:string,task_version_id:string,occurred_at:string,assignment_id?:string,phase:string,mandatory:bool}>}  $graph
     *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   Original proof slot keys bind complete stable subject/scope/authority identity.
     *                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   Current requests use the separately reserved internal authority request shape.
     */
    public function run(TrustedActor $actor, string $project, array $purposes, bool $requireOpen, array $graph, Closure $work, ?string $department = null): mixed
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        foreach (['app.management_authority_closed', 'app.qa_authority_closed', 'app.ba_authority_closed'] as $setting) {
            if (DB::scalar('select nullif(current_setting(?,true),\'\')', [$setting]) !== null) {
                throw new LogicException('Nested management or source coordination is prohibited.');
            }
        }
        if (! Str::isUuid($project) || ($graph['coverage'] ?? 'unknown') !== 'complete') {
            throw new ServiceUnavailableHttpException(null, 'Management coverage unavailable.');
        }
        $graph = $this->normalizeGraph($actor, $project, $graph, $department);

        return $this->context->run($actor->tenantId, function () use ($actor, $project, $purposes, $requireOpen, $graph, $work, $department) {
            if (! DB::table('projects')->where('id', $project)->lockForUpdate()->first()) {
                throw new NotFoundHttpException;
            }
            $state = DB::table('lifecycle_project_state')->where('project_id', $project)->lockForUpdate()->first();
            if ($requireOpen && $state?->is_closed) {
                throw new ConflictHttpException('Project closed.');
            }
            $refs = $graph['refs'] ?? [];
            if (count($refs) > 64) {
                throw new ServiceUnavailableHttpException;
            }
            $count = 0;
            foreach ($refs as $table => $ids) {
                if (! is_array($ids) || ! array_is_list($ids)) {
                    throw new LogicException('Invalid management graph references.');
                }
                $count += count($ids);
                foreach ($ids as $id) {
                    if (! is_string($id) || ! Str::isUuid($id)) {
                        throw new LogicException('Invalid management graph reference.');
                    }
                }
            }
            if ($count > 4000) {
                throw new ServiceUnavailableHttpException;
            }
            foreach (self::TABLES as $table) {
                if (isset($refs[$table])) {
                    if (in_array($table, ['lifecycle_project_state', 'qa_project_state'], true)) {
                        DB::table($table)->where('project_id', $project)->lockForUpdate()->get(['project_id']);

                        continue;
                    }
                    $ids = array_values(array_unique($refs[$table]));
                    sort($ids, SORT_STRING);
                    DB::table($table)->where('project_id', $project)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
                }
            }
            foreach ($graph['heads'] ?? [] as $table => $heads) {
                $identity = in_array($table, ['lifecycle_project_state', 'qa_project_state'], true) ? 'project_id' : 'id';
                $columns = array_values(array_unique([$identity, ...array_merge([], ...array_map('array_keys', array_values($heads)))]));
                $captured = DB::table($table)->where('project_id', $project)->whereIn($identity, array_keys($heads))->orderBy($identity)->get($columns)->keyBy($identity);
                foreach ($heads as $id => $fields) {
                    $row = $captured->get($id);
                    if (! $row || array_intersect_key((array) $row, $fields) != $fields) {
                        throw new ConflictHttpException('Management captured head changed.');
                    }
                }
            }
            $current = ['key' => 'current', 'format' => 'management-authority-v1', 'actor_id' => $actor->id, 'purposes' => $purposes, 'required' => true];
            $proofs = $this->authority->captureBatch($actor, $project, [$current, ...($graph['current_requests'] ?? []), ...($graph['proof_slots'] ?? [])], $department, $graph['named_slots'] ?? [], $graph['responsibility_slots'] ?? []);
            DB::select("select set_config('app.management_authority_closed','1',true),set_config('app.management_proofs',?,true),set_config('app.management_required_keys','[]',true)", [ManagementContract::canonical($proofs)]);
            $result = $work($proofs);
            foreach (json_decode(DB::scalar("select current_setting('app.management_required_keys')"), true, 8, JSON_THROW_ON_ERROR) as $key) {
                $proofs[$key]['required'] = true;
            }
            $this->authority->assertEffective($proofs, DB::scalar('select clock_timestamp()'));
            // On failure rollback restores every SET LOCAL without issuing SQL
            // against an aborted transaction; success restores the savepoint scope.
            DB::select("select set_config('app.management_authority_closed','',true),set_config('app.management_proofs','',true),set_config('app.management_required_keys','',true)");

            return $result;
        });
    }

    private function normalizeGraph(TrustedActor $actor, string $project, array $graph, ?string $department): array
    {
        foreach (['proof_slots' => 2700, 'current_requests' => 201, 'named_slots' => 2000, 'responsibility_slots' => 1000] as $field => $limit) {
            $graph[$field] ??= [];
            if (! is_array($graph[$field] ?? []) || ! array_is_list($graph[$field] ?? []) || count($graph[$field] ?? []) > $limit) {
                throw new ServiceUnavailableHttpException(null, 'Management coverage unavailable.');
            }
        }
        foreach ($graph['refs'] ?? [] as $table => $ids) {
            if (! in_array($table, [...self::TABLES, ...self::SUBJECTS, ...self::IMMUTABLE_REFS], true)) {
                throw new LogicException('Unknown management graph table.');
            }
            if (in_array($table, ['lifecycle_project_state', 'qa_project_state'], true) && $ids !== [$project]) {
                throw new LogicException('Invalid singleton management identity.');
            }
        }
        foreach ($graph['heads'] ?? [] as $table => $heads) {
            if (! in_array($table, self::TABLES, true) || ! is_array($heads) || count($heads) > 200) {
                throw new LogicException('Invalid management head map.');
            }
            foreach ($heads as $id => $fields) {
                if (! Str::isUuid($id) || ! in_array($id, $graph['refs'][$table] ?? [], true) || ! is_array($fields) || array_is_list($fields) || count($fields) > 30) {
                    throw new LogicException('Invalid management captured head.');
                }
            }
        }
        foreach ($graph['proof_slots'] as &$slot) {
            $expectedFormat = match (true) {
                str_starts_with($slot['subject_kind'] ?? '', 'qa_') => 'qa-authority-v1', str_starts_with($slot['subject_kind'] ?? '', 'ba_') => 'ba-authority-v1', default => 'management-authority-v1'
            };
            if (($slot['format'] ?? '') !== $expectedFormat) {
                throw new LogicException('Original proof format does not match subject kind.');
            }
            if (! is_array($slot) || array_diff(array_keys($slot), ['key', 'format', 'subject_kind', 'subject_id', 'proof_hash', 'actor_id', 'purposes', 'pinned_proof', 'mandatory']) || ! in_array($slot['subject_kind'] ?? '', self::SUBJECTS, true) || ! Str::isUuid($slot['subject_id'] ?? '') || ! is_array($slot['pinned_proof'] ?? null) || ! is_bool($slot['mandatory'] ?? null) || ! is_array($slot['purposes'] ?? null) || ! array_is_list($slot['purposes']) || ! $slot['purposes'] || ($slot['proof_hash'] ?? '') !== ManagementContract::proofHash($slot['pinned_proof']) || ($slot['key'] ?? '') !== ManagementContract::proofKey($actor->tenantId, $project, $slot, $department)) {
                throw new LogicException('Invalid management original proof slot.');
            }
            $slot = ['key' => $slot['key'], 'format' => $slot['format'], 'actor_id' => $slot['actor_id'], 'purposes' => $slot['purposes'], 'pinned_proof' => $slot['pinned_proof'], 'required' => $slot['mandatory']];
        }
        unset($slot);
        foreach ($graph['current_requests'] ?? [] as $request) {
            if (($request['actor_id'] ?? '') !== $actor->id || ($request['required'] ?? null) !== true || isset($request['pinned_proof']) || ($request['format'] ?? 'management-authority-v1') !== 'management-authority-v1' || ! str_starts_with($request['key'] ?? '', 'current:')) {
                throw new LogicException('Invalid management current request.');
            }
        }
        foreach ($graph['named_slots'] as &$slot) {
            if (! is_array($slot) || array_diff(array_keys($slot), ['key', 'employee_id', 'origin', 'mandatory']) || ! in_array($slot['origin'] ?? '', ['management', 'lifecycle', 'qa', 'ba'], true) || ! is_bool($slot['mandatory'] ?? null)) {
                throw new LogicException('Invalid management named slot.');
            }
            $slot = array_intersect_key($slot, array_flip(['key', 'employee_id']));
        }
        unset($slot);
        foreach ($graph['responsibility_slots'] as &$slot) {
            if (! is_array($slot) || array_diff(array_keys($slot), ['key', 'task_version_id', 'occurred_at', 'assignment_id', 'phase', 'mandatory']) || ! in_array($slot['phase'] ?? '', ['current', 'historical'], true) || ! is_bool($slot['mandatory'] ?? null) || (isset($slot['assignment_id']) && ! Str::isUuid($slot['assignment_id']))) {
                throw new LogicException('Invalid management responsibility slot.');
            }
            $slot = array_intersect_key($slot, array_flip(['key', 'task_version_id', 'occurred_at']));
        }
        unset($slot);

        return $graph;
    }
}
