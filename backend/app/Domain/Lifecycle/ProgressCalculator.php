<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProgressCalculator
{
    public function __construct(private readonly ProjectCoordination $coordination, private readonly CurrentInputEligibility $eligibility, private readonly EvidenceService $evidence, private readonly DependencyGraph $dependencies, private readonly AcceptanceAuthority $authority) {}

    public function calculate(TrustedActor $actor, string $project, ?string $baselineId = null): array
    {
        if ($baselineId !== null && ! Str::isUuid($baselineId)) {
            throw ValidationException::withMessages(['baseline' => 'Invalid baseline.']);
        }

        return $this->coordination->read($actor, $project, 'progress.read', fn () => $this->locked($actor, $project, $baselineId));
    }

    /** Caller owns the authorized project coordination lock. This method never writes projections. */
    public function locked(TrustedActor $actor, string $project, ?string $baselineId = null): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.tenant_id',true),'')") !== $actor->tenantId) {
            throw new \LogicException('Progress requires coordination context.');
        }
        $state = DB::table('lifecycle_project_state')->where('project_id', $project)->first();
        $id = $baselineId ?? $state?->current_baseline_id;
        $schedule = $this->dependencies->coverage($project);
        $base = ['status' => 'unavailable', 'accepted_weight' => 0, 'total_weight' => 0, 'percentage_basis_points' => null, 'baseline_id' => $id, 'policy_id' => null, 'coverage_reason_codes' => [], 'previous_snapshot' => null, 'schedule_coverage' => $schedule];
        if (! $id) {
            return array_replace($base, ['coverage_reason_codes' => ['baseline_missing']]);
        }
        $baseline = DB::table('baselines')->where('project_id', $project)->where('id', $id)->first() ?? throw new NotFoundHttpException;
        $base['policy_id'] = $baseline->policy_id;
        $snapshot = DB::table('baseline_progress_snapshots')->where('project_id', $project)->where('baseline_id', $id)->first();
        $source = true;
        try {
            $this->evidence->sourceAccess($actor, $project);
        } catch (AccessDeniedHttpException) {
            $source = false;
        }
        if ($snapshot) {
            $frozen = $this->snapshot($snapshot);
            if (! $source) {
                return array_replace($base, ['total_weight' => $frozen['total_weight'], 'coverage_reason_codes' => ['source_access_unavailable']]);
            }

            return array_replace($base, $frozen, ['schedule_coverage' => json_decode($snapshot->schedule_coverage, true, 16, JSON_THROW_ON_ERROR)]);
        }
        if ($id !== $state?->current_baseline_id) {
            throw new NotFoundHttpException;
        }
        $items = DB::table('baseline_items')->where('project_id', $project)->where('baseline_id', $id)->orderBy('stable_criterion_id')->get();
        $base['total_weight'] = (int) $items->sum('weight');
        if (! $source) {
            return array_replace($base, ['coverage_reason_codes' => ['source_access_unavailable']]);
        }
        $reasons = [];
        $accepted = 0;
        foreach ($items as $item) {
            $decision = DB::table('human_acceptance_decisions')->where('project_id', $project)->where('item_id', $item->id)->orderByDesc('input_generation')->first();
            if (! $decision && $item->carry_decision_id) {
                $decision = DB::table('human_acceptance_decisions')->where('project_id', $project)->where('id', $item->carry_decision_id)->first();
            }
            if (! $decision) {
                $reasons[] = 'human_acceptance_missing';

                continue;
            }
            $applicability = DB::table('acceptance_applicability')->where('project_id', $project)->where('decision_id', $decision->id)->value('status');
            if (in_array($applicability, ['stale', 'revoked'], true)) {
                $reasons[] = $applicability === 'revoked' ? 'acceptance_author_access_revoked' : 'acceptance_inputs_changed';

                continue;
            }
            if (! $this->authority->valid($actor, $project, $decision)) {
                $reasons[] = 'acceptance_author_access_revoked';

                continue;
            }
            $pins = json_decode($decision->pins, true, 16, JSON_THROW_ON_ERROR);
            $e = $this->eligibility->check($actor, $project, $item->criterion_version_id, $item->policy_id, array_column($pins['links'], 'id'));
            if ($decision->criterion_version_id === $item->criterion_version_id && $decision->policy_id === $item->policy_id && $e['status'] === 'valid' && AcceptancePins::matches($decision, $e['pins'])) {
                $accepted += $item->weight;
            } else {
                $reasons = array_merge($reasons, $e['reason_codes'] ?: ['acceptance_inputs_changed']);
            }
        }
        try {
            $this->evidence->sourceAccess($actor, $project);
        } catch (AccessDeniedHttpException) {
            return array_replace($base, ['coverage_reason_codes' => ['source_access_unavailable']]);
        }
        $previous = $baseline->previous_baseline_id ? DB::table('baseline_progress_snapshots')->where('project_id', $project)->where('baseline_id', $baseline->previous_baseline_id)->first() : null;
        if (! $base['total_weight']) {
            $reasons[] = 'zero_scope';
        }
        $available = $base['total_weight'] && ! in_array('acceptance_author_access_revoked', $reasons, true);

        return array_replace($base, ['status' => $available ? ($reasons ? 'partial' : 'available') : 'unavailable', 'accepted_weight' => $accepted, 'percentage_basis_points' => $available ? intdiv($accepted * 10000, $base['total_weight']) : null, 'coverage_reason_codes' => array_values(array_unique($reasons)), 'previous_snapshot' => $previous ? $this->snapshot($previous) : null]);
    }

    private function snapshot(object $s): array
    {
        return ['status' => $s->availability, 'accepted_weight' => $s->accepted_weight, 'total_weight' => $s->total_weight, 'percentage_basis_points' => $s->availability !== 'unavailable' && $s->total_weight ? intdiv($s->accepted_weight * 10000, $s->total_weight) : null, 'baseline_id' => $s->baseline_id, 'policy_id' => $s->policy_id, 'coverage_reason_codes' => json_decode($s->coverage, true, 16, JSON_THROW_ON_ERROR), 'as_of' => $s->as_of];
    }
}
