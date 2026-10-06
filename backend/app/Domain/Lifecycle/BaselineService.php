<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BaselineService
{
    public function __construct(private readonly ProjectCoordination $coordination, private readonly CurrentInputEligibility $eligibility, private readonly ProgressCalculator $progress, private readonly LifecycleChangeWriter $changes, private readonly AuditWriter $audit, private readonly AcceptanceAuthority $authority, private readonly EvidenceService $evidence) {}

    public function approve(TrustedActor $actor, string $project, array $items, string $policyId, ?string $expectedCurrentBaselineId, int $expectedGeneration, string $reason, bool $reopen = false): array
    {
        $this->validate($items, $policyId, $expectedCurrentBaselineId, $reason);
        $work = function () use ($actor, $project, $items, $policyId, $expectedCurrentBaselineId, $reason, $reopen) {
            $state = DB::table('lifecycle_project_state')->where('project_id', $project)->first();
            if ($state->current_baseline_id !== $expectedCurrentBaselineId || ($reopen && ! $expectedCurrentBaselineId)) {
                throw new ConflictHttpException;
            }
            if (! DB::table('dod_policy_versions')->where('project_id', $project)->where('id', $policyId)->exists()) {
                $this->invalid();
            }
            $prepared = [];
            foreach ($items as $item) {
                $cv = DB::table('criterion_versions')->where('project_id', $project)->where('id', $item['criterion_version_id'])->first();
                if (! $cv) {
                    $this->invalid();
                }
                $c = DB::table('criteria')->where('project_id', $project)->where('id', $cv->criterion_id)->first();
                $latest = DB::table('requirement_versions')->where('project_id', $project)->where('requirement_id', $c->requirement_id)->orderByDesc('version')->first();
                if (! $latest || ! DB::table('requirement_version_criteria')->where('project_id', $project)->where('requirement_version_id', $latest->id)->where('criterion_version_id', $cv->id)->exists()) {
                    throw new ConflictHttpException;
                }
                if (in_array($cv->criterion_id, array_column($prepared, 'stable_criterion_id'), true)) {
                    $this->invalid();
                }
                if (isset($item['carry_decision_id'])) {
                    $d = DB::table('human_acceptance_decisions')->where('project_id', $project)->where('id', $item['carry_decision_id'])->first();
                    if (! $d || ! $state->current_baseline_id || $d->criterion_version_id !== $cv->id || $d->policy_id !== $policyId) {
                        throw new ConflictHttpException;
                    }
                    if (in_array(DB::table('acceptance_applicability')->where('project_id', $project)->where('decision_id', $d->id)->value('status'), ['stale', 'revoked'], true)) {
                        throw new ConflictHttpException;
                    }
                    if (! $this->authority->valid($actor, $project, $d)) {
                        throw new ConflictHttpException;
                    }
                    // A prior baseline must explicitly contain this decision, directly or through approved carry lineage.
                    $prior = DB::table('baseline_items')->where('project_id', $project)->where('baseline_id', $state->current_baseline_id)->where('criterion_version_id', $cv->id)->first();
                    if (! $prior || ($prior->id !== $d->item_id && $prior->carry_decision_id !== $d->id)) {
                        throw new ConflictHttpException;
                    }
                    $pins = json_decode($d->pins, true, 16, JSON_THROW_ON_ERROR);
                    $e = $this->eligibility->check($actor, $project, $cv->id, $policyId, array_column($pins['links'], 'id'));
                    if ($e['status'] !== 'valid' || ! AcceptancePins::matches($d, $e['pins'])) {
                        throw new ConflictHttpException;
                    }
                }
                $prepared[] = ['stable_criterion_id' => $cv->criterion_id] + $item;
            }
            usort($prepared, fn ($a, $b) => strcmp($a['stable_criterion_id'], $b['stable_criterion_id']));
            $id = (string) Str::uuid();
            $hash = hash('sha256', AcceptancePins::json(['items' => $prepared, 'policy_id' => $policyId, 'previous_baseline_id' => $state->current_baseline_id, 'reopen' => $reopen]));
            $oldProgress = $state->current_baseline_id ? $this->progress->locked($actor, $project) : null;
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
            DB::table('baselines')->insert(['id' => $id, 'policy_id' => $policyId, 'previous_baseline_id' => $state->current_baseline_id, 'content_hash' => $hash, 'approved_by' => $actor->id, 'reason' => $reason, 'is_reopen' => $reopen] + $scope);
            foreach ($prepared as $item) {
                DB::table('baseline_items')->insert(['id' => (string) Str::uuid(), 'baseline_id' => $id, 'policy_id' => $policyId, 'stable_criterion_id' => $item['stable_criterion_id'], 'criterion_version_id' => $item['criterion_version_id'], 'weight' => $item['weight'], 'carry_decision_id' => $item['carry_decision_id'] ?? null] + $scope);
            }
            $event = $this->changes->record($actor, $project, 'baseline.approve', 'baseline_activated', $id, $id, $reason);
            if ($oldProgress) {
                $this->freeze($actor, $project, $oldProgress, $event);
            }
            if ($state->current_dod_policy_id !== $policyId) {
                $this->changes->record($actor, $project, 'baseline.approve', 'policy_activated', $policyId, $id, $reason);
            }
            DB::table('lifecycle_project_state')->where('project_id', $project)->update(['current_baseline_id' => $id, 'current_dod_policy_id' => $policyId, 'is_closed' => false]);
            $this->audit->record($actor->id, $scope, 'baseline.approved', ['type' => 'baselines', 'id' => $id], 1, 'approved_change');

            return ['id' => $id, 'policy_id' => $policyId, 'hash' => $hash, 'previous_baseline_id' => $state->current_baseline_id, 'status' => $reopen ? 'reopened' : 'active'];
        };
        $r = $reopen ? $this->coordination->reopenBaseline($actor, $project, $expectedGeneration, $work) : $this->coordination->run($actor, $project, 'baseline.approve', $expectedGeneration, $work);

        return $r['result'] + ['generation' => $r['generation']];
    }

    public function close(TrustedActor $actor, string $project, int $expectedGeneration, string $reason): array
    {
        if (! trim($reason) || mb_strlen($reason) > 500 || str_contains($reason, "\0")) {
            $this->invalid();
        }
        $r = $this->coordination->run($actor, $project, 'baseline.approve', $expectedGeneration, function () use ($actor, $project, $reason) {
            $state = DB::table('lifecycle_project_state')->where('project_id', $project)->first();
            if (! $state->current_baseline_id) {
                throw new ConflictHttpException;
            }
            $p = $this->progress->locked($actor, $project);
            $event = $this->changes->record($actor, $project, 'baseline.approve', 'project_closed', $state->current_baseline_id, $state->current_baseline_id, $reason);
            $this->freeze($actor, $project, $p, $event);
            DB::table('lifecycle_project_state')->where('project_id', $project)->update(['is_closed' => true]);
            $this->audit->record($actor->id, ['tenant_id' => $actor->tenantId, 'project_id' => $project], 'baseline.closed', ['type' => 'baselines', 'id' => $state->current_baseline_id], 1, 'approved_change');

            return ['id' => $state->current_baseline_id, 'status' => 'closed'];
        });

        return $r['result'] + ['generation' => $r['generation']];
    }

    private function freeze(TrustedActor $actor, string $project, array $p, string $event): void
    {
        if (DB::table('baseline_progress_snapshots')->where('project_id', $project)->where('baseline_id', $p['baseline_id'])->exists()) {
            return;
        }
        // Viewer redaction must never become immutable project history; recheck at the write boundary.
        $this->evidence->sourceAccess($actor, $project);
        if (in_array('source_access_unavailable', $p['coverage_reason_codes'], true)) {
            throw new AccessDeniedHttpException;
        }

        $versions = DB::table('baseline_items')->where('project_id', $project)->where('baseline_id', $p['baseline_id'])->orderBy('stable_criterion_id')->get(['stable_criterion_id', 'criterion_version_id', 'weight'])->map(fn ($v) => (array) $v)->all();
        DB::table('baseline_progress_snapshots')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'baseline_id' => $p['baseline_id'], 'policy_id' => $p['policy_id'], 'accepted_weight' => $p['accepted_weight'], 'total_weight' => $p['total_weight'], 'availability' => $p['status'], 'versions' => AcceptancePins::json($versions), 'coverage' => AcceptancePins::json($p['coverage_reason_codes']), 'schedule_coverage' => AcceptancePins::json($p['schedule_coverage']), 'closure_event_id' => $event]);
    }

    private function validate(array $items, string $policy, ?string $current, string $reason): void
    {
        if (! array_is_list($items) || count($items) > 100 || ! Str::isUuid($policy) || ($current !== null && ! Str::isUuid($current)) || ! trim($reason) || mb_strlen($reason) > 500 || str_contains($reason, "\0")) {
            $this->invalid();
        }
        foreach ($items as $i) {
            if (! is_array($i) || array_diff(array_keys($i), ['criterion_version_id', 'weight', 'carry_decision_id']) || ! is_string($i['criterion_version_id'] ?? null) || ! Str::isUuid($i['criterion_version_id']) || ! is_int($i['weight'] ?? null) || $i['weight'] < 1 || $i['weight'] > 1000000 || (isset($i['carry_decision_id']) && (! is_string($i['carry_decision_id']) || ! Str::isUuid($i['carry_decision_id'])))) {
                $this->invalid();
            }
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['baseline' => 'Invalid baseline.']);
    }
}
