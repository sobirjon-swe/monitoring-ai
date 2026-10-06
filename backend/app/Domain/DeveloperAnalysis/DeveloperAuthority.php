<?php

namespace App\Domain\DeveloperAnalysis;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class DeveloperAuthority
{
    public const PURPOSES = ['analysis.request', 'analysis.read', 'analysis.source.read', 'analysis.review', 'analysis.process', 'analysis.fixture.manage'];

    public function __construct(private readonly AccessGateway $access) {}

    public function capture(TrustedActor $actor, string $project, array $purposes): array
    {
        return $this->captureBatch($actor, $project, [['actor_id' => $actor->id, 'purposes' => $purposes]])[$actor->id];
    }

    public function valid(TrustedActor $scope, string $project, array $proof): bool
    {
        try {
            $this->captureBatch($scope, $project, [['actor_id' => $proof['actor_id'] ?? '', 'purposes' => $proof['purposes'] ?? [], 'pinned_proof' => $proof]]);

            return true;
        } catch (AccessDeniedHttpException) {
            return false;
        }
    }

    /** Natural expiry is not protected by row locks; recheck held proofs without acquiring rows. */
    public function assertEffective(array $proofs, string $instant): void
    {
        try {
            $at = CarbonImmutable::parse($instant, 'UTC');
            foreach ($proofs as $proof) {
                foreach ([$proof['membership'], ...$proof['grants']] as $row) {
                    if (CarbonImmutable::parse($row['valid_from'], 'UTC')->greaterThan($at)
                        || ($row['valid_to'] !== null && CarbonImmutable::parse($row['valid_to'], 'UTC')->lessThanOrEqualTo($at))) {
                        throw new AccessDeniedHttpException;
                    }
                }
            }
        } catch (\Throwable) {
            throw new AccessDeniedHttpException;
        }
    }

    /** Lock unions by table and sorted ID, never by actor or joined-query execution order. */
    public function captureBatch(TrustedActor $scope, string $project, array $requests): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.tenant_id',true),'')") !== $scope->tenantId
            || DB::scalar("select nullif(current_setting('app.developer_authority_closed',true),'')") !== null) {
            throw new LogicException('Authority requires an open coordination phase.');
        }
        if (! Str::isUuid($project) || count($requests) < 1 || count($requests) > 20) {
            throw new AccessDeniedHttpException;
        }
        $actors = [];
        foreach ($requests as $request) {
            $id = $request['actor_id'] ?? '';
            $purposes = $request['purposes'] ?? [];
            if (! Str::isUuid($id) || ! is_array($purposes) || ! array_is_list($purposes) || ! $purposes || array_diff($purposes, self::PURPOSES)
                || array_diff(array_keys($request), ['actor_id', 'purposes', 'pinned_proof'])) {
                throw new AccessDeniedHttpException;
            }
            $previous = $actors[$id] ?? ['purposes' => []];
            if (isset($previous['pinned_proof'], $request['pinned_proof']) && DeveloperContract::canonical($previous['pinned_proof']) !== DeveloperContract::canonical($request['pinned_proof'])) {
                throw new AccessDeniedHttpException;
            }
            $purposes = array_values(array_unique([...$previous['purposes'], ...$purposes]));
            sort($purposes, SORT_STRING);
            $actors[$id] = ['purposes' => $purposes] + (isset($request['pinned_proof']) ? ['pinned_proof' => $request['pinned_proof']] : $previous);
        }
        ksort($actors, SORT_STRING);
        $tenant = DB::table('tenants')->where('id', $scope->tenantId)->sharedLock()->first();
        $users = DB::table('users')->whereIn('id', array_keys($actors))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $members = DB::table('project_memberships')->where('project_id', $project)->whereIn('employee_id', $users->pluck('employee_id')->filter()->all())->orderBy('id')->sharedLock()->get();
        $grants = DB::table('access_grants')->whereIn('user_id', array_keys($actors))->where('scope_type', 'project')->where('project_id', $project)->whereIn('purpose', self::PURPOSES)->orderBy('id')->sharedLock()->get();
        $policies = DB::table('policy_versions')->whereIn('id', $grants->pluck('policy_version_id')->unique()->all())->orderBy('id')->sharedLock()->get()->keyBy('id');
        // Sample after all locks: a grant expiring while locks are awaited is never effective.
        $instant = DB::scalar('select clock_timestamp()');
        $effective = fn ($row) => $row->valid_from <= $instant && ($row->valid_to === null || $row->valid_to > $instant);
        $proofs = [];
        foreach ($actors as $id => $request) {
            $user = $users->get($id);
            if (! $tenant || $tenant->status !== 'active' || ! $user || $user->status !== 'active' || ! $user->employee_id) {
                throw new AccessDeniedHttpException;
            }
            $pin = $request['pinned_proof'] ?? null;
            if ($pin !== null && (! is_array($pin) || ($pin['version'] ?? '') !== 'developer-authority-v1' || ($pin['actor_id'] ?? '') !== $id
                || ($pin['tenant_id'] ?? '') !== $scope->tenantId || ($pin['project_id'] ?? '') !== $project || ! is_array($pin['purposes'] ?? null)
                || ! $pin['purposes'] || array_diff($pin['purposes'], $request['purposes']))) {
                throw new AccessDeniedHttpException;
            }
            $membership = $members->first(fn ($m) => $m->employee_id === $user->employee_id && $effective($m) && ($pin === null || $m->id === ($pin['membership']['id'] ?? '')));
            if (! $membership) {
                throw new AccessDeniedHttpException;
            }
            $proof = ['version' => 'developer-authority-v1', 'tenant_id' => $scope->tenantId, 'project_id' => $project, 'actor_id' => $id, 'employee_id' => $user->employee_id,
                'purposes' => $request['purposes'], 'membership' => $this->fields($membership, ['id', 'employee_id', 'valid_from', 'valid_to', 'revision']), 'grants' => [], 'captured_at' => $instant];
            foreach ($request['purposes'] as $purpose) {
                $this->access->authorize(new TrustedActor($id, $scope->tenantId), $purpose, ['tenant_id' => $scope->tenantId, 'project_id' => $project]);
                $pinnedGrant = $pin === null ? null : collect($pin['grants'] ?? [])->firstWhere('purpose', $purpose);
                $isPinned = $pin !== null && in_array($purpose, $pin['purposes'], true);
                $grant = $grants->first(fn ($g) => $g->user_id === $id && $g->purpose === $purpose && $g->revoked_at === null && $effective($g)
                    && $policies->get($g->policy_version_id)?->status === 'active' && (! $isPinned || $g->id === ($pinnedGrant['id'] ?? '')));
                if (! $grant) {
                    throw new AccessDeniedHttpException;
                }
                $proof['grants'][] = $this->fields($grant, ['id', 'policy_version_id', 'purpose', 'scope_type', 'project_id', 'department_id', 'valid_from', 'valid_to', 'revision'])
                    + ['policy_version' => $policies->get($grant->policy_version_id)->version];
            }
            if ($pin !== null) {
                $compare = $proof;
                $compare['purposes'] = $pin['purposes'];
                $compare['grants'] = array_values(array_filter($proof['grants'], fn ($grant) => in_array($grant['purpose'], $pin['purposes'], true)));
                unset($compare['captured_at']);
                $old = $pin;
                unset($old['captured_at']);
                if (DeveloperContract::canonical($compare) !== DeveloperContract::canonical($old)) {
                    throw new AccessDeniedHttpException;
                }
            }
            $proofs[$id] = $proof;
        }

        return $proofs;
    }

    private function fields(object $row, array $keys): array
    {
        return array_intersect_key((array) $row, array_flip($keys));
    }
}
