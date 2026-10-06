<?php

namespace App\Domain\Access;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DatabaseAccessGateway implements AccessGateway
{
    public const PURPOSES = ['organization.read', 'organization.manage', 'project.create', 'project.read', 'project.manage', 'roles.read', 'roles.manage', 'membership.manage', 'identity.read', 'identity.manage', 'analysis.context', 'connector.manage', 'ingestion.accept', 'ingestion.read', 'ingestion.process'];

    public function __construct(private readonly TenantContext $context) {}

    public function authorize(TrustedActor $actor, string $purpose, array $scope): void
    {
        if (($scope['tenant_id'] ?? null) !== $actor->tenantId
            || ! in_array($purpose, self::PURPOSES, true)
            || array_diff(array_keys($scope), ['tenant_id', 'project_id', 'department_id'])) {
            throw new AccessDeniedHttpException;
        }
        foreach (['project_id', 'department_id'] as $key) {
            if (isset($scope[$key]) && ! Str::isUuid($scope[$key])) {
                throw new AccessDeniedHttpException;
            }
        }
        if (isset($scope['project_id'], $scope['department_id'])) {
            throw new AccessDeniedHttpException;
        }
        $allowed = $this->context->run($actor->tenantId, function () use ($actor, $purpose, $scope): bool {
            $instant = now();
            $user = DB::table('users')->where('id', $actor->id)->where('status', 'active')->first();
            if ($user === null || ! DB::table('tenants')->where('id', $actor->tenantId)->where('status', 'active')->exists()) {
                return false;
            }
            $project = $scope['project_id'] ?? null;
            $department = $scope['department_id'] ?? null;
            if (in_array($purpose, ['project.read', 'project.manage', 'roles.read', 'roles.manage', 'membership.manage', 'analysis.context', 'connector.manage', 'ingestion.accept', 'ingestion.read', 'ingestion.process'], true) && $project === null) {
                return false;
            }
            if ($project !== null && ($user->employee_id === null || ! DB::table('project_memberships')
                ->where('project_id', $project)->where('employee_id', $user->employee_id)
                ->where('valid_from', '<=', $instant)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $instant))->exists())) {
                return false;
            }
            if ($department !== null && ($user->employee_id === null || ! DB::table('department_memberships')
                ->where('department_id', $department)->where('employee_id', $user->employee_id)
                ->where('valid_from', '<=', $instant)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $instant))->exists())) {
                return false;
            }
            $grants = DB::table('access_grants as g')->join('policy_versions as p', function ($join): void {
                $join->on('p.id', '=', 'g.policy_version_id')->on('p.tenant_id', '=', 'g.tenant_id');
            })->where('g.user_id', $actor->id)->where('g.purpose', $purpose)->where('p.status', 'active')
                ->whereNull('g.revoked_at')->where('g.valid_from', '<=', $instant)
                ->where(fn ($q) => $q->whereNull('g.valid_to')->orWhere('g.valid_to', '>', $instant));
            // An unscoped list requires an explicitly tenant-wide grant.
            $grants->where(function ($q) use ($project, $department): void {
                $q->where('g.scope_type', 'tenant');
                if ($project !== null) {
                    $q->orWhere(fn ($q) => $q->where('g.scope_type', 'project')->where('g.project_id', $project));
                }
                if ($department !== null) {
                    $q->orWhere(fn ($q) => $q->where('g.scope_type', 'department')->where('g.department_id', $department));
                }
            });

            return $grants->exists();
        });
        if (! $allowed) {
            throw new AccessDeniedHttpException;
        }
    }
}
