<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AcceptanceAuthority
{
    public function __construct(private readonly AccessGateway $access) {}

    /** Exact bounded proof of the original human's authority, independent of viewer authority. */
    public function capture(TrustedActor $actor, string $project): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.tenant_id',true),'')") !== $actor->tenantId) {
            throw new \LogicException('Authority requires coordination context.');
        }
        $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
        foreach (['acceptance.review', 'ingestion.read'] as $purpose) {
            $this->access->authorize($actor, $purpose, $scope);
        }
        $user = DB::table('users')->where('id', $actor->id)->where('status', 'active')->sharedLock()->first();
        $membership = DB::table('project_memberships')->where('project_id', $project)->where('employee_id', $user->employee_id)->where('valid_from', '<=', now())->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()))->orderBy('id')->sharedLock()->first(['id', 'employee_id', 'valid_from', 'valid_to']);
        if (! $membership) {
            throw new AccessDeniedHttpException;
        }
        $proof = ['version' => 'acceptance-authority-v1', 'actor_id' => $actor->id, 'employee_id' => $user->employee_id, 'membership' => (array) $membership, 'grants' => []];
        foreach (['acceptance.review', 'ingestion.read'] as $purpose) {
            $q = DB::table('access_grants as g')->join('policy_versions as p', fn ($j) => $j->on('p.id', '=', 'g.policy_version_id')->on('p.tenant_id', '=', 'g.tenant_id'))->where('g.user_id', $actor->id)->where('g.purpose', $purpose)->where('p.status', 'active')->whereNull('g.revoked_at')->where('g.valid_from', '<=', now())->where(fn ($q) => $q->whereNull('g.valid_to')->orWhere('g.valid_to', '>', now()));
            $q->where(function ($q) use ($project, $purpose) {
                $q->where('g.scope_type', 'project')->where('g.project_id', $project);
                if ($purpose === 'ingestion.read') {
                    $q->orWhere('g.scope_type', 'tenant');
                }
            });
            $g = $q->orderBy('g.id')->sharedLock()->first(['g.id', 'g.policy_version_id', 'p.version as policy_version', 'g.purpose', 'g.scope_type', 'g.project_id', 'g.department_id', 'g.valid_from', 'g.valid_to']);
            if (! $g) {
                throw new AccessDeniedHttpException;
            }
            $proof['grants'][] = (array) $g;
        }

        return $proof;
    }

    public function valid(TrustedActor $viewer, string $project, object $decision): bool
    {
        if (! $decision->authority || ! $decision->authority_hash) {
            return false;
        }
        $proof = json_decode($decision->authority, true, 16, JSON_THROW_ON_ERROR);
        if (! hash_equals($decision->authority_hash, hash('sha256', AcceptancePins::json($proof))) || ($proof['version'] ?? null) !== 'acceptance-authority-v1' || ($proof['actor_id'] ?? null) !== $decision->actor_id) {
            return false;
        }
        try {
            $original = new TrustedActor($decision->actor_id, $viewer->tenantId);
            foreach (['acceptance.review', 'ingestion.read'] as $purpose) {
                $this->access->authorize($original, $purpose, ['tenant_id' => $viewer->tenantId, 'project_id' => $project]);
            }
        } catch (AccessDeniedHttpException) {
            return false;
        }
        $user = DB::table('users')->where('id', $decision->actor_id)->where('status', 'active')->sharedLock()->first();
        if (! $user || $user->employee_id !== ($proof['employee_id'] ?? null)) {
            return false;
        }
        $m = DB::table('project_memberships')->where('project_id', $project)->where('employee_id', $user->employee_id)->where('id', $proof['membership']['id'] ?? null)->where('valid_from', '<=', now())->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()))->sharedLock()->first(['id', 'employee_id', 'valid_from', 'valid_to']);
        if (! $m || AcceptancePins::json((array) $m) !== AcceptancePins::json($proof['membership'])) {
            return false;
        }
        if (count($proof['grants'] ?? []) !== 2) {
            return false;
        }
        foreach ($proof['grants'] as $p) {
            $g = DB::table('access_grants as g')->join('policy_versions as p', fn ($j) => $j->on('p.id', '=', 'g.policy_version_id')->on('p.tenant_id', '=', 'g.tenant_id'))->where('g.id', $p['id'])->where('g.user_id', $decision->actor_id)->where('p.status', 'active')->whereNull('g.revoked_at')->where('g.valid_from', '<=', now())->where(fn ($q) => $q->whereNull('g.valid_to')->orWhere('g.valid_to', '>', now()))->sharedLock()->first(['g.id', 'g.policy_version_id', 'p.version as policy_version', 'g.purpose', 'g.scope_type', 'g.project_id', 'g.department_id', 'g.valid_from', 'g.valid_to']);
            if (! $g || AcceptancePins::json((array) $g) !== AcceptancePins::json($p)) {
                return false;
            }
        }

        return true;
    }
}
