<?php

namespace App\Domain\Projects;

use App\Domain\Audit\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProjectService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function find(string $id): object
    {
        return DB::table('projects')->where('id', $id)->first() ?? throw new NotFoundHttpException;
    }

    public function create(string $tenant, string $actor, string $name): object
    {
        return DB::transaction(function () use ($tenant, $actor, $name): object {
            $id = (string) Str::uuid();
            DB::table('projects')->insert(['id' => $id, 'tenant_id' => $tenant, 'name' => $name]);
            $this->audit->record($actor, ['tenant_id' => $tenant, 'project_id' => $id], 'project.created', ['type' => 'projects', 'id' => $id], 1, 'approved_change');
            $employee = DB::table('users')->where('id', $actor)->value('employee_id');
            if ($employee !== null) {
                $this->addMembership($tenant, $actor, $id, $employee, now()->toISOString(), null);
            }

            return $this->find($id);
        });
    }

    public function update(string $tenant, string $actor, string $id, string $name, int $revision): object
    {
        return DB::transaction(function () use ($tenant, $actor, $id, $name, $revision): object {
            $this->find($id);
            if (DB::table('projects')->where('id', $id)->where('revision', $revision)->update(['name' => $name, 'revision' => $revision + 1]) !== 1) {
                throw new ConflictHttpException;
            }
            $this->audit->record($actor, ['tenant_id' => $tenant, 'project_id' => $id], 'project.updated', ['type' => 'projects', 'id' => $id], $revision + 1, 'approved_change');

            return $this->find($id);
        });
    }

    public function addMembership(string $tenant, string $actor, string $project, string $employee, string $from, ?string $to): object
    {
        return $this->insertPeriod('project_memberships', $tenant, $actor, $project, $employee, null, $from, $to);
    }

    public function assignRole(string $tenant, string $actor, string $project, string $employee, string $role, string $from, ?string $to): object
    {
        return $this->insertPeriod('role_assignments', $tenant, $actor, $project, $employee, $role, $from, $to);
    }

    private function insertPeriod(string $table, string $tenant, string $actor, string $project, string $employee, ?string $role, string $from, ?string $to): object
    {
        [$from, $to] = $this->period($from, $to);

        return DB::transaction(function () use ($table, $tenant, $actor, $project, $employee, $role, $from, $to): object {
            $id = (string) Str::uuid();
            $data = ['id' => $id, 'tenant_id' => $tenant, 'project_id' => $project, 'employee_id' => $employee, 'valid_from' => $from, 'valid_to' => $to];
            if ($role !== null) {
                $data['role'] = $role;
            }
            DB::table($table)->insert($data);
            $this->audit->record($actor, ['tenant_id' => $tenant, 'project_id' => $project], 'project.assignment_created', ['type' => $table, 'id' => $id], 1, 'membership_change');

            return DB::table($table)->where('id', $id)->first();
        });
    }

    public function endPeriod(string $table, string $tenant, string $actor, string $project, string $id, string $to, int $revision): object
    {
        if (! in_array($table, ['project_memberships', 'role_assignments'], true)) {
            throw new \InvalidArgumentException;
        }

        return DB::transaction(function () use ($table, $tenant, $actor, $project, $id, $to, $revision): object {
            $row = DB::table($table)->where('project_id', $project)->where('id', $id)->first() ?? throw new NotFoundHttpException;
            [, $end] = $this->period($row->valid_from, $to);
            if (DB::table($table)->where('id', $id)->where('revision', $revision)->update(['valid_to' => $end, 'revision' => $revision + 1]) !== 1) {
                throw new ConflictHttpException;
            }
            $this->audit->record($actor, ['tenant_id' => $tenant, 'project_id' => $project], 'project.assignment_updated', ['type' => $table, 'id' => $id], $revision + 1, 'membership_change');

            return DB::table($table)->where('id', $id)->first();
        });
    }

    public function rolesAt(string $project, string $employee, string $at): array
    {
        $instant = CarbonImmutable::parse($at)->utc();

        return DB::table('role_assignments')->where('project_id', $project)->where('employee_id', $employee)
            ->where('valid_from', '<=', $instant)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $instant))
            ->orderBy('id')->limit(100)->get()->all();
    }

    private function period(string $from, ?string $to): array
    {
        $start = CarbonImmutable::parse($from)->utc();
        $end = $to === null ? null : CarbonImmutable::parse($to)->utc();
        if ($end !== null && $end <= $start) {
            throw ValidationException::withMessages(['valid_to' => ['Invalid period.']]);
        }

        return [$start->format('Y-m-d H:i:s.uP'), $end?->format('Y-m-d H:i:s.uP')];
    }
}
