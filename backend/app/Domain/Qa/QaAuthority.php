<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class QaAuthority
{
    public const PURPOSES = ['qa.fixture.manage', 'qa.plan.manage', 'qa.execution.record', 'qa.defect.review', 'qa.handoff.manage', 'qa.read', 'qa.source.read', 'qa.process'];

    public function captureBatch(TrustedActor $scope, string $project, array $requests, ?string $department = null): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.tenant_id',true),'')") !== $scope->tenantId || DB::scalar("select nullif(current_setting('app.qa_authority_closed',true),'')") !== null) {
            throw new LogicException('QA authority requires an open coordination phase.');
        }
        if (! Str::isUuid($project) || ($department !== null && ! Str::isUuid($department))) {
            throw new AccessDeniedHttpException;
        }
        $actors = [];
        $originalActors = [];
        $keys = [];
        $refs = [];
        $ownerIds = [];
        $departmentSubjectIds = [];
        foreach ($requests as $r) {
            if (array_diff(array_keys($r), ['key', 'actor_id', 'purposes', 'pinned_proof', 'required', 'responsibility_refs', 'owner_refs', 'department_refs']) || ! is_string($r['key'] ?? null) || ! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $r['key']) || isset($keys[$r['key']])
                || ! Str::isUuid($r['actor_id'] ?? '') || ! is_bool($r['required'] ?? null) || ! is_array($r['purposes'] ?? null) || ! array_is_list($r['purposes']) || ! $r['purposes'] || array_diff($r['purposes'], [...self::PURPOSES, 'qa.department.read'])) {
                throw new AccessDeniedHttpException;
            }
            $keys[$r['key']] = true;
            $actors[$r['actor_id']] = true;
            if ($r['key'] !== 'current') {
                $originalActors[$r['actor_id']] = true;
            }
            if (array_key_exists('owner_refs', $r)) {
                if (! is_array($r['owner_refs']) || ! array_is_list($r['owner_refs']) || count($r['owner_refs']) > 100) {
                    throw new AccessDeniedHttpException;
                }
                $seenOwners = [];
                foreach ($r['owner_refs'] as $owner) {
                    if (! is_string($owner) || ! Str::isUuid($owner) || isset($seenOwners[$owner])) {
                        throw new AccessDeniedHttpException;
                    }
                    $seenOwners[$owner] = $ownerIds[$owner] = true;
                }
            }
            if (array_key_exists('department_refs', $r)) {
                if ($department === null || ! in_array('qa.department.read', $r['purposes'], true) || ! is_array($r['department_refs']) || ! array_is_list($r['department_refs']) || count($r['department_refs']) > 2000) {
                    throw new AccessDeniedHttpException;
                }
                $seenSubjects = [];
                foreach ($r['department_refs'] as $subject) {
                    if (! is_string($subject) || ! Str::isUuid($subject) || isset($seenSubjects[$subject])) {
                        throw new AccessDeniedHttpException;
                    }
                    $seenSubjects[$subject] = $departmentSubjectIds[$subject] = true;
                }
            }
            if (isset($r['responsibility_refs']) && (! is_array($r['responsibility_refs']) || ! array_is_list($r['responsibility_refs']))) {
                throw new AccessDeniedHttpException;
            }
            foreach ($r['responsibility_refs'] ?? [] as $ref) {
                if (! is_array($ref) || array_diff(array_keys($ref), ['task_version_id', 'identity_id', 'occurred_at']) || ! Str::isUuid($ref['task_version_id'] ?? '') || (isset($ref['identity_id']) && ! Str::isUuid($ref['identity_id'])) || (isset($ref['occurred_at']) && ! is_string($ref['occurred_at']))) {
                    throw new AccessDeniedHttpException;
                }
                if (isset($ref['occurred_at']) && app(QaResponsibility::class)->elapsedAge($ref['occurred_at'], $ref['occurred_at'])['reason_code'] !== null) {
                    throw new AccessDeniedHttpException;
                }
                $refs[] = $ref;
            }
        }
        // Each reference has an own-proof and a receipt-proof slot, plus one caller proof.
        if (count($requests) > 2 * QaPins::MAX_REFERENCE_ROWS + 1 || count($refs) > QaPins::MAX_REFERENCE_ROWS || count($ownerIds) > 100 || count($departmentSubjectIds) > 2000) {
            throw new AccessDeniedHttpException;
        }
        $results = [];
        if (count($originalActors) > 100) {
            $required = [];
            $actors = [];
            $refs = [];
            $ownerIds = [];
            $departmentSubjectIds = [];
            foreach ($requests as $r) {
                if (! $r['required']) {
                    $results[$r['key']] = ['valid' => false, 'reason_code' => 'coverage_limit'];
                } else {
                    $required[] = $r;
                    $actors[$r['actor_id']] = true;
                    foreach ($r['responsibility_refs'] ?? [] as $ref) {
                        $refs[] = $ref;
                    }
                    foreach ($r['owner_refs'] ?? [] as $owner) {
                        $ownerIds[$owner] = true;
                    }
                    foreach ($r['department_refs'] ?? [] as $subject) {
                        $departmentSubjectIds[$subject] = true;
                    }
                }
            }
            $requiredOriginal = [];
            foreach ($required as $r) {
                if ($r['key'] !== 'current') {
                    $requiredOriginal[$r['actor_id']] = true;
                }
            }
            if (count($requiredOriginal) > 100) {
                throw new AccessDeniedHttpException;
            }
            $requests = $required;
        }
        $ids = array_keys($actors);
        sort($ids, SORT_STRING);
        $tenant = DB::table('tenants')->where('id', $scope->tenantId)->sharedLock()->first();
        $users = DB::table('users')->whereIn('id', $ids)->orderBy('id')->sharedLock()->get()->keyBy('id');
        // Responsibility rows are part of the same authority phase, never acquired by a callback.
        $taskIds = array_values(array_unique(array_column($refs, 'task_version_id')));
        sort($taskIds, SORT_STRING);
        // Immutable task versions need no row lock (the runtime cannot UPDATE them).
        // The project lock excludes concurrent succession. Exact next revisions bound each
        // immutable task interval without materializing its potentially unbounded history.
        $tasks = DB::table('task_versions as v')->leftJoin('lifecycle_tasks as h', 'h.id', '=', 'v.task_id')
            ->leftJoin('task_versions as n', fn ($q) => $q->on('n.task_id', '=', 'v.task_id')->on('n.project_id', '=', 'v.project_id')->whereRaw('n.revision=v.revision+1'))
            ->where('v.project_id', $project)->whereIn('v.id', $taskIds)->orderBy('v.id')
            ->selectRaw('v.*,n.effective_at as effective_until,((h.current_version_id=v.id AND h.revision=v.revision AND n.id IS NULL) OR (h.revision>v.revision AND n.id IS NOT NULL AND n.effective_at>=v.effective_at)) as interval_known')->get();
        $identityIds = array_values(array_unique(array_filter(array_column($refs, 'identity_id'))));
        sort($identityIds, SORT_STRING);
        $identities = DB::table('identities')->whereIn('id', $identityIds)->orderBy('id')->sharedLock()->get();
        $employeeIds = array_values(array_unique([...$users->pluck('employee_id')->filter()->all(), ...$tasks->pluck('assignee_id')->all(), ...array_keys($ownerIds), ...array_keys($departmentSubjectIds)]));
        sort($employeeIds, SORT_STRING);
        $employees = DB::table('employees')->whereIn('id', $employeeIds)->orderBy('id')->sharedLock()->get()->keyBy('id');
        $candidateAt = DB::scalar('select clock_timestamp()');
        $memberIds = $departmentIds = $grantIds = $memberPoints = $departmentPoints = $grantPoints = $rolePoints = $mappingPoints = $contexts = [];
        $ownerHorizons = [];
        $ownerFutureIds = [];
        if ($ownerIds) {
            // Uniqueness can change when an already-recorded future membership starts.
            // Fetch only its earliest boundary per owner, then join the sorted SHARE row union.
            $futureOwners = DB::select('SELECT p.employee_id,r.id,r.valid_from FROM jsonb_to_recordset(?::jsonb) AS p(employee_id uuid) CROSS JOIN LATERAL (SELECT t.id,t.valid_from FROM project_memberships t WHERE t.employee_id=p.employee_id AND t.project_id=?::uuid AND t.valid_from>?::timestamptz ORDER BY t.valid_from,t.id LIMIT 1) r', [json_encode(array_map(fn ($owner) => ['employee_id' => $owner], array_keys($ownerIds)), JSON_THROW_ON_ERROR), $project, $candidateAt]);
            foreach ($futureOwners as $future) {
                $ownerHorizons[$future->employee_id] = $future->valid_from;
                $ownerFutureIds[$future->employee_id] = $future->id;
                $memberIds[] = $future->id;
            }
        }
        foreach (array_keys($ownerIds) as $owner) {
            $key = 'owner:'.$owner;
            $memberPoints[$key] = ['key' => $key, 'employee_id' => $owner, 'project_id' => $project, 'at' => $candidateAt];
        }
        foreach ($requests as $request) {
            $pin = $request['pinned_proof'] ?? null;
            if (is_array($pin)) {
                $memberIds[] = $pin['membership']['id'] ?? null;
                $departmentIds[] = $pin['department_membership']['id'] ?? null;
                foreach ($pin['grants'] ?? [] as $grant) {
                    $grantIds[] = $grant['id'] ?? null;
                }
            } elseif ($user = $users->get($request['actor_id'])) {
                $memberPoints[$user->id] = ['key' => $user->id, 'employee_id' => $user->employee_id, 'project_id' => $project, 'at' => $candidateAt];
                foreach ($request['purposes'] as $purpose) {
                    $key = $user->id.':'.$purpose;
                    $grantPoints[$key] = ['key' => $key, 'user_id' => $user->id, 'purpose' => $purpose, 'project_id' => $project, 'department_id' => $department, 'at' => $candidateAt];
                    if ($purpose === 'qa.department.read' && $department !== null) {
                        $departmentPoints[$user->id] = ['key' => $user->id, 'employee_id' => $user->employee_id, 'department_id' => $department, 'at' => $candidateAt];
                    }
                }
            }
        }
        $taskRows = $tasks->keyBy('id');
        foreach ($refs as $ref) {
            if (! isset($ref['occurred_at']) || ! ($task = $taskRows->get($ref['task_version_id']))) {
                continue;
            }
            $key = QaResponsibility::contextKey($ref['task_version_id'], $ref['occurred_at'], $ref['identity_id'] ?? null);
            $contexts[$key] = true;
            $point = ['key' => $key, 'employee_id' => $task->assignee_id, 'project_id' => $project, 'at' => $ref['occurred_at']];
            $memberPoints[$key] = $rolePoints[$key] = $point;
            if (isset($ref['identity_id'])) {
                $mappingPoints[$key] = ['key' => $key, 'identity_id' => $ref['identity_id'], 'at' => $ref['occurred_at']];
            }
        }
        [$members, $memberOverflow] = $this->candidates('project_memberships', $memberIds, $memberPoints, 't.employee_id=p.employee_id AND t.project_id=p.project_id', ['project_id' => $project]);
        $lockedMembers = $members->keyBy('id');
        foreach ($ownerFutureIds as $owner => $id) {
            $lockedFuture = $lockedMembers->get($id);
            // A row changed before SHARE locking must never extend the probed horizon.
            if ($lockedFuture && CarbonImmutable::parse($lockedFuture->valid_from)->lessThan(CarbonImmutable::parse($ownerHorizons[$owner]))) {
                $ownerHorizons[$owner] = $lockedFuture->valid_from;
            }
        }
        $subjectCandidates = [];
        $subjectLockIds = [];
        if ($departmentSubjectIds) {
            // Two current rows prove ambiguity; one future row bounds uniqueness in time.
            $subjectRows = DB::select("SELECT p.employee_id,r.id,r.valid_from,r.kind FROM jsonb_to_recordset(?::jsonb) AS p(employee_id uuid) CROSS JOIN LATERAL ((SELECT t.id,t.valid_from,'current'::text AS kind FROM department_memberships t WHERE t.employee_id=p.employee_id AND t.department_id=?::uuid AND t.valid_from<=?::timestamptz AND (t.valid_to IS NULL OR t.valid_to>?::timestamptz) ORDER BY t.id LIMIT 2) UNION ALL (SELECT t.id,t.valid_from,'future'::text AS kind FROM department_memberships t WHERE t.employee_id=p.employee_id AND t.department_id=?::uuid AND t.valid_from>?::timestamptz ORDER BY t.valid_from,t.id LIMIT 1)) r", [json_encode(array_map(fn ($subject) => ['employee_id' => $subject], array_keys($departmentSubjectIds)), JSON_THROW_ON_ERROR), $department, $candidateAt, $candidateAt, $department, $candidateAt]);
            foreach ($subjectRows as $row) {
                $subjectLockIds[] = $row->id;
                if ($row->kind === 'current') {
                    $subjectCandidates[$row->employee_id]['current'][] = $row->id;
                } else {
                    $subjectCandidates[$row->employee_id]['future'] = $row->id;
                    $subjectCandidates[$row->employee_id]['horizon'] = $row->valid_from;
                }
            }
        }
        [$departments, $departmentOverflow, $departmentAuthorityIds] = $this->candidates('department_memberships', $departmentIds, $departmentPoints, 't.employee_id=p.employee_id AND t.department_id=p.department_id', ['department_id' => $department], $subjectLockIds);
        [$roles, $roleOverflow] = $this->candidates('role_assignments', [], $rolePoints, 't.employee_id=p.employee_id AND t.project_id=p.project_id', ['project_id' => $project]);
        [$mappings, $mappingOverflow] = $this->candidates('identity_mappings', [], $mappingPoints, "t.identity_id=p.identity_id AND t.status='approved'");
        // One bounded, transaction-local snapshot is handed to the pure responsibility capturer.
        $responsibility = ['project_id' => $project, 'contexts' => $contexts, 'overflow' => array_fill_keys([...$memberOverflow, ...$roleOverflow, ...$mappingOverflow], true), 'tasks' => $tasks->all(), 'identities' => $identities->all(), 'memberships' => $members->all(), 'roles' => $roles->all(), 'mappings' => $mappings->all()];
        DB::select("select set_config('app.qa_responsibility',?,true)", [json_encode($responsibility, JSON_THROW_ON_ERROR)]);
        [$grants, $grantOverflow] = $this->candidates('access_grants', $grantIds, $grantPoints, "t.user_id=p.user_id AND t.purpose=p.purpose AND t.revoked_at IS NULL AND ((p.purpose='qa.department.read' AND t.scope_type='department' AND t.department_id=p.department_id) OR (p.purpose<>'qa.department.read' AND t.scope_type='project' AND t.project_id=p.project_id))");
        $policies = DB::table('policy_versions')->whereIn('id', $grants->pluck('policy_version_id')->unique()->all())->orderBy('id')->sharedLock()->get()->keyBy('id');
        $memberById = $members->keyBy('id');
        $memberByEmployee = $members->groupBy('employee_id');
        $lockedDepartmentById = $departments->keyBy('id');
        $authorityDepartments = $departments->whereIn('id', $departmentAuthorityIds);
        $departmentById = $authorityDepartments->keyBy('id');
        $departmentByEmployee = $authorityDepartments->groupBy('employee_id');
        $grantById = $grants->keyBy('id');
        $grantByActorPurpose = $grants->groupBy(fn ($grant) => $grant->user_id.':'.$grant->purpose);
        $instant = DB::scalar('select clock_timestamp()');
        $effective = fn ($r) => CarbonImmutable::parse($r->valid_from)->lessThanOrEqualTo(CarbonImmutable::parse($instant)) && ($r->valid_to === null || CarbonImmutable::parse($r->valid_to)->greaterThan(CarbonImmutable::parse($instant)));
        foreach ($requests as $r) {
            if (array_intersect(array_map(fn ($owner) => 'owner:'.$owner, $r['owner_refs'] ?? []), $memberOverflow)) {
                if ($r['required']) {
                    throw new AccessDeniedHttpException;
                }
                $results[$r['key']] = ['valid' => false, 'reason_code' => 'coverage_limit'];

                continue;
            }
            if (! isset($r['pinned_proof']) && (in_array($r['actor_id'], $memberOverflow, true) || in_array($r['actor_id'], $departmentOverflow, true) || array_intersect(array_map(fn ($purpose) => $r['actor_id'].':'.$purpose, $r['purposes']), $grantOverflow))) {
                if ($r['required']) {
                    throw new AccessDeniedHttpException;
                }
                $results[$r['key']] = ['valid' => false, 'reason_code' => 'coverage_limit'];

                continue;
            }
            try {
                $user = $users->get($r['actor_id']);
                $pin = $r['pinned_proof'] ?? null;
                if (! $tenant || $tenant->status !== 'active' || ! $user || $user->status !== 'active' || ! $user->employee_id) {
                    throw new AccessDeniedHttpException;
                }
                $purposes = array_values(array_unique($r['purposes']));
                sort($purposes, SORT_STRING);
                if ($pin !== null && (! is_array($pin) || ($pin['version'] ?? '') !== 'qa-authority-v1' || ($pin['actor_id'] ?? '') !== $user->id || ($pin['purposes'] ?? null) !== $purposes)) {
                    throw new AccessDeniedHttpException;
                }
                $member = $pin === null ? $memberByEmployee->get($user->employee_id, collect())->first($effective) : $memberById->get($pin['membership']['id'] ?? '');
                if (! $member || $member->employee_id !== $user->employee_id || ! $effective($member)) {
                    throw new AccessDeniedHttpException;
                }
                $proof = ['version' => 'qa-authority-v1', 'tenant_id' => $scope->tenantId, 'project_id' => $project, 'actor_id' => $user->id, 'employee_id' => $user->employee_id, 'purposes' => $purposes,
                    'membership' => $this->fields($member, ['id', 'employee_id', 'valid_from', 'valid_to', 'revision']), 'department_id' => in_array('qa.department.read', $purposes, true) ? $department : null, 'department_membership' => null, 'grants' => [], 'captured_at' => $instant];
                if (in_array('qa.department.read', $purposes, true)) {
                    $dm = $pin === null ? $departmentByEmployee->get($user->employee_id, collect())->first($effective) : $departmentById->get($pin['department_membership']['id'] ?? '');
                    if (! $dm || $department === null || $dm->employee_id !== $user->employee_id || ! $effective($dm)) {
                        throw new AccessDeniedHttpException;
                    }
                    $proof['department_membership'] = $this->fields($dm, ['id', 'employee_id', 'valid_from', 'valid_to', 'revision']);
                }
                foreach ($purposes as $purpose) {
                    $pg = $pin === null ? null : collect($pin['grants'] ?? [])->firstWhere('purpose', $purpose);
                    $eligible = fn ($g) => $g->user_id === $user->id && $g->purpose === $purpose && $g->revoked_at === null && $effective($g) && $policies->get($g->policy_version_id)?->status === 'active'
                        && ($purpose === 'qa.department.read' ? ($g->scope_type === 'department' && $g->department_id === $department) : ($g->scope_type === 'project' && $g->project_id === $project));
                    $grant = $pin === null ? $grantByActorPurpose->get($user->id.':'.$purpose, collect())->first($eligible) : $grantById->get($pg['id'] ?? '');
                    if (! $grant || ! $eligible($grant)) {
                        throw new AccessDeniedHttpException;
                    }
                    $proof['grants'][] = $this->fields($grant, ['id', 'policy_version_id', 'purpose', 'scope_type', 'project_id', 'department_id', 'valid_from', 'valid_to', 'revision']) + ['policy_version' => $policies->get($grant->policy_version_id)->version];
                }
                if ($pin !== null) {
                    $compare = $proof;
                    unset($compare['captured_at'], $pin['captured_at']);
                    if (QaContract::canonical($compare) !== QaContract::canonical($pin)) {
                        throw new AccessDeniedHttpException;
                    }
                }
                $owners = [];
                $requestedOwners = $r['owner_refs'] ?? [];
                sort($requestedOwners, SORT_STRING);
                foreach ($requestedOwners as $owner) {
                    $employee = $employees->get($owner);
                    $memberships = $memberByEmployee->get($owner, collect())->filter($effective)->values();
                    $horizon = $ownerHorizons[$owner] ?? null;
                    if (! $employee || $employee->tenant_id !== $scope->tenantId || $memberships->count() !== 1 || ($horizon !== null && CarbonImmutable::parse($horizon)->lessThanOrEqualTo(CarbonImmutable::parse($instant)))) {
                        throw new AccessDeniedHttpException;
                    }
                    $owners[$owner] = ['employee_id' => $owner, 'membership' => $this->fields($memberships[0], ['id', 'revision', 'employee_id', 'valid_from', 'valid_to']), 'uniqueness_valid_until' => $horizon];
                }
                $results[$r['key']] = ['valid' => true, 'proof' => $proof, 'reason_code' => null];
                if ($owners) {
                    $results[$r['key']]['owners'] = $owners;
                }
                if (array_key_exists('department_refs', $r)) {
                    $subjects = [];
                    $requestedSubjects = $r['department_refs'];
                    sort($requestedSubjects, SORT_STRING);
                    foreach ($requestedSubjects as $subject) {
                        $subjects[$subject] = ['employee_id' => $subject, 'membership' => null, 'visibility' => 'unavailable', 'uniqueness_valid_until' => null];
                        $candidate = $subjectCandidates[$subject] ?? [];
                        // Never reinterpret a truncated ambiguous probe as a favorable subset.
                        if (count($candidate['current'] ?? []) !== 1) {
                            continue;
                        }
                        $employee = $employees->get($subject);
                        $membership = $lockedDepartmentById->get($candidate['current'][0]);
                        $horizon = $candidate['horizon'] ?? null;
                        $future = $lockedDepartmentById->get($candidate['future'] ?? '');
                        if ($future && CarbonImmutable::parse($future->valid_from)->lessThan(CarbonImmutable::parse($horizon))) {
                            $horizon = $future->valid_from;
                        }
                        if (! $employee || $employee->tenant_id !== $scope->tenantId || ! $membership || $membership->employee_id !== $subject || $membership->department_id !== $department || ! $effective($membership) || ($horizon !== null && CarbonImmutable::parse($horizon)->lessThanOrEqualTo(CarbonImmutable::parse($instant)))) {
                            continue;
                        }
                        $subjects[$subject] = ['employee_id' => $subject, 'membership' => $this->fields($membership, ['id', 'employee_id', 'department_id', 'revision', 'valid_from', 'valid_to']), 'visibility' => 'visible', 'uniqueness_valid_until' => $horizon];
                    }
                    $results[$r['key']]['department_subjects'] = $subjects;
                }
            } catch (AccessDeniedHttpException) {
                if ($r['required']) {
                    throw new AccessDeniedHttpException;
                }
                $results[$r['key']] = ['valid' => false, 'reason_code' => 'access_revoked'];
            }
        }

        return $results;
    }

    public function assertEffective(array $proofResults, string $dbInstant): void
    {
        $at = CarbonImmutable::parse($dbInstant);
        foreach ($proofResults as $result) {
            if (! $result['valid']) {
                continue;
            }
            $proof = $result['proof'];
            foreach ($result['owners'] ?? [] as $owner) {
                if ($owner['uniqueness_valid_until'] !== null && CarbonImmutable::parse($owner['uniqueness_valid_until'])->lessThanOrEqualTo($at)) {
                    throw new AccessDeniedHttpException;
                }
            }
            $ownerMemberships = array_values(array_map(fn ($owner) => $owner['membership'], $result['owners'] ?? []));
            $visibleSubjects = array_filter($result['department_subjects'] ?? [], fn ($subject) => $subject['visibility'] === 'visible');
            foreach ($visibleSubjects as $subject) {
                if ($subject['uniqueness_valid_until'] !== null && CarbonImmutable::parse($subject['uniqueness_valid_until'])->lessThanOrEqualTo($at)) {
                    throw new AccessDeniedHttpException;
                }
            }
            $subjectMemberships = array_values(array_map(fn ($subject) => $subject['membership'], $visibleSubjects));
            foreach (array_filter([$proof['membership'], $proof['department_membership'], ...$proof['grants'], ...$ownerMemberships, ...$subjectMemberships]) as $row) {
                if (CarbonImmutable::parse($row['valid_from'])->greaterThan($at) || ($row['valid_to'] !== null && CarbonImmutable::parse($row['valid_to'])->lessThanOrEqualTo($at))) {
                    throw new AccessDeniedHttpException;
                }
            }
        }
    }

    private function fields(object $row, array $keys): array
    {
        return array_intersect_key((array) $row, array_flip($keys));
    }

    private function candidates(string $table, array $exactIds, array $points, string $condition, array $scope = [], array $additionalLockIds = []): array
    {
        // LIMIT is applied per temporal graph point before PHP materialization or row locking.
        // An overflow point contributes no candidates; exact original pins are fetched independently.
        $ids = array_values(array_unique(array_filter($exactIds, fn ($id) => is_string($id) && Str::isUuid($id))));
        $overflow = [];
        if ($points) {
            $rows = DB::select("SELECT p.key,r.id FROM jsonb_to_recordset(?::jsonb) AS p(key text,employee_id uuid,project_id uuid,department_id uuid,identity_id uuid,user_id uuid,purpose text,at timestamptz) CROSS JOIN LATERAL (SELECT t.id FROM {$table} t WHERE {$condition} AND t.valid_from<=p.at AND (t.valid_to IS NULL OR t.valid_to>p.at) ORDER BY t.id LIMIT 101) r", [json_encode(array_values($points), JSON_THROW_ON_ERROR)]);
            foreach (collect($rows)->groupBy('key') as $key => $group) {
                if ($group->count() > 100) {
                    $overflow[] = (string) $key;
                } else {
                    $ids = [...$ids, ...$group->pluck('id')->all()];
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        $authorityIds = $ids;
        $ids = array_values(array_unique([...$ids, ...$additionalLockIds]));
        sort($ids, SORT_STRING);

        return [$ids ? DB::table($table)->where($scope)->whereIn('id', $ids)->orderBy('id')->sharedLock()->get() : collect(), $overflow, $authorityIds];
    }
}
