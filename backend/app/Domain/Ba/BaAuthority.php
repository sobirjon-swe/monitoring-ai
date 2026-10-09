<?php

namespace App\Domain\Ba;

use App\Domain\Access\TrustedActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class BaAuthority
{
    public const PURPOSES = ['ba.fixture.manage', 'ba.request.manage', 'ba.answer.record', 'ba.decision.confirm', 'ba.read', 'ba.source.read', 'ba.department.read'];

    public function captureBatch(TrustedActor $actor, string $project, array $requests, ?string $department = null, array $subjectRefs = []): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.tenant_id',true),'')") !== $actor->tenantId || DB::scalar("select nullif(current_setting('app.ba_authority_closed',true),'')") !== null) {
            throw new LogicException('BA authority requires an open coordination phase.');
        }
        if (! Str::isUuid($project) || ($department !== null && ! Str::isUuid($department)) || count($requests) > 2302 || count($subjectRefs) > 1350) {
            throw new AccessDeniedHttpException;
        }
        $keys = $reserved = $originalActors = [];
        $sourceReservation = false;
        foreach ($requests as $r) {
            if (array_diff(array_keys($r), ['key', 'actor_id', 'purposes', 'required', 'pinned_proof', 'source_ids', 'owner_refs', 'coordinator_refs', 'request_subject_refs', 'department_refs', 'responsibility_refs', 'root_refs', 'source_head_refs']) || ! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $r['key'] ?? '') || isset($keys[$r['key']]) || ! Str::isUuid($r['actor_id'] ?? '') || ! is_bool($r['required'] ?? null) || ! is_array($r['purposes'] ?? null) || ! array_is_list($r['purposes']) || ! $r['purposes'] || array_diff($r['purposes'], self::PURPOSES)) {
                throw new AccessDeniedHttpException;
            }
            $keys[$r['key']] = true;
            if ($r['key'] === 'current' && ($r['actor_id'] !== $actor->id || ! $r['required'] || isset($r['pinned_proof']))) {
                throw new AccessDeniedHttpException;
            }
            if ($r['key'] === 'current') {
                $reserved['current'] = true;
            }
            if ($r['key'] === 'sources') {
                $sourceReservation = $r['actor_id'] === $actor->id && $r['required'] && ! isset($r['pinned_proof']) && $r['purposes'] === ['ba.source.read'] && ! empty($r['source_ids']) && empty($r['owner_refs']) && empty($r['coordinator_refs']) && empty($r['request_subject_refs']) && empty($r['department_refs']) && empty($r['responsibility_refs']);
            }
            foreach (['source_ids' => 200, 'owner_refs' => 1350, 'coordinator_refs' => 1350, 'request_subject_refs' => 1350, 'department_refs' => 1350, 'root_refs' => 50, 'source_head_refs' => 200] as $field => $limit) {
                $this->ids($r[$field] ?? [], $limit);
            }
            if (isset($r['department_refs']) && ($department === null || ! in_array('ba.department.read', $r['purposes'], true))) {
                throw new AccessDeniedHttpException;
            }
            if (! is_array($r['responsibility_refs'] ?? []) || ! array_is_list($r['responsibility_refs'] ?? []) || count($r['responsibility_refs'] ?? []) > 1000) {
                throw new AccessDeniedHttpException;
            }
            foreach ($r['responsibility_refs'] ?? [] as $ref) {
                if (! is_array($ref) || array_diff(array_keys($ref), ['task_version_id', 'occurred_at']) || ! Str::isUuid($ref['task_version_id'] ?? '') || ! is_string($ref['occurred_at'] ?? null)) {
                    throw new AccessDeniedHttpException;
                }
            }
        }
        if (isset($reserved['current']) && $sourceReservation) {
            $reserved['sources'] = true;
        }
        // Typed current/source authority is reserved independently of originals.
        // A key label alone never reduces the original proof or actor counts.
        if (count($requests) - count($reserved) > 2300) {
            throw new AccessDeniedHttpException;
        }
        foreach ($requests as $r) {
            if (! isset($reserved[$r['key']])) {
                $originalActors[$r['actor_id']] = true;
            }
        }
        $this->ids($subjectRefs, 1350);
        $results = [];
        if (count($originalActors) > 100) {
            $required = [];
            foreach ($requests as $r) {
                if ($r['required']) {
                    $required[] = $r;
                } else {
                    $results[$r['key']] = ['valid' => false, 'required' => false, 'reason_code' => 'coverage_limit'];
                }
            }
            $requiredActors = array_unique(array_column(array_filter($required, fn ($r) => ! isset($reserved[$r['key']])), 'actor_id'));
            if (count($requiredActors) > 100) {
                throw new AccessDeniedHttpException;
            }
            $requests = $required;
        }
        $tenant = DB::table('tenants')->where('id', $actor->tenantId)->sharedLock()->first();
        $actorIds = array_values(array_unique(array_column($requests, 'actor_id')));
        sort($actorIds, SORT_STRING);
        $users = DB::table('users')->whereIn('id', $actorIds)->orderBy('id')->sharedLock()->get()->keyBy('id');
        $at = DB::scalar('select clock_timestamp()');
        $points = $exact = $sourceIds = $taskIds = [];
        $namedIds = $subjectRefs;
        foreach ($requests as $r) {
            $user = $users->get($r['actor_id']);
            $pin = $r['pinned_proof'] ?? null;
            $roles = $this->requiredRoles($r['purposes']);
            if ($pin !== null) {
                foreach (['membership' => 'project_memberships', 'department_membership' => 'department_memberships'] as $key => $table) {
                    if (isset($pin[$key]['id'])) {
                        $exact[$table][] = $pin[$key]['id'];
                    }
                }
                foreach (['roles' => 'role_assignments', 'grants' => 'access_grants'] as $key => $table) {
                    foreach ($pin[$key] ?? [] as $row) {
                        $exact[$table][] = $row['id'] ?? '';
                    }
                }
            } elseif ($user) {
                $points['project_memberships'][$user->employee_id] = ['key' => $user->employee_id, 'employee_id' => $user->employee_id, 'project_id' => $project, 'at' => $at];
                foreach ($roles as $role) {
                    $key = $user->employee_id.':'.$role;
                    $points['role_assignments'][$key] = ['key' => $key, 'employee_id' => $user->employee_id, 'project_id' => $project, 'role' => $role, 'at' => $at];
                }
                foreach ($r['purposes'] as $purpose) {
                    $key = $user->id.':'.$purpose;
                    $points['access_grants'][$key] = ['key' => $key, 'user_id' => $user->id, 'purpose' => $purpose, 'project_id' => $project, 'department_id' => $department, 'at' => $at];
                }
            }
            $sourceIds = [...$sourceIds, ...($r['source_ids'] ?? []), ...array_column($pin['sources'] ?? [], 'id')];
            $namedIds = [...$namedIds, ...($r['owner_refs'] ?? []), ...($r['department_refs'] ?? [])];
            $coordinators = array_values(array_unique([...($r['coordinator_refs'] ?? []), ...array_column($pin['coordinators'] ?? [], 'employee_id')]));
            $this->ids($coordinators, 1350);
            $namedIds = [...$namedIds, ...$coordinators];
            foreach ($coordinators as $id) {
                $saved = collect($pin['coordinators'] ?? [])->firstWhere('employee_id', $id);
                if ($saved) {
                    $exact['project_memberships'][] = $saved['membership']['id'];
                    foreach ($saved['roles'] as $role) {
                        $exact['role_assignments'][] = $role['id'];
                    }
                } else {
                    $points['role_assignments'][$id.':ba'] = ['key' => $id.':ba', 'employee_id' => $id, 'project_id' => $project, 'role' => 'ba', 'at' => $at];
                }
            }
            $subjects = array_values(array_unique([...($r['request_subject_refs'] ?? []), ...array_column($pin['request_subjects'] ?? [], 'employee_id')]));
            $this->ids($subjects, 1350);
            $namedIds = [...$namedIds, ...$subjects];
            foreach ($pin['request_subjects'] ?? [] as $subject) {
                $exact['project_memberships'][] = $subject['membership']['id'];
            }
            foreach ($r['responsibility_refs'] ?? [] as $ref) {
                $taskIds[] = $ref['task_version_id'];
            }
        }
        $tasks = DB::table('task_versions as v')->join('lifecycle_tasks as h', fn ($q) => $q->on('h.id', '=', 'v.task_id')->on('h.project_id', '=', 'v.project_id'))
            ->leftJoin('task_versions as n', fn ($q) => $q->on('n.task_id', '=', 'v.task_id')->on('n.project_id', '=', 'v.project_id')->whereRaw('n.revision=v.revision+1'))
            ->where('v.project_id', $project)->whereIn('v.id', array_unique($taskIds))->selectRaw('v.*, n.effective_at as effective_until, ((h.current_version_id=v.id AND n.id IS NULL) OR (h.revision>v.revision AND n.id IS NOT NULL AND n.effective_at>=v.effective_at)) as interval_known')->get()->keyBy('id');
        $namedIds = array_values(array_unique([...$namedIds, ...$tasks->pluck('assignee_id')->all()]));
        if (count($namedIds) > 1350 || count(array_unique($sourceIds)) > 200 || count(array_unique($taskIds)) > 1000) {
            throw new AccessDeniedHttpException;
        }
        foreach ($namedIds as $id) {
            $points['project_memberships'][$id] = ['key' => $id, 'employee_id' => $id, 'project_id' => $project, 'at' => $at];
            if ($department !== null) {
                $points['department_memberships'][$id] = ['key' => $id, 'employee_id' => $id, 'department_id' => $department, 'at' => $at];
            }
        }
        foreach ($requests as $r) {
            $user = $users->get($r['actor_id']);
            if ($user && in_array('ba.department.read', $r['purposes'], true)) {
                $points['department_memberships'][$user->employee_id] = ['key' => $user->employee_id, 'employee_id' => $user->employee_id, 'department_id' => $department, 'at' => $at];
            }
            foreach ($r['responsibility_refs'] ?? [] as $ref) {
                if ($task = $tasks->get($ref['task_version_id'])) {
                    $key = hash('sha256', BaContract::canonical($ref));
                    $point = ['key' => $key, 'employee_id' => $task->assignee_id, 'project_id' => $project, 'at' => $ref['occurred_at']];
                    $points['project_memberships'][$key] = $point;
                    $points['role_assignments'][$key] = $point;
                }
            }
        }
        $conditions = ['project_memberships' => 't.employee_id=p.employee_id AND t.project_id=p.project_id', 'department_memberships' => 't.employee_id=p.employee_id AND t.department_id=p.department_id', 'role_assignments' => 't.employee_id=p.employee_id AND t.project_id=p.project_id AND (p.role IS NULL OR t.role=p.role)', 'access_grants' => "t.user_id=p.user_id AND t.purpose=p.purpose AND t.revoked_at IS NULL AND ((p.purpose='ba.department.read' AND t.scope_type='department' AND t.department_id=p.department_id) OR (p.purpose<>'ba.department.read' AND t.scope_type='project' AND t.project_id=p.project_id))"];
        $rows = $overflow = $future = [];
        foreach ($conditions as $table => $condition) {
            [$rows[$table], $overflow[$table], $future[$table]] = $this->candidates($table, $exact[$table] ?? [], array_values($points[$table] ?? []), $condition);
        }
        $policies = DB::table('policy_versions')->whereIn('id', $rows['access_grants']->pluck('policy_version_id')->unique()->all())->orderBy('id')->sharedLock()->get()->keyBy('id');
        $sources = DB::table('source_instances')->whereIn('id', array_unique($sourceIds))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $connectors = DB::table('ingestion_connectors')->where('project_id', $project)->whereIn('source_instance_id', array_unique($sourceIds))->orderBy('id')->sharedLock()->get()->keyBy('source_instance_id');
        $employeeIds = array_values(array_unique([...$users->pluck('employee_id')->filter()->all(), ...$namedIds]));
        sort($employeeIds, SORT_STRING);
        $employees = DB::table('employees')->whereIn('id', $employeeIds)->orderBy('id')->sharedLock()->get()->keyBy('id');
        $instant = DB::scalar('select clock_timestamp()');
        $effective = fn ($row, $time = null) => $this->effective((array) $row, $time ?? $instant);
        foreach ($requests as $r) {
            $sourceInvalid = false;
            try {
                $user = $users->get($r['actor_id']);
                $pin = $r['pinned_proof'] ?? null;
                if (! $tenant || $tenant->status !== 'active' || ! $user || $user->status !== 'active' || ! $employees->has($user->employee_id)) {
                    throw new AccessDeniedHttpException;
                }
                $purposes = array_values(array_unique($r['purposes']));
                sort($purposes, SORT_STRING);
                $pick = function ($table, $predicate, ?string $id, string $pointKey) use ($rows, $overflow, $effective) {
                    if ($id === null && in_array($pointKey, $overflow[$table], true)) {
                        throw new \OverflowException;
                    }
                    $candidates = $rows[$table]->filter(fn ($row) => $predicate($row) && $effective($row));
                    if ($id !== null) {
                        $candidates = $candidates->where('id', $id);
                    }
                    if ($candidates->count() !== 1) {
                        throw new AccessDeniedHttpException;
                    }

                    return $candidates->first();
                };
                $member = $pick('project_memberships', fn ($m) => $m->employee_id === $user->employee_id && $m->project_id === $project, $pin['membership']['id'] ?? null, $user->employee_id);
                $proof = ['version' => 'ba-authority-v1', 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'actor_id' => $user->id, 'employee_id' => $user->employee_id, 'purposes' => $purposes, 'membership' => $this->fields($member), 'roles' => [], 'grants' => [], 'department_id' => null, 'department_membership' => null, 'sources' => [], 'captured_at' => $instant];
                foreach ($this->requiredRoles($purposes) as $role) {
                    $original = collect($pin['roles'] ?? [])->firstWhere('role', $role);
                    $row = $pick('role_assignments', fn ($m) => $m->employee_id === $user->employee_id && $m->project_id === $project && $m->role === $role, $original['id'] ?? null, $user->employee_id.':'.$role);
                    $proof['roles'][] = $this->fields($row);
                }
                foreach ($purposes as $purpose) {
                    $original = collect($pin['grants'] ?? [])->firstWhere('purpose', $purpose);
                    $row = $pick('access_grants', fn ($g) => $g->user_id === $user->id && $g->purpose === $purpose && $g->revoked_at === null && $policies->get($g->policy_version_id)?->status === 'active' && ($purpose === 'ba.department.read' ? ($department !== null && $g->scope_type === 'department' && $g->department_id === $department) : ($g->scope_type === 'project' && $g->project_id === $project)), $original['id'] ?? null, $user->id.':'.$purpose);
                    $proof['grants'][] = $this->fields($row) + ['policy_version' => $policies->get($row->policy_version_id)->version];
                }
                if (in_array('ba.department.read', $purposes, true)) {
                    $dm = $pick('department_memberships', fn ($m) => $m->employee_id === $user->employee_id && $m->department_id === $department, $pin['department_membership']['id'] ?? null, $user->employee_id);
                    $proof['department_id'] = $department;
                    $proof['department_membership'] = $this->fields($dm);
                }
                $wantedSources = array_values(array_unique([...($r['source_ids'] ?? []), ...array_column($pin['sources'] ?? [], 'id')]));
                sort($wantedSources, SORT_STRING);
                foreach ($wantedSources as $id) {
                    $source = $sources->get($id);
                    $connector = $connectors->get($id);
                    if (! in_array('ba.source.read', $purposes, true) || ! $source || $source->kind !== 'manual' || ! $connector || $connector->status !== 'active' || $connector->adapter !== 'synthetic-v1') {
                        $sourceInvalid = in_array('ba.source.read', $purposes, true);
                        throw new AccessDeniedHttpException;
                    }
                    $proof['sources'][] = ['id' => $source->id, 'kind' => $source->kind, 'connector_id' => $connector->id, 'connector_revision' => $connector->revision, 'adapter' => $connector->adapter, 'status' => $connector->status];
                }
                $coordinatorIds = array_values(array_unique([...($r['coordinator_refs'] ?? []), ...array_column($pin['coordinators'] ?? [], 'employee_id')]));
                sort($coordinatorIds, SORT_STRING);
                $coordinatorLimits = [];
                foreach ($coordinatorIds as $id) {
                    if (! in_array('ba.request.manage', $purposes, true) || ! $employees->has($id)) {
                        throw new AccessDeniedHttpException;
                    }
                    $saved = collect($pin['coordinators'] ?? [])->firstWhere('employee_id', $id);
                    $membership = $pick('project_memberships', fn ($m) => $m->employee_id === $id && $m->project_id === $project, $saved['membership']['id'] ?? null, $id);
                    $role = $pick('role_assignments', fn ($m) => $m->employee_id === $id && $m->project_id === $project && $m->role === 'ba', $saved['roles'][0]['id'] ?? null, $id.':ba');
                    $proof['coordinators'][] = ['employee_id' => $id, 'membership' => $this->fields($membership), 'roles' => [$this->fields($role)]];
                    if ($saved === null) {
                        $coordinatorLimits = [...$coordinatorLimits, $future['project_memberships'][$id] ?? null, $future['role_assignments'][$id.':ba'] ?? null];
                    }
                }
                $subjectIds = array_values(array_unique([...($r['request_subject_refs'] ?? []), ...array_column($pin['request_subjects'] ?? [], 'employee_id')]));
                sort($subjectIds, SORT_STRING);
                $subjectLimits = [];
                foreach ($subjectIds as $id) {
                    if (! in_array('ba.request.manage', $purposes, true) || ! $employees->has($id)) {
                        throw new AccessDeniedHttpException;
                    }
                    $saved = collect($pin['request_subjects'] ?? [])->firstWhere('employee_id', $id);
                    $membership = $pick('project_memberships', fn ($m) => $m->employee_id === $id && $m->project_id === $project, $saved['membership']['id'] ?? null, $id);
                    $proof['request_subjects'][] = ['employee_id' => $id, 'membership' => $this->fields($membership)];
                    if ($saved === null) {
                        $subjectLimits[] = $future['project_memberships'][$id] ?? null;
                    }
                }
                if ($pin !== null) {
                    $compare = $proof;
                    unset($compare['captured_at'], $pin['captured_at']);
                    $sourceInvalid = BaContract::canonical($compare['sources']) !== BaContract::canonical($pin['sources'] ?? []);
                    if (BaContract::canonical($compare) !== BaContract::canonical($pin)) {
                        throw new AccessDeniedHttpException;
                    }
                }
                $result = ['valid' => true, 'required' => $r['required'], 'reason_code' => null, 'proof' => $proof, 'owners' => [], 'department_subjects' => [], 'responsibilities' => []];
                if ($coordinatorIds) {
                    $result['coordinator_uniqueness_limits'] = array_values(array_filter($coordinatorLimits));
                }
                if ($subjectIds) {
                    $result['request_subject_uniqueness_limits'] = array_values(array_filter($subjectLimits));
                }
                foreach (array_unique([...($r['owner_refs'] ?? []), ...($r['key'] === 'current' ? $subjectRefs : [])]) as $owner) {
                    if (! $employees->has($owner)) {
                        throw new AccessDeniedHttpException;
                    }
                    $membership = $pick('project_memberships', fn ($m) => $m->employee_id === $owner && $m->project_id === $project, null, $owner);
                    $result['owners'][$owner] = ['employee_id' => $owner, 'membership' => $this->fields($membership), 'uniqueness_valid_until' => $future['project_memberships'][$owner] ?? null];
                }
                foreach ($r['department_refs'] ?? [] as $owner) {
                    $membership = $pick('department_memberships', fn ($m) => $m->employee_id === $owner && $m->department_id === $department, null, $owner);
                    $result['department_subjects'][$owner] = ['employee_id' => $owner, 'membership' => $this->fields($membership), 'uniqueness_valid_until' => $future['department_memberships'][$owner] ?? null];
                }
                foreach ($r['responsibility_refs'] ?? [] as $ref) {
                    $key = hash('sha256', BaContract::canonical($ref));
                    $task = $tasks->get($ref['task_version_id']);
                    $unknown = ['attribution' => 'unknown', 'reason_code' => 'historical_proof_missing', 'employee_id' => null, 'task_version_id' => $ref['task_version_id'], 'occurred_at' => $ref['occurred_at']];
                    if (! $task || ! $task->interval_known || CarbonImmutable::parse($ref['occurred_at'])->greaterThan(CarbonImmutable::parse($instant)) || CarbonImmutable::parse($task->effective_at)->greaterThan(CarbonImmutable::parse($ref['occurred_at'])) || ($task->effective_until !== null && CarbonImmutable::parse($task->effective_until)->lessThanOrEqualTo(CarbonImmutable::parse($ref['occurred_at'])))) {
                        $result['responsibilities'][$key] = $unknown;

                        continue;
                    }
                    if (in_array($key, $overflow['project_memberships'], true) || in_array($key, $overflow['role_assignments'], true)) {
                        $result['responsibilities'][$key] = array_replace($unknown, ['reason_code' => 'coverage_limit']);

                        continue;
                    }
                    $members = $rows['project_memberships']->filter(fn ($m) => $m->employee_id === $task->assignee_id && $effective($m, $ref['occurred_at']));
                    $roles = $rows['role_assignments']->filter(fn ($m) => $m->employee_id === $task->assignee_id && $effective($m, $ref['occurred_at']));
                    $result['responsibilities'][$key] = $members->count() === 1 && $roles->isNotEmpty() ? ['attribution' => 'known', 'reason_code' => null, 'employee_id' => $task->assignee_id, 'task_version_id' => $task->id, 'occurred_at' => $ref['occurred_at'], 'effective_until' => $task->effective_until, 'membership' => $this->fields($members->first()), 'roles' => $roles->map(fn ($row) => $this->fields($row))->values()->all()] : $unknown;
                }
                $results[$r['key']] = $result;
            } catch (AccessDeniedHttpException|\OverflowException $e) {
                if ($r['required']) {
                    throw new AccessDeniedHttpException;
                }
                $results[$r['key']] = ['valid' => false, 'required' => false, 'reason_code' => $e instanceof \OverflowException ? 'coverage_limit' : ($sourceInvalid ? 'source_revoked' : 'access_revoked')];
                if ($sourceInvalid && isset($r['pinned_proof']) && ! ($e instanceof \OverflowException)) {
                    $results[$r['key']]['original_source_proof_hash'] = hash('sha256', BaContract::canonical($r['pinned_proof']));
                }
            }
        }

        return $results;
    }

    public function assertEffective(array $proofs, string $at): void
    {
        foreach ($proofs as $result) {
            // Consumers must promote every delivered dependency before capture.
            if (! $result['valid'] || ! ($result['required'] ?? true)) {
                continue;
            }
            $proof = $result['proof'];
            $rows = array_filter([$proof['membership'], $proof['department_membership'], ...$proof['roles'], ...$proof['grants']]);
            foreach ($proof['coordinators'] ?? [] as $coordinator) {
                $rows = [...$rows, $coordinator['membership'], ...$coordinator['roles']];
            }
            foreach ($proof['request_subjects'] ?? [] as $subject) {
                $rows[] = $subject['membership'];
            }
            foreach ([...($result['coordinator_uniqueness_limits'] ?? []), ...($result['request_subject_uniqueness_limits'] ?? [])] as $limit) {
                if (CarbonImmutable::parse($limit)->lessThanOrEqualTo(CarbonImmutable::parse($at))) {
                    throw new AccessDeniedHttpException;
                }
            }
            foreach ([...($result['owners'] ?? []), ...($result['department_subjects'] ?? [])] as $owner) {
                $rows[] = $owner['membership'];
                if ($owner['uniqueness_valid_until'] !== null && CarbonImmutable::parse($owner['uniqueness_valid_until'])->lessThanOrEqualTo(CarbonImmutable::parse($at))) {
                    throw new AccessDeniedHttpException;
                }
            }
            foreach ($rows as $row) {
                if (! $this->effective($row, $at)) {
                    throw new AccessDeniedHttpException;
                }
            }
        }
    }

    public function requireDeliveryLocked(array $keys): void
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.ba_authority_closed',true),'')") !== '1' || ! array_is_list($keys)) {
            throw new LogicException('Delivery selection requires closed BA authority.');
        }
        $proofs = json_decode(DB::scalar("select current_setting('app.ba_proofs')"), true, 64, JSON_THROW_ON_ERROR);
        $required = json_decode(DB::scalar("select current_setting('app.ba_required_keys')"), true, 8, JSON_THROW_ON_ERROR);
        foreach ($keys as $key) {
            if (! is_string($key) || ! ($proofs[$key]['valid'] ?? false)) {
                throw new AccessDeniedHttpException;
            }
            $required[] = $key;
        }
        $required = array_values(array_unique($required));
        if (count($required) > 2302) {
            throw new AccessDeniedHttpException;
        }
        // This only selects already captured proofs; it performs no authority relock.
        DB::select("select set_config('app.ba_required_keys',?,true)", [BaContract::canonical($required)]);
    }

    private function requiredRoles(array $purposes): array
    {
        $roles = [];
        foreach ($purposes as $purpose) {
            $role = match ($purpose) {
                'ba.request.manage', 'ba.answer.record' => 'ba',
                'ba.decision.confirm' => 'pm',
                'ba.department.read' => 'department_head',
                default => null,
            };
            if ($role !== null) {
                $roles[$role] = $role;
            }
        }
        sort($roles, SORT_STRING);

        return $roles;
    }

    private function fields(object $row): array
    {
        return array_intersect_key((array) $row, array_flip(['id', 'revision', 'employee_id', 'user_id', 'role', 'policy_version_id', 'purpose', 'scope_type', 'project_id', 'department_id', 'valid_from', 'valid_to']));
    }

    private function effective(array $row, string $at): bool
    {
        return CarbonImmutable::parse($row['valid_from'])->lessThanOrEqualTo(CarbonImmutable::parse($at)) && ($row['valid_to'] === null || CarbonImmutable::parse($row['valid_to'])->greaterThan(CarbonImmutable::parse($at)));
    }

    private function ids(array $ids, int $limit): void
    {
        if (! array_is_list($ids) || count($ids) > $limit || count($ids) !== count(array_unique($ids))) {
            throw new AccessDeniedHttpException;
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new AccessDeniedHttpException;
            }
        }
    }

    private function candidates(string $table, array $exactIds, array $points, string $condition): array
    {
        $ids = array_values(array_unique(array_filter($exactIds, fn ($id) => is_string($id) && Str::isUuid($id))));
        $overflow = $horizons = [];
        if ($points) {
            $pointsJson = json_encode($points, JSON_THROW_ON_ERROR);
            $record = 'p(key text,employee_id uuid,project_id uuid,department_id uuid,user_id uuid,purpose text,role text,at timestamptz)';
            $probes = DB::select("SELECT p.key,r.id FROM jsonb_to_recordset(?::jsonb) AS $record CROSS JOIN LATERAL (SELECT t.id FROM $table t WHERE $condition AND t.valid_from<=p.at AND (t.valid_to IS NULL OR t.valid_to>p.at) ORDER BY t.id LIMIT 101) r", [$pointsJson]);
            foreach (collect($probes)->groupBy('key') as $key => $group) {
                if ($group->count() > 100) {
                    $overflow[] = (string) $key;
                } else {
                    $ids = [...$ids, ...$group->pluck('id')->all()];
                }
            }
            $future = DB::select("SELECT p.key,r.id,r.valid_from FROM jsonb_to_recordset(?::jsonb) AS $record CROSS JOIN LATERAL (SELECT t.id,t.valid_from FROM $table t WHERE $condition AND t.valid_from>p.at ORDER BY t.valid_from,t.id LIMIT 1) r", [$pointsJson]);
            foreach ($future as $row) {
                $ids[] = $row->id;
                $horizons[$row->key] = $row->valid_from;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        $rows = DB::table($table)->whereIn('id', $ids)->orderBy('id')->sharedLock()->get();

        return [$rows, $overflow, $horizons];
    }
}
