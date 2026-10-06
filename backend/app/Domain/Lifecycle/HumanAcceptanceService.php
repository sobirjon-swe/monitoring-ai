<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class HumanAcceptanceService
{
    public function __construct(private readonly ProjectCoordination $coordination, private readonly CurrentInputEligibility $eligibility, private readonly LifecycleChangeWriter $changes, private readonly AuditWriter $audit, private readonly AcceptanceAuthority $authority) {}

    public function accept(TrustedActor $actor, string $project, string $baselineId, string $criterionVersionId, array $reviewedLinkIds, int $expectedGeneration, string $reason): array
    {
        if (! array_is_list($reviewedLinkIds) || count($reviewedLinkIds) > 100 || count(array_unique($reviewedLinkIds, SORT_REGULAR)) !== count($reviewedLinkIds)) {
            throw ValidationException::withMessages(['acceptance' => 'Invalid acceptance.']);
        }
        foreach ($reviewedLinkIds as $link) {
            if (! is_string($link) || ! Str::isUuid($link)) {
                throw ValidationException::withMessages(['acceptance' => 'Invalid acceptance.']);
            }
        }
        if (! Str::isUuid($baselineId) || ! Str::isUuid($criterionVersionId) || ! trim($reason) || mb_strlen($reason) > 500 || str_contains($reason, "\0")) {
            throw ValidationException::withMessages(['acceptance' => 'Invalid acceptance.']);
        }
        $r = $this->coordination->run($actor, $project, 'acceptance.review', $expectedGeneration, function () use ($actor, $project, $baselineId, $criterionVersionId, $reviewedLinkIds, $expectedGeneration, $reason) {
            $state = DB::table('lifecycle_project_state')->where('project_id', $project)->first();
            if ($state->current_baseline_id !== $baselineId) {
                throw new ConflictHttpException;
            }
            $item = DB::table('baseline_items')->where('project_id', $project)->where('baseline_id', $baselineId)->where('criterion_version_id', $criterionVersionId)->first();
            if (! $item || $item->policy_id !== $state->current_dod_policy_id) {
                throw new ConflictHttpException;
            }
            $e = $this->eligibility->check($actor, $project, $criterionVersionId, $item->policy_id, $reviewedLinkIds);
            if ($e['status'] !== 'valid') {
                throw new ConflictHttpException;
            }
            $id = (string) Str::uuid();
            $json = AcceptancePins::json($e['pins']);
            $hash = hash('sha256', $json);
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
            $authority = AcceptancePins::json($this->authority->capture($actor, $project));
            DB::table('human_acceptance_decisions')->insert(['id' => $id, 'baseline_id' => $baselineId, 'item_id' => $item->id, 'policy_id' => $item->policy_id, 'criterion_version_id' => $criterionVersionId, 'pins' => $json, 'pins_hash' => $hash, 'authority' => $authority, 'authority_hash' => hash('sha256', $authority), 'input_generation' => $expectedGeneration, 'actor_id' => $actor->id, 'reason' => $reason] + $scope);
            DB::table('acceptance_applicability')->insert(['decision_id' => $id, 'status' => 'valid', 'input_generation' => $expectedGeneration + 1, 'pins_hash' => $hash] + $scope);
            $this->changes->record($actor, $project, 'acceptance.review', 'acceptance_changed', $item->stable_criterion_id, $id, $reason);
            $this->audit->record($actor->id, $scope, 'acceptance.recorded', ['type' => 'human_acceptance_decisions', 'id' => $id], 1, 'approved_change');

            return ['id' => $id, 'baseline_id' => $baselineId, 'criterion_version_id' => $criterionVersionId, 'pins_hash' => $hash, 'status' => 'accepted'];
        });

        return $r['result'] + ['generation' => $r['generation']];
    }
}
