<?php

namespace App\Domain\Access;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;

final class ProjectReadScope
{
    /** Caller must establish TenantContext; every join also binds its tenant explicitly. */
    public function apply(Builder $projects, TrustedActor $actor, CarbonInterface $instant): Builder
    {
        $at = $instant->copy()->utc()->format('Y-m-d H:i:s.u');

        return $projects->where('projects.tenant_id', $actor->tenantId)
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('tenants as t')->where('t.id', $actor->tenantId)->where('t.status', 'active'))
            ->whereExists(function (Builder $q) use ($actor, $at): void {
                $q->selectRaw('1')->from('users as u')
                    ->join('project_memberships as m', function ($join): void {
                        $join->on('m.employee_id', '=', 'u.employee_id')->on('m.tenant_id', '=', 'u.tenant_id');
                    })->where('u.id', $actor->id)->where('u.tenant_id', $actor->tenantId)->where('u.status', 'active')
                    ->whereColumn('m.project_id', 'projects.id')->where('m.valid_from', '<=', $at)
                    ->where(fn ($q) => $q->whereNull('m.valid_to')->orWhere('m.valid_to', '>', $at));
            })->whereExists(function (Builder $q) use ($actor, $at): void {
                $q->selectRaw('1')->from('access_grants as g')->join('policy_versions as p', function ($join): void {
                    $join->on('p.id', '=', 'g.policy_version_id')->on('p.tenant_id', '=', 'g.tenant_id');
                })->where('g.tenant_id', $actor->tenantId)->where('g.user_id', $actor->id)->where('g.purpose', 'project.read')
                    ->where('p.status', 'active')->whereNull('g.revoked_at')->where('g.valid_from', '<=', $at)
                    ->where(fn ($q) => $q->whereNull('g.valid_to')->orWhere('g.valid_to', '>', $at))
                    ->where(fn ($q) => $q->where('g.scope_type', 'tenant')->orWhere(fn ($q) => $q->where('g.scope_type', 'project')->whereColumn('g.project_id', 'projects.id')));
            });
    }
}
