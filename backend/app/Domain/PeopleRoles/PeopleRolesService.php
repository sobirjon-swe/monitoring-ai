<?php

namespace App\Domain\PeopleRoles;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PeopleRolesService
{
    public function __construct(private readonly TenantContext $context, private readonly AuditWriter $audit) {}

    private function current($query, string $at)
    {
        return $query->where('valid_from', '<=', $at)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $at));
    }

    /** All mutable authorization rows are locked before reading the database wall clock. */
    public function authority(TrustedActor $actor, string $purpose, ?string $project = null, bool $lock = false): string
    {
        $tenant = DB::table('tenants')->where('id', $actor->tenantId);
        $user = DB::table('users')->where('id', $actor->id);
        if ($lock) {
            $tenant->lockForUpdate();
            $user->lockForUpdate();
        }
        $t = $tenant->first();
        $u = $user->first();
        $grants = DB::table('access_grants')->where('user_id', $actor->id)->orderBy('id');
        if ($lock) {
            $grants->lockForUpdate();
        }
        $gs = $grants->get();
        $policies = DB::table('policy_versions')->whereIn('id', $gs->pluck('policy_version_id'))->orderBy('id');
        if ($lock) {
            $policies->lockForUpdate();
        }
        $ps = $policies->get()->keyBy('id');
        if ($project !== null) {
            $pq = DB::table('projects')->where('id', $project);
            $mq = DB::table('project_memberships')->where('project_id', $project)->where('employee_id', $u?->employee_id)->orderBy('id');
            $rq = DB::table('role_assignments')->where('project_id', $project)->where('employee_id', $u?->employee_id)->orderBy('id');
            if ($lock) {
                $pq->lockForUpdate();
                $mq->lockForUpdate();
                $rq->lockForUpdate();
            }
            $p = $pq->first();
            $ms = $mq->get();
            $rs = $rq->get();
        }
        $at = DB::scalar('select clock_timestamp()');
        $effective = static fn ($r) => $r->valid_from <= $at && ($r->valid_to === null || $r->valid_to > $at);
        $has = function (string $wanted, bool $tenantOnly = false, bool $projectOnly = false) use ($gs, $ps, $effective, $project): bool {
            return $gs->contains(fn ($g) => $g->purpose === $wanted && $g->revoked_at === null && $effective($g) && ($ps[$g->policy_version_id]->status ?? null) === 'active' &&
                ((! $projectOnly && $g->scope_type === 'tenant') || (! $tenantOnly && $project !== null && $g->scope_type === 'project' && $g->project_id === $project)));
        };
        if (! $t || $t->status !== 'active' || ! $u || $u->status !== 'active') {
            throw new AccessDeniedHttpException;
        }
        if ($project !== null && (! isset($p) || ! $p || ! $ms->contains($effective))) {
            throw new AccessDeniedHttpException;
        }
        if ($purpose === 'roles.assign') {
            if (! $has('project.read') || ! $has('employees.read', false, true) || ! $has('roles.manage', false, true) || (! $rs->contains(fn ($r) => $r->role === 'pm' && $effective($r)) && ! $has('role_catalog.manage', true))) {
                throw new AccessDeniedHttpException;
            }
        } elseif (! $has($purpose, str_starts_with($purpose, 'role_catalog.'))) {
            throw new AccessDeniedHttpException;
        }

        return $at;
    }

    private function validate(array $input, array $rules): array
    {
        if (array_diff(array_keys($input), array_keys($rules))) {
            throw ValidationException::withMessages(['request' => 'Invalid fields.']);
        }
        $validated = Validator::make($input, $rules)->validate();
        if (array_key_exists('description', $rules)) {
            $validated['description'] ??= '';
        }
        foreach (['expected_revision', 'expected_role_revision'] as $revision) {
            if (array_key_exists($revision, $validated)) {
                $validated[$revision] = (int) $validated[$revision];
            }
        }
        ksort($validated);

        return $validated;
    }

    private function item(object $row): array
    {
        return ['id' => $row->id, 'name' => $row->name, 'description' => $row->description, 'status' => $row->status, 'revision' => (int) $row->revision];
    }

    private function stamp(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.uP');
    }

    private function mutate(TrustedActor $actor, ?string $project, string $operation, array $input, callable $work): array
    {
        return $this->context->run($actor->tenantId, function () use ($actor, $project, $operation, $input, $work) {
            $this->authority($actor, $project === null ? 'role_catalog.manage' : 'roles.assign', $project, true);
            $hash = hash('sha256', json_encode([$operation, $project, $input], JSON_THROW_ON_ERROR));
            $receipt = DB::table('people_role_requests')->where('id', $input['request_id'])->first();
            if ($receipt) {
                if ($receipt->actor_id !== $actor->id || $receipt->project_id !== $project || $receipt->operation !== $operation || ! hash_equals($receipt->request_hash, $hash)) {
                    throw new ConflictHttpException;
                }

                $order = $project === null ? ['id', 'name', 'description', 'status', 'revision'] : ['id', 'employee_id', 'role_catalog_id', 'name', 'revision', 'valid_from', 'valid_to'];

                return ['item' => array_replace(array_fill_keys($order, null), json_decode($receipt->response, true, 512, JSON_THROW_ON_ERROR)), 'created' => false];
            }
            $item = $work();
            DB::table('people_role_requests')->insert(['id' => $input['request_id'], 'tenant_id' => $actor->tenantId, 'actor_id' => $actor->id, 'project_id' => $project, 'operation' => $operation, 'request_hash' => $hash, 'response' => json_encode($item, JSON_THROW_ON_ERROR)]);

            return ['item' => $item, 'created' => true];
        });
    }

    private function cursor(TrustedActor $actor, ?string $project, string $kind, array $filters, ?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $bad = fn () => throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
        if (strlen($cursor) > 1024 || substr_count($cursor, '.') !== 1) {
            $bad();
        }
        [$body,$mac] = explode('.', $cursor);
        $scope = json_encode([$actor->id, $actor->tenantId, $project, $kind, $filters], JSON_THROW_ON_ERROR);
        if (! hash_equals(hash_hmac('sha256', $scope.$body, config('app.key')), $mac)) {
            $bad();
        }
        $id = base64_decode($body, true);
        if (! $id || ! Str::isUuid($id)) {
            $bad();
        }

        return $id;
    }

    private function pageResult(TrustedActor $actor, ?string $project, string $kind, array $filters, $rows, int $count): array
    {
        $items = $rows->take(25)->values()->all();
        $cursor = null;
        if ($rows->count() > 25) {
            $body = base64_encode($items[24]['id']);
            $scope = json_encode([$actor->id, $actor->tenantId, $project, $kind, $filters], JSON_THROW_ON_ERROR);
            $cursor = $body.'.'.hash_hmac('sha256', $scope.$body, config('app.key'));
        }

        return ['items' => $items, 'next_cursor' => $cursor, 'visible_record_count' => $count];
    }

    public function catalogAuthority(TrustedActor $actor, ?string $project): void
    {
        if ($project !== null) {
            $this->authority($actor, 'project.read', $project);
            try {
                $this->authority($actor, 'roles.read', $project);

                return;
            } catch (AccessDeniedHttpException) {
            }
        }
        try {
            $this->authority($actor, 'role_catalog.read');
        } catch (AccessDeniedHttpException) {
            $this->authority($actor, 'role_catalog.manage');
        }
    }

    public function catalog(TrustedActor $actor, ?string $project, array $input): array
    {
        $input = $this->validate($input, ['search' => 'sometimes|nullable|string|max:120', 'status' => 'sometimes|in:active,archived,all', 'cursor' => 'sometimes|nullable|string|max:1024']);
        $filters = ['search' => $input['search'] ?? '', 'status' => $input['status'] ?? 'active'];
        $after = $this->cursor($actor, $project, 'catalog', $filters, $input['cursor'] ?? null);

        return $this->context->run($actor->tenantId, function () use ($actor, $project, $filters, $after) {
            $this->catalogAuthority($actor, $project);
            $query = DB::table('role_catalog');
            if ($filters['status'] !== 'all') {
                $query->where('status', $filters['status']);
            }
            if ($filters['search'] !== '') {
                $query->whereRaw('strpos(lower(name), lower(?)) > 0', [$filters['search']]);
            }
            $count = (clone $query)->count();
            if ($after) {
                $query->where('id', '>', $after);
            }
            $rows = $query->orderBy('id')->limit(26)->get()->map(fn ($r) => $this->item($r));

            return $this->pageResult($actor, $project, 'catalog', $filters, $rows, $count);
        });
    }

    public function employees(TrustedActor $actor, string $project, array $input): array
    {
        $input = $this->validate($input, ['search' => 'sometimes|nullable|string|max:120', 'role_id' => 'sometimes|nullable|uuid', 'cursor' => 'sometimes|nullable|string|max:1024']);
        $filters = ['search' => $input['search'] ?? '', 'role_id' => $input['role_id'] ?? null];
        $after = $this->cursor($actor, $project, 'employees', $filters, $input['cursor'] ?? null);

        return $this->context->run($actor->tenantId, function () use ($actor, $project, $filters, $after) {
            $at = $this->authority($actor, 'employees.read', $project);
            $query = DB::table('employees as e')->whereExists(function ($q) use ($project, $at) {
                $q->selectRaw('1')->from('project_memberships as m')->whereColumn('m.employee_id', 'e.id')->where('m.project_id', $project)->where('m.valid_from', '<=', $at)->where(fn ($q) => $q->whereNull('m.valid_to')->orWhere('m.valid_to', '>', $at));
            });
            if ($filters['search'] !== '') {
                $query->whereRaw('strpos(lower(e.display_name), lower(?)) > 0', [$filters['search']]);
            }
            if ($filters['role_id']) {
                $query->whereExists(function ($q) use ($project, $at, $filters) {
                    $q->selectRaw('1')->from('project_catalog_role_assignments as a')->whereColumn('a.employee_id', 'e.id')->where('a.project_id', $project)->where('a.role_catalog_id', $filters['role_id'])->where('a.valid_from', '<=', $at)->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>', $at));
                });
            }
            $count = (clone $query)->count();
            if ($after) {
                $query->where('e.id', '>', $after);
            }
            $employees = $query->orderBy('e.id')->limit(26)->get(['e.id', 'e.display_name']);
            $ids = $employees->pluck('id');
            $memberships = $this->current(DB::table('project_memberships')->where('project_id', $project)->whereIn('employee_id', $ids), $at)->orderByDesc('valid_from')->get()->groupBy('employee_id');
            $roles = $this->current(DB::table('project_catalog_role_assignments')->where('project_id', $project)->whereIn('employee_id', $ids), $at)->orderBy('id')->get()->groupBy('employee_id');
            $system = $this->current(DB::table('role_assignments')->where('project_id', $project)->whereIn('employee_id', $ids), $at)->orderBy('role')->get()->groupBy('employee_id');
            $rows = $employees->map(function ($e) use ($memberships, $roles, $system) {
                $m = $memberships[$e->id]->first();

                return ['id' => $e->id, 'display_name' => $e->display_name, 'membership' => ['id' => $m->id, 'valid_from' => $this->stamp($m->valid_from), 'valid_to' => $this->stamp($m->valid_to)],
                    'roles' => collect($roles[$e->id] ?? [])->map(fn ($r) => ['id' => $r->id, 'role_catalog_id' => $r->role_catalog_id, 'name' => $r->definition_name, 'revision' => (int) $r->revision, 'valid_from' => $this->stamp($r->valid_from), 'valid_to' => $this->stamp($r->valid_to)])->all(),
                    'system_roles' => collect($system[$e->id] ?? [])->pluck('role')->unique()->values()->all()];
            });

            return $this->pageResult($actor, $project, 'employees', $filters, $rows, $count);
        });
    }

    public function create(TrustedActor $actor, array $input): array
    {
        $input = $this->validate($input, ['name' => 'required|string|max:120', 'description' => 'sometimes|nullable|string|max:2000', 'request_id' => 'required|uuid']);

        return $this->mutate($actor, null, 'catalog.create', $input, function () use ($actor, $input) {
            $id = (string) Str::uuid();
            if (trim($input['name']) === '') {
                throw ValidationException::withMessages(['name' => 'Required.']);
            }
            if (DB::table('role_catalog')->whereRaw('lower(btrim(name)) = lower(?)', [trim($input['name'])])->exists()) {
                throw new ConflictHttpException;
            }
            DB::table('role_catalog')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'key' => (string) Str::uuid(), 'name' => trim($input['name']), 'description' => $input['description']]);
            $this->audit->record($actor->id, ['tenant_id' => $actor->tenantId], 'role_catalog.created', ['type' => 'role_catalog', 'id' => $id], 1, 'approved_change');

            return $this->item(DB::table('role_catalog')->where('id', $id)->first());
        });
    }

    public function update(TrustedActor $actor, string $id, array $input): array
    {
        $input = $this->validate($input, ['name' => 'required|string|max:120', 'description' => 'present|nullable|string|max:2000', 'status' => 'required|in:active,archived', 'expected_revision' => 'required|integer|min:1', 'request_id' => 'required|uuid']);

        return $this->mutate($actor, null, 'catalog.update', $input + ['resource_id' => $id], function () use ($actor, $id, $input) {
            $row = DB::table('role_catalog')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException;
            $this->authority($actor, 'role_catalog.manage', null, true);
            if ($row->revision !== $input['expected_revision']) {
                throw new ConflictHttpException;
            }
            if (trim($input['name']) === '') {
                throw ValidationException::withMessages(['name' => 'Required.']);
            }
            if (DB::table('role_catalog')->where('id', '<>', $id)->whereRaw('lower(btrim(name)) = lower(?)', [trim($input['name'])])->exists()) {
                throw new ConflictHttpException;
            }
            DB::table('role_catalog')->where('id', $id)->update(['name' => trim($input['name']), 'description' => $input['description'], 'status' => $input['status'], 'revision' => $row->revision + 1, 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->audit->record($actor->id, ['tenant_id' => $actor->tenantId], 'role_catalog.updated', ['type' => 'role_catalog', 'id' => $id], $row->revision + 1, 'approved_change');

            return $this->item(DB::table('role_catalog')->where('id', $id)->first());
        });
    }

    public function assign(TrustedActor $actor, string $project, array $input): array
    {
        $input = $this->validate($input, ['employee_id' => 'required|uuid', 'role_catalog_id' => 'required|uuid', 'expected_role_revision' => 'required|integer|min:1', 'request_id' => 'required|uuid', 'valid_from' => 'sometimes|required|date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s.uP,Y-m-d\TH:i:s.vP', 'valid_to' => 'sometimes|nullable|date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s.uP,Y-m-d\TH:i:s.vP']);

        return $this->mutate($actor, $project, 'assignment.create', $input, function () use ($actor, $project, $input) {
            $role = DB::table('role_catalog')->where('id', $input['role_catalog_id'])->lockForUpdate()->first() ?? throw new NotFoundHttpException;
            $memberships = DB::table('project_memberships')->where('project_id', $project)->where('employee_id', $input['employee_id'])->orderBy('id')->lockForUpdate()->get();
            $at = $this->authority($actor, 'roles.assign', $project, true);
            if ($role->status !== 'active' || $role->revision !== $input['expected_role_revision']) {
                throw new ConflictHttpException;
            }
            $from = isset($input['valid_from']) ? CarbonImmutable::parse($input['valid_from'])->utc() : CarbonImmutable::parse($at);
            $to = isset($input['valid_to']) ? CarbonImmutable::parse($input['valid_to'])->utc() : null;
            if ($to && $to <= $from) {
                throw ValidationException::withMessages(['valid_from' => 'Invalid period.']);
            }
            if (! $memberships->contains(fn ($m) => CarbonImmutable::parse($m->valid_from) <= CarbonImmutable::parse($at) && ($m->valid_to === null || CarbonImmutable::parse($m->valid_to) > CarbonImmutable::parse($at)) && CarbonImmutable::parse($m->valid_from) <= $from && ($m->valid_to === null || ($to !== null && CarbonImmutable::parse($m->valid_to) >= $to)))) {
                throw new AccessDeniedHttpException;
            }
            $fromStamp = $from->format('Y-m-d\TH:i:s.uP');
            $toStamp = $to?->format('Y-m-d\TH:i:s.uP');
            $overlap = DB::table('project_catalog_role_assignments')->where('project_id', $project)->where('employee_id', $input['employee_id'])->where('role_catalog_id', $role->id)->whereRaw("tstzrange(valid_from, valid_to, '[)') && tstzrange(?::timestamptz, ?::timestamptz, '[)')", [$fromStamp, $toStamp])->exists();
            if ($overlap) {
                throw new ConflictHttpException;
            }
            $id = (string) Str::uuid();
            DB::table('project_catalog_role_assignments')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'employee_id' => $input['employee_id'], 'role_catalog_id' => $role->id, 'definition_revision' => $role->revision, 'definition_name' => $role->name, 'valid_from' => $fromStamp, 'valid_to' => $toStamp]);
            $this->audit->record($actor->id, ['tenant_id' => $actor->tenantId, 'project_id' => $project], 'role_catalog.assigned', ['type' => 'project_catalog_role_assignments', 'id' => $id], 1, 'membership_change');

            return ['id' => $id, 'employee_id' => $input['employee_id'], 'role_catalog_id' => $role->id, 'name' => $role->name, 'revision' => 1, 'valid_from' => $fromStamp, 'valid_to' => $toStamp];
        });
    }
}
