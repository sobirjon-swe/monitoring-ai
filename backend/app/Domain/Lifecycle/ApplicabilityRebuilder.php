<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ApplicabilityRebuilder
{
    public function __construct(private readonly AccessGateway $access, private readonly CurrentInputEligibility $eligibility, private readonly AcceptanceAuthority $authority) {}

    /** Caller holds the project coordination lock and applies this together with fenced completion. */
    public function apply(TrustedActor $operator, object $persistedChange): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.tenant_id',true),'')") !== $operator->tenantId) {
            throw new LogicException('Rebuild requires authorized coordination context.');
        }
        $change = DB::table('lifecycle_changes')->where('tenant_id', $operator->tenantId)->where('project_id', $persistedChange->project_id)->where('id', $persistedChange->id)->first() ?? throw new NotFoundHttpException;
        $this->authorizeChange($operator, $change);
        $state = DB::table('lifecycle_project_state')->where('project_id', $change->project_id)->first();
        $query = DB::table('human_acceptance_decisions as d')->join('criterion_versions as c', 'c.id', '=', 'd.criterion_version_id')->where('d.project_id', $change->project_id);
        switch ($change->subject_kind) {
            case 'criterion_replaced':
            case 'criterion_removed':
            case 'qa_changed':
            case 'acceptance_changed':
                $query->where('c.criterion_id', $change->subject_id);
                break;
            case 'evidence_changed':
                $query->whereRaw("d.pins->'links' @> ?::jsonb", [json_encode([['id' => $change->subject_id]], JSON_THROW_ON_ERROR)]);
                break;
            case 'baseline_activated':
            case 'policy_activated':
            case 'project_closed':
                break;
            default:
                throw new LogicException('Unsupported lifecycle change.');
        }
        $affected = 0;
        $stale = 0;
        $reasons = [];
        // Cursor chunks bound memory while preserving every historical dependency, without deleting history.
        $query->select('d.*')->chunkById(100, function ($decisions) use ($operator, $change, $state, &$affected, &$stale, &$reasons) {
            foreach ($decisions as $decision) {
                $affected++;
                $projection = DB::table('acceptance_applicability')->where('project_id', $change->project_id)->where('decision_id', $decision->id)->lockForUpdate()->first();
                $status = $projection?->status ?? 'valid';
                // Revocation is terminal, and stale inputs can never become valid for this human decision.
                if (! $this->authority->valid($operator, $change->project_id, $decision)) {
                    $status = 'revoked';
                } elseif ($status === 'valid') {
                    $pins = json_decode($decision->pins, true, 16, JSON_THROW_ON_ERROR);
                    $current = DB::table('baseline_items')->where('project_id', $change->project_id)->where('baseline_id', $state->current_baseline_id)->where('criterion_version_id', $decision->criterion_version_id)->where('policy_id', $decision->policy_id)->first();
                    $eligibility = $this->eligibility->check($operator, $change->project_id, $decision->criterion_version_id, $decision->policy_id, array_column($pins['links'], 'id'));
                    if (! $current || ($current->id !== $decision->item_id && $current->carry_decision_id !== $decision->id) || $eligibility['status'] !== 'valid' || ! AcceptancePins::matches($decision, $eligibility['pins'])) {
                        $status = 'stale';
                    }
                }
                if ($status !== 'valid') {
                    $stale++;
                    $reasons[] = $status === 'revoked' ? 'access_revoked' : 'input_stale';
                }
                $values = ['status' => $status, 'input_generation' => max($state->generation, $projection?->input_generation ?? 0, $decision->input_generation), 'pins_hash' => $decision->pins_hash];
                if ($projection) {
                    DB::table('acceptance_applicability')->where('project_id', $change->project_id)->where('decision_id', $decision->id)->update($values);
                } else {
                    DB::table('acceptance_applicability')->insert($values + ['tenant_id' => $operator->tenantId, 'project_id' => $change->project_id, 'decision_id' => $decision->id]);
                }
            }
        }, 'd.id', 'id');
        $this->authorizeChange($operator, $change);

        return ['affected' => $affected, 'stale' => $stale, 'reason_codes' => array_values(array_unique($reasons))];
    }

    /** Lock authority after the project parent, before outbox/projection rows; no inverse project lock. */
    public function authorizeChange(TrustedActor $operator, object $change): void
    {
        $scope = ['tenant_id' => $operator->tenantId, 'project_id' => $change->project_id];
        $purposes = [
            'criterion_replaced' => 'requirements.approve', 'criterion_removed' => 'requirements.approve',
            'baseline_activated' => 'baseline.approve', 'policy_activated' => 'baseline.approve', 'project_closed' => 'baseline.approve',
            'evidence_changed' => 'evidence.review', 'qa_changed' => 'evidence.review', 'acceptance_changed' => 'acceptance.review',
        ];
        if (($purposes[$change->subject_kind] ?? null) !== $change->authorizing_purpose || $change->tenant_id !== $operator->tenantId) {
            throw new AccessDeniedHttpException;
        }
        $actors = [$operator->id, $change->actor_id];
        sort($actors, SORT_STRING);
        $users = DB::table('users')->whereIn('id', array_unique($actors))->orderBy('id')->sharedLock()->get();
        DB::table('project_memberships')->where('project_id', $change->project_id)->whereIn('employee_id', $users->pluck('employee_id'))->orderBy('id')->sharedLock()->get();
        DB::table('access_grants as g')->join('policy_versions as p', 'p.id', '=', 'g.policy_version_id')->whereIn('g.user_id', $actors)->orderBy('g.id')->sharedLock()->get(['g.id']);
        $this->access->authorize($operator, 'lifecycle.process', $scope);
        $this->access->authorize(new TrustedActor($change->actor_id, $operator->tenantId), $change->authorizing_purpose, $scope);
        // Rebuilding any accepted input retrieves source evidence. Empty changes do not need source access.
        if (in_array($change->subject_kind, ['evidence_changed', 'qa_changed', 'acceptance_changed'], true) || DB::table('human_acceptance_decisions')->where('project_id', $change->project_id)->exists()) {
            $this->access->authorize($operator, 'ingestion.read', $scope);
        }
        if (in_array($change->subject_kind, ['evidence_changed', 'qa_changed', 'acceptance_changed'], true)) {
            $this->access->authorize(new TrustedActor($change->actor_id, $operator->tenantId), 'ingestion.read', $scope);
        }
    }
}
