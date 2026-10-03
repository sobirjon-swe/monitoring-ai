<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrganizationService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function createEmployee(string $tenant, string $actor, string $name, string $reason): object
    {
        return $this->create('employees', 'display_name', $tenant, $actor, $name, $reason);
    }

    public function createDepartment(string $tenant, string $actor, string $name, string $reason): object
    {
        return $this->create('departments', 'name', $tenant, $actor, $name, $reason);
    }

    public function updateEmployee(string $tenant, string $actor, string $id, string $name, int $revision, string $reason): object
    {
        return $this->update('employees', 'display_name', $tenant, $actor, $id, $name, $revision, $reason);
    }

    public function updateDepartment(string $tenant, string $actor, string $id, string $name, int $revision, string $reason): object
    {
        return $this->update('departments', 'name', $tenant, $actor, $id, $name, $revision, $reason);
    }

    private function update(string $table, string $field, string $tenant, string $actor, string $id, string $name, int $revision, string $reason): object
    {
        if (trim($name) === '' || mb_strlen($name) > 200 || $revision < 1) {
            throw ValidationException::withMessages([$field => 'Invalid value.']);
        }

        return DB::transaction(function () use ($table, $field, $tenant, $actor, $id, $name, $revision, $reason): object {
            $query = DB::table($table)->where('id', $id)->where('tenant_id', $tenant);
            if ($query->lockForUpdate()->first() === null) {
                throw new NotFoundHttpException;
            }
            if ((clone $query)->where('revision', $revision)->update([$field => trim($name), 'revision' => $revision + 1, 'updated_at' => now()]) !== 1) {
                throw new ConflictHttpException;
            }
            $this->audit->record($actor, ['tenant_id' => $tenant], 'organization.updated', ['type' => $table, 'id' => $id], $revision + 1, $reason);

            return $query->first();
        });
    }

    private function create(string $table, string $field, string $tenant, string $actor, string $name, string $reason): object
    {
        if (trim($name) === '' || mb_strlen($name) > 200) {
            throw ValidationException::withMessages([$field => 'Invalid name.']);
        }

        return DB::transaction(function () use ($table, $field, $tenant, $actor, $name, $reason): object {
            $id = (string) Str::uuid();
            DB::table($table)->insert(['id' => $id, 'tenant_id' => $tenant, $field => trim($name), 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record($actor, ['tenant_id' => $tenant], 'organization.created', ['type' => $table, 'id' => $id], 1, $reason);

            return DB::table($table)->where('id', $id)->first();
        });
    }

    public function listEmployees(int $limit = 50, ?string $cursor = null): array
    {
        return $this->page('employees', $limit, $cursor);
    }

    public function listDepartments(int $limit = 50, ?string $cursor = null): array
    {
        return $this->page('departments', $limit, $cursor);
    }

    private function page(string $table, int $limit, ?string $cursor): array
    {
        if ($limit < 1 || $limit > 100 || ($cursor !== null && ! Str::isUuid($cursor))) {
            throw ValidationException::withMessages(['pagination' => 'Invalid pagination.']);
        }
        $rows = DB::table($table)->when($cursor, fn ($query) => $query->where('id', '>', $cursor))->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $data = $rows->take($limit)->map(fn ($row) => (array) $row)->values()->all();

        return ['data' => $data, 'next_cursor' => $hasMore ? $data[count($data) - 1]['id'] : null];
    }

    public function addDepartmentMembership(string $tenant, string $actor, string $employee, string $department, string $from, ?string $to, string $reason): string
    {
        return $this->relationship('department_memberships', 'department_id', $tenant, $actor, $employee, $department, $from, $to, $reason);
    }

    public function addReportingRelationship(string $tenant, string $actor, string $employee, string $manager, string $from, ?string $to, string $reason): string
    {
        return $this->relationship('reporting_relationships', 'manager_id', $tenant, $actor, $employee, $manager, $from, $to, $reason);
    }

    public function departmentMembershipsAt(string $employee, string $at): array
    {
        return $this->relationshipsAt('department_memberships', $employee, $at);
    }

    public function reportingRelationshipsAt(string $employee, string $at): array
    {
        return $this->relationshipsAt('reporting_relationships', $employee, $at);
    }

    private function relationshipsAt(string $table, string $employee, string $at): array
    {
        $instant = CarbonImmutable::parse($at)->utc();

        return DB::table($table)->where('employee_id', $employee)->where('valid_from', '<=', $instant)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>', $instant))
            ->orderBy('valid_from')->orderBy('id')->limit(100)->get()->all();
    }

    private function relationship(string $table, string $relatedKey, string $tenant, string $actor, string $employee, string $related, string $from, ?string $to, string $reason): string
    {
        try {
            $start = CarbonImmutable::parse($from)->utc();
            $end = $to === null ? null : CarbonImmutable::parse($to)->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['valid_from' => 'Invalid period.']);
        }
        if ($end !== null && $end <= $start) {
            throw ValidationException::withMessages(['valid_to' => 'Invalid period.']);
        }

        return DB::transaction(function () use ($table, $relatedKey, $tenant, $actor, $employee, $related, $start, $end, $reason): string {
            $id = (string) Str::uuid();
            DB::table($table)->insert(['id' => $id, 'tenant_id' => $tenant, 'employee_id' => $employee, $relatedKey => $related, 'valid_from' => $start, 'valid_to' => $end, 'revision' => 1]);
            $this->audit->record($actor, ['tenant_id' => $tenant], 'organization.membership_created', ['type' => $table, 'id' => $id], 1, $reason);

            return $id;
        });
    }
}
