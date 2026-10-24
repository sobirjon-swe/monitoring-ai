<?php

namespace App\Domain\Management;

use App\Domain\Access\DatabaseAccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\Ba\BaAuthority;
use App\Domain\Qa\QaAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ManagementAuthority
{
    public const PURPOSES = DatabaseAccessGateway::PURPOSES;

    /**
     * @param  list<array{key:string,format?:string,actor_id:string,purposes:list<string>,required:bool,pinned_proof?:array,source_ids?:list<string>,owner_refs?:list<string>,department_refs?:list<string>}>  $requests  Trusted server-built requests only.
     * @param  list<array{key:string,employee_id:string}>  $namedSlots  Charged before deduplication.
     * @param  list<array{key:string,task_version_id:string,occurred_at:string}>  $responsibilitySlots
     */
    public function captureBatch(TrustedActor $actor, string $project, array $requests, ?string $department = null, array $namedSlots = [], array $responsibilitySlots = []): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.tenant_id',true),'')") !== $actor->tenantId || DB::scalar("select nullif(current_setting('app.management_authority_closed',true),'')") !== null) {
            throw new LogicException('Management authority requires an open coordination phase.');
        }
        if (! array_is_list($requests) || count($requests) > 2902 || count($namedSlots) > 2000 || count($responsibilitySlots) > 1000) {
            throw new AccessDeniedHttpException;
        }
        $groups = ['management-authority-v1' => [], 'qa-authority-v1' => [], 'ba-authority-v1' => []];
        $actors = $keys = [];
        $managementOriginals = $currentReservations = 0;
        $namedCharge = count($namedSlots);
        $responsibilityCharge = count($responsibilitySlots);
        foreach ($requests as $r) {
            if (! is_array($r)) {
                throw new AccessDeniedHttpException;
            }
            $format = $r['format'] ?? 'management-authority-v1';
            if (! isset($groups[$format]) || ! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $r['key'] ?? '') || isset($keys[$r['key']]) || ! Str::isUuid($r['actor_id'] ?? '') || ! is_bool($r['required'] ?? null) || ! is_array($r['purposes'] ?? null) || ! array_is_list($r['purposes']) || ! $r['purposes']) {
                throw new AccessDeniedHttpException;
            }
            $keys[$r['key']] = true;
            $reserved = ($r['key'] === 'current' || str_starts_with($r['key'], 'current:')) && $format === 'management-authority-v1' && $r['actor_id'] === $actor->id && $r['required'] && ! isset($r['pinned_proof']);
            if ($reserved) {
                $currentReservations++;
            } else {
                $actors[$r['actor_id']] = true;
                if ($format === 'management-authority-v1') {
                    $managementOriginals++;
                }
            }
            $namedCharge += count($r['owner_refs'] ?? []) + count($r['department_refs'] ?? []) + count($r['pinned_proof']['named'] ?? []) + count($r['pinned_proof']['coordinators'] ?? []) + count($r['pinned_proof']['request_subjects'] ?? []);
            $responsibilityCharge += count($r['responsibility_refs'] ?? []);
            if ($format !== 'management-authority-v1' && (in_array($r['key'], ['current', 'sources'], true) || ! is_array($r['pinned_proof'] ?? null) || ($r['pinned_proof']['version'] ?? '') !== $format || isset($r['source_ids'], $r['responsibility_refs']))) {
                throw new AccessDeniedHttpException;
            }
            unset($r['format']);
            $groups[$format][] = $r;
        }
        if (count($actors) > 100 || $managementOriginals > 400 || $currentReservations > 202 || $namedCharge > 2000 || $responsibilityCharge > 1000 || count($groups['qa-authority-v1']) + count($groups['ba-authority-v1']) > 2300) {
            throw new AccessDeniedHttpException;
        }
        $subjects = [];
        foreach ($namedSlots as $slot) {
            if (array_diff(array_keys($slot), ['key', 'employee_id']) || ! Str::isUuid($slot['employee_id'] ?? '') || ! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $slot['key'] ?? '')) {
                throw new AccessDeniedHttpException;
            }
            $subjects[] = $slot['employee_id'];
        }
        foreach ($responsibilitySlots as $slot) {
            if (array_diff(array_keys($slot), ['key', 'task_version_id', 'occurred_at']) || ! Str::isUuid($slot['task_version_id'] ?? '') || ! is_string($slot['occurred_at'] ?? null)) {
                throw new AccessDeniedHttpException;
            }
        }
        // The project lock serializes heads. Prelock the union of original rows in
        // one typed table order before invoking any format-specific read validator.
        $this->prelockOriginals($actor, $project, $requests, $subjects, $responsibilitySlots);
        foreach ($groups['management-authority-v1'] as &$r) {
            if ($r['key'] === 'current' && $responsibilitySlots) {
                $r['responsibility_refs'] = array_map(fn ($s) => array_intersect_key($s, array_flip(['task_version_id', 'occurred_at'])), $responsibilitySlots);
            }
        }
        unset($r);
        $results = $this->captureManagement($actor, $project, $groups['management-authority-v1'], $department, array_values(array_unique($subjects)));
        // These APIs reconstruct one department scope per batch. Separate exact
        // original scopes after the union prelock; caller scope never repairs them.
        foreach (['qa-authority-v1', 'ba-authority-v1'] as $format) {
            $scopes = [];
            foreach ($groups[$format] as $request) {
                $scope = $request['pinned_proof']['department_id'] ?? null;
                if ($scope !== null && ! Str::isUuid($scope)) {
                    throw new AccessDeniedHttpException;
                }
                $scopes[$scope ?? ''][] = $request;
            }
            ksort($scopes, SORT_STRING);
            foreach ($scopes as $scope => $requests) {
                if ($format === 'qa-authority-v1') {
                    $previous = DB::scalar("select current_setting('app.qa_responsibility',true)");
                    $captured = app(QaAuthority::class)->captureBatch($actor, $project, $requests, $scope === '' ? null : $scope);
                    DB::select("select set_config('app.qa_responsibility',?,true)", [$previous ?? '']);
                } else {
                    $captured = app(BaAuthority::class)->captureBatch($actor, $project, $requests, $scope === '' ? null : $scope);
                }
                foreach ($requests as $request) {
                    $results[$request['key']] = $captured[$request['key']] + ['required' => $request['required']];
                }
            }
        }

        return $results;
    }

    private function prelockOriginals(TrustedActor $actor, string $project, array $requests, array $subjects, array $responsibilitySlots): void
    {
        DB::table('tenants')->where('id', $actor->tenantId)->sharedLock()->first();
        $actorIds = array_values(array_unique(array_column($requests, 'actor_id')));
        sort($actorIds, SORT_STRING);
        $users = DB::table('users')->whereIn('id', $actorIds)->orderBy('id')->sharedLock()->get();
        $employees = [...$subjects, ...$users->pluck('employee_id')->filter()->all()];
        $exact = $sources = [];
        foreach ($requests as $r) {
            $pin = $r['pinned_proof'] ?? [];
            foreach (['membership' => 'project_memberships', 'department_membership' => 'department_memberships'] as $field => $table) {
                if (isset($pin[$field]['id'])) {
                    $exact[$table][] = $pin[$field]['id'];
                }
            }
            foreach (['roles' => 'role_assignments', 'grants' => 'access_grants'] as $field => $table) {
                foreach ($pin[$field] ?? [] as $row) {
                    $exact[$table][] = $row['id'];
                }
            }
            foreach (['coordinators', 'request_subjects', 'named'] as $field) {
                foreach ($pin[$field] ?? [] as $named) {
                    $employees[] = $named['employee_id'];
                    $exact['project_memberships'][] = $named['membership']['id'];
                    if (isset($named['department_membership']['id'])) {
                        $exact['department_memberships'][] = $named['department_membership']['id'];
                    }
                    foreach ($named['roles'] ?? [] as $role) {
                        $exact['role_assignments'][] = $role['id'];
                    }
                }
            }
            $sources = [...$sources, ...($r['source_ids'] ?? []), ...array_column($pin['sources'] ?? [], 'id')];
        }
        if (count(array_unique($sources)) > 200 || count(array_unique($employees)) > 2100) {
            throw new AccessDeniedHttpException;
        }
        // Current candidate locks are batched by bounded graph points in the
        // management collector; source originals add only their exact saved IDs.
        foreach (['project_memberships', 'department_memberships', 'role_assignments', 'access_grants'] as $table) {
            $ids = array_values(array_unique($exact[$table] ?? []));
            sort($ids, SORT_STRING);
            DB::table($table)->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id']);
        }
        $policyIds = DB::table('access_grants')->whereIn('id', $exact['access_grants'] ?? [])->pluck('policy_version_id')->unique()->all();
        DB::table('policy_versions')->whereIn('id', $policyIds)->orderBy('id')->sharedLock()->get(['id']);
        DB::table('source_instances')->whereIn('id', array_unique($sources))->orderBy('id')->sharedLock()->get(['id']);
        DB::table('ingestion_connectors')->where('project_id', $project)->whereIn('source_instance_id', array_unique($sources))->orderBy('id')->sharedLock()->get(['id']);
        DB::table('employees')->whereIn('id', array_unique($employees))->orderBy('id')->sharedLock()->get(['id']);
    }

    private function captureManagement(TrustedActor $actor, string $project, array $requests, ?string $department = null, array $subjectRefs = []): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.tenant_id',true),'')") !== $actor->tenantId || DB::scalar("select nullif(current_setting('app.management_authority_closed',true),'')") !== null) {
            throw new LogicException('Management authority requires an open coordination phase.');
        }
        if (! Str::isUuid($project) || ($department !== null && ! Str::isUuid($department)) || count($requests) > 2702 || count($subjectRefs) > 2000) {
            throw new AccessDeniedHttpException;
        }
        $keys = $reserved = $originalActors = [];
        $sourceReservation = false;
        foreach ($requests as $r) {
            if (array_diff(array_keys($r), ['key', 'actor_id', 'purposes', 'required', 'pinned_proof', 'source_ids', 'owner_refs', 'department_refs', 'responsibility_refs', 'root_refs', 'source_head_refs']) || ! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $r['key'] ?? '') || isset($keys[$r['key']]) || ! Str::isUuid($r['actor_id'] ?? '') || ! is_bool($r['required'] ?? null) || ! is_array($r['purposes'] ?? null) || ! array_is_list($r['purposes']) || ! $r['purposes'] || array_diff($r['purposes'], self::PURPOSES)) {
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
                $sourceReservation = $r['actor_id'] === $actor->id && $r['required'] && ! isset($r['pinned_proof']) && $r['purposes'] === ['management.source.read'] && ! empty($r['source_ids']) && empty($r['owner_refs']) && empty($r['department_refs']) && empty($r['responsibility_refs']);
            }
            foreach (['source_ids' => 200, 'owner_refs' => 2000, 'department_refs' => 2000, 'root_refs' => 50, 'source_head_refs' => 200] as $field => $limit) {
                $this->ids($r[$field] ?? [], $limit);
            }
            if (isset($r['department_refs']) && ($department === null || ! $this->departmentPurpose($r['purposes']))) {
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
        if (count($requests) - count($reserved) > 2700) {
            throw new AccessDeniedHttpException;
        }
        foreach ($requests as $r) {
            if (! isset($reserved[$r['key']]) && ! str_starts_with($r['key'], 'current:')) {
                $originalActors[$r['actor_id']] = true;
            }
        }
        $this->ids($subjectRefs, 2000);
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
                    if ($role === 'recording') {
                        $points['role_assignments'][$user->employee_id.':recording'] = ['key' => $user->employee_id.':recording', 'employee_id' => $user->employee_id, 'project_id' => $project, 'at' => $at];

                        continue;
                    }
                    $key = $user->employee_id.':'.$role;
                    $points['role_assignments'][$key] = ['key' => $key, 'employee_id' => $user->employee_id, 'project_id' => $project, 'role' => $role, 'at' => $at];
                }
                foreach ($r['purposes'] as $purpose) {
                    $key = $user->id.':'.$purpose;
                    $points['access_grants'][$key] = ['key' => $key, 'user_id' => $user->id, 'purpose' => $purpose, 'project_id' => $project, 'department_id' => $department, 'at' => $at];
                }
            }
            $sourceIds = [...$sourceIds, ...($r['source_ids'] ?? []), ...array_column($pin['sources'] ?? [], 'id')];
            foreach ($pin['named'] ?? [] as $named) {
                $namedIds[] = $named['employee_id'];
                $exact['project_memberships'][] = $named['membership']['id'];
                if (isset($named['department_membership']['id'])) {
                    $exact['department_memberships'][] = $named['department_membership']['id'];
                }
            }
            $namedIds = [...$namedIds, ...($r['owner_refs'] ?? []), ...($r['department_refs'] ?? [])];
            foreach ($r['responsibility_refs'] ?? [] as $ref) {
                $taskIds[] = $ref['task_version_id'];
            }
        }
        $tasks = DB::table('task_versions as v')->join('lifecycle_tasks as h', fn ($q) => $q->on('h.id', '=', 'v.task_id')->on('h.project_id', '=', 'v.project_id'))
            ->leftJoin('task_versions as n', fn ($q) => $q->on('n.task_id', '=', 'v.task_id')->on('n.project_id', '=', 'v.project_id')->whereRaw('n.revision=v.revision+1'))
            ->where('v.project_id', $project)->whereIn('v.id', array_unique($taskIds))->selectRaw('v.*, n.effective_at as effective_until, ((h.current_version_id=v.id AND n.id IS NULL) OR (h.revision>v.revision AND n.id IS NOT NULL AND n.effective_at>=v.effective_at)) as interval_known')->get()->keyBy('id');
        $namedIds = array_values(array_unique([...$namedIds, ...$tasks->pluck('assignee_id')->all()]));
        if (count($namedIds) > 2000 || count(array_unique($sourceIds)) > 200 || count(array_unique($taskIds)) > 1000) {
            throw new AccessDeniedHttpException;
        }
        foreach ($namedIds as $id) {
            $points['project_memberships'][$id] = ['key' => $id, 'employee_id' => $id, 'project_id' => $project, 'at' => $at];
            if ($department !== null) {
                $scopeKey = $id.':'.$department;
                $points['department_memberships'][$scopeKey] = ['key' => $scopeKey, 'employee_id' => $id, 'department_id' => $department, 'at' => $at];
            }
        }
        foreach ($requests as $r) {
            $user = $users->get($r['actor_id']);
            if ($user && ! isset($r['pinned_proof']) && $this->departmentPurpose($r['purposes'])) {
                $scopeKey = $user->employee_id.':'.$department;
                $points['department_memberships'][$scopeKey] = ['key' => $scopeKey, 'employee_id' => $user->employee_id, 'department_id' => $department, 'at' => $at];
            }
            foreach ($r['responsibility_refs'] ?? [] as $ref) {
                if ($task = $tasks->get($ref['task_version_id'])) {
                    $key = hash('sha256', ManagementContract::canonical($ref));
                    $point = ['key' => $key, 'employee_id' => $task->assignee_id, 'project_id' => $project, 'at' => $ref['occurred_at']];
                    $points['project_memberships'][$key] = $point;
                    $points['role_assignments'][$key] = $point;
                }
            }
        }
        $conditions = ['project_memberships' => 't.employee_id=p.employee_id AND t.project_id=p.project_id', 'department_memberships' => 't.employee_id=p.employee_id AND t.department_id=p.department_id', 'role_assignments' => 't.employee_id=p.employee_id AND t.project_id=p.project_id AND (p.role IS NULL OR t.role=p.role)', 'access_grants' => "t.user_id=p.user_id AND t.purpose=p.purpose AND t.revoked_at IS NULL AND ((p.purpose IN ('management.department.read','management.department.manage','qa.department.read','ba.department.read') AND t.scope_type='department' AND t.department_id=p.department_id) OR (p.purpose NOT IN ('management.department.read','management.department.manage','qa.department.read','ba.department.read') AND t.scope_type='project' AND t.project_id=p.project_id))"];
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
                $requestDepartment = $pin !== null ? ($pin['department_id'] ?? null) : $department;
                if ($requestDepartment !== null && ! Str::isUuid($requestDepartment)) {
                    throw new AccessDeniedHttpException;
                }
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
                $proof = ['version' => 'management-authority-v1', 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'actor_id' => $user->id, 'employee_id' => $user->employee_id, 'purposes' => $purposes, 'membership' => $this->fields($member), 'roles' => [], 'grants' => [], 'department_id' => null, 'department_membership' => null, 'sources' => [], 'captured_at' => $instant];
                foreach ($this->requiredRoles($purposes) as $role) {
                    $original = collect($pin['roles'] ?? [])->first(fn ($r) => $role === 'recording' ? in_array($r['role'], ['developer', 'qa', 'ba', 'pm', 'department_head'], true) : $r['role'] === $role);
                    if ($role === 'recording' && $pin === null) {
                        $eligible = $rows['role_assignments']->filter(fn ($m) => $m->employee_id === $user->employee_id && $m->project_id === $project && in_array($m->role, ['developer', 'qa', 'ba', 'pm', 'department_head'], true) && $effective($m));
                        if ($eligible->isEmpty()) {
                            throw new AccessDeniedHttpException;
                        }
                        $row = $eligible->sortBy('id')->first();
                    } else {
                        $row = $pick('role_assignments', fn ($m) => $m->employee_id === $user->employee_id && $m->project_id === $project && ($role === 'recording' ? in_array($m->role, ['developer', 'qa', 'ba', 'pm', 'department_head'], true) : $m->role === $role), $original['id'] ?? null, $user->employee_id.':'.$role);
                    }
                    $proof['roles'][] = $this->fields($row);
                }
                foreach ($purposes as $purpose) {
                    $original = collect($pin['grants'] ?? [])->firstWhere('purpose', $purpose);
                    $row = $pick('access_grants', fn ($g) => $g->user_id === $user->id && $g->purpose === $purpose && $g->revoked_at === null && $policies->get($g->policy_version_id)?->status === 'active' && ($this->departmentPurpose([$purpose]) ? ($requestDepartment !== null && $g->scope_type === 'department' && $g->department_id === $requestDepartment) : ($g->scope_type === 'project' && $g->project_id === $project)), $original['id'] ?? null, $user->id.':'.$purpose);
                    $proof['grants'][] = $this->fields($row) + ['policy_version' => $policies->get($row->policy_version_id)->version];
                }
                if ($this->departmentPurpose($purposes)) {
                    $dm = $pick('department_memberships', fn ($m) => $m->employee_id === $user->employee_id && $m->department_id === $requestDepartment, $pin['department_membership']['id'] ?? null, $user->employee_id.':'.$requestDepartment);
                    $proof['department_id'] = $requestDepartment;
                    $proof['department_membership'] = $this->fields($dm);
                }
                $wantedSources = array_values(array_unique([...($r['source_ids'] ?? []), ...array_column($pin['sources'] ?? [], 'id')]));
                sort($wantedSources, SORT_STRING);
                foreach ($wantedSources as $id) {
                    $source = $sources->get($id);
                    $connector = $connectors->get($id);
                    if (! array_intersect(['management.source.read', 'ba.source.read', 'qa.source.read'], $purposes) || ! $source || $source->kind !== 'manual' || ! $connector || $connector->status !== 'active' || $connector->adapter !== 'synthetic-v1') {
                        $sourceInvalid = (bool) array_intersect(['management.source.read', 'ba.source.read', 'qa.source.read'], $purposes);
                        throw new AccessDeniedHttpException;
                    }
                    $proof['sources'][] = ['id' => $source->id, 'kind' => $source->kind, 'connector_id' => $connector->id, 'connector_revision' => $connector->revision, 'adapter' => $connector->adapter, 'status' => $connector->status];
                }
                $result = ['valid' => true, 'required' => $r['required'], 'reason_code' => null, 'proof' => $proof, 'owners' => [], 'department_subjects' => [], 'responsibilities' => []];
                $wantedNamed = array_values(array_unique([...($r['owner_refs'] ?? []), ...array_column($pin['named'] ?? [], 'employee_id'), ...($r['key'] === 'current' ? $subjectRefs : [])]));
                sort($wantedNamed, SORT_STRING);
                $proof['named'] = [];
                foreach ($wantedNamed as $owner) {
                    if (! $employees->has($owner)) {
                        throw new AccessDeniedHttpException;
                    }
                    $savedNamed = collect($pin['named'] ?? [])->firstWhere('employee_id', $owner);
                    $membership = $pick('project_memberships', fn ($m) => $m->employee_id === $owner && $m->project_id === $project, $savedNamed['membership']['id'] ?? null, $owner);
                    $namedDepartment = $pin !== null ? ($savedNamed['department_membership']['department_id'] ?? null) : $department;
                    $proof['named'][] = ['employee_id' => $owner, 'membership' => $this->fields($membership), 'department_membership' => $namedDepartment !== null ? $this->fields($pick('department_memberships', fn ($m) => $m->employee_id === $owner && $m->department_id === $namedDepartment, $savedNamed['department_membership']['id'] ?? null, $owner.':'.$namedDepartment)) : null];
                    $result['owners'][$owner] = ['employee_id' => $owner, 'membership' => $this->fields($membership), 'uniqueness_valid_until' => $future['project_memberships'][$owner] ?? null];
                }
                foreach ($r['department_refs'] ?? [] as $owner) {
                    $membership = $pick('department_memberships', fn ($m) => $m->employee_id === $owner && $m->department_id === $department, null, $owner.':'.$department);
                    $result['department_subjects'][$owner] = ['employee_id' => $owner, 'membership' => $this->fields($membership), 'uniqueness_valid_until' => $future['department_memberships'][$owner] ?? null];
                }
                foreach ($r['responsibility_refs'] ?? [] as $ref) {
                    $key = hash('sha256', ManagementContract::canonical($ref));
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
                if ($pin !== null) {
                    $compare = $proof;
                    unset($compare['captured_at'], $pin['captured_at']);
                    $sourceInvalid = ManagementContract::canonical($compare['sources']) !== ManagementContract::canonical($pin['sources'] ?? []);
                    if (ManagementContract::canonical($compare) !== ManagementContract::canonical($pin)) {
                        throw new AccessDeniedHttpException;
                    }
                }
                $result['proof'] = $proof;
                $results[$r['key']] = $result;
            } catch (AccessDeniedHttpException|\OverflowException $e) {
                if ($r['required']) {
                    throw new AccessDeniedHttpException;
                }
                $results[$r['key']] = ['valid' => false, 'required' => false, 'reason_code' => $e instanceof \OverflowException ? 'coverage_limit' : ($sourceInvalid ? 'source_revoked' : 'access_revoked')];
                if ($sourceInvalid && isset($r['pinned_proof']) && ! ($e instanceof \OverflowException)) {
                    $results[$r['key']]['original_source_proof_hash'] = hash('sha256', ManagementContract::canonical($r['pinned_proof']));
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
            if ($proof['version'] === 'qa-authority-v1') {
                app(QaAuthority::class)->assertEffective([$result], $at);

                continue;
            }
            if ($proof['version'] === 'ba-authority-v1') {
                app(BaAuthority::class)->assertEffective([$result], $at);

                continue;
            }
            $rows = array_filter([$proof['membership'], $proof['department_membership'], ...$proof['roles'], ...$proof['grants']]);
            foreach ($proof['named'] ?? [] as $named) {
                $rows[] = $named['membership'];
                if ($named['department_membership'] !== null) {
                    $rows[] = $named['department_membership'];
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
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.management_authority_closed',true),'')") !== '1' || ! array_is_list($keys)) {
            throw new LogicException('Delivery selection requires closed Management authority.');
        }
        $proofs = json_decode(DB::scalar("select current_setting('app.management_proofs')"), true, 64, JSON_THROW_ON_ERROR);
        $required = json_decode(DB::scalar("select current_setting('app.management_required_keys')"), true, 8, JSON_THROW_ON_ERROR);
        foreach ($keys as $key) {
            if (! is_string($key) || ! ($proofs[$key]['valid'] ?? false)) {
                throw new AccessDeniedHttpException;
            }
            $required[] = $key;
        }
        $required = array_values(array_unique($required));
        if (count($required) > 2902) {
            throw new AccessDeniedHttpException;
        }
        // This only selects already captured proofs; it performs no authority relock.
        DB::select("select set_config('app.management_required_keys',?,true)", [ManagementContract::canonical($required)]);
    }

    private function departmentPurpose(array $purposes): bool
    {
        return (bool) array_intersect($purposes, ['management.department.read', 'management.department.manage', 'qa.department.read', 'ba.department.read']);
    }

    private function requiredRoles(array $purposes): array
    {
        $roles = [];
        if (in_array('management.action.manage', $purposes, true)) {
            $roles[] = 'pm';
        }
        if (in_array('management.support.assign', $purposes, true) || $this->departmentPurpose($purposes)) {
            $roles[] = 'department_head';
        }
        if (in_array('management.outcome.review', $purposes, true)) {
            $roles[] = in_array('management.department.manage', $purposes, true) ? 'department_head' : 'pm';
        }
        if (in_array('management.support.record', $purposes, true)) {
            $roles[] = 'recording';
        }

        return array_values(array_unique($roles));
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
