<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class QaSelectionService
{
    public function select(TrustedActor $actor, string $project, array $selection): array
    {
        $selection = app(QaContract::class)->validate('selection.select', $selection);
        $plans = app(QaPlanService::class);
        $graph = $plans->collectPins($actor, $project, ['plan' => [$selection['plan_version_id']], 'build' => [$selection['build_id']], 'environment' => [$selection['environment_version_id']]]);

        return app(QaCoordination::class)->run($actor, $project, ['qa.plan.manage', 'qa.read', 'qa.source.read'], true, function ($proofs) use ($actor, $project, $selection, $plans, $graph) {
            $rows = $plans->loadGraphLocked($project, $graph);
            $plans->assertOriginalsLocked($graph, $proofs);
            $plans->assertCurrentLocked($project, $rows);
            $plan = $rows['plan'][$selection['plan_version_id']];
            $plans->planDtoLocked($project, $plan, $rows);
            $review = $rows['review'][$plan->current_review_id] ?? null;
            if ($plan->review_state !== 'approved' || ! $review || $review->plan_id !== $plan->id || $review->decision !== 'approved' || $review->subject_hash !== $plan->content_hash) {
                throw new ConflictHttpException;
            }
            $state = DB::table('qa_project_state')->where('project_id', $project)->first();
            if ($state->selection_revision !== $selection['expected_revision']) {
                throw new ConflictHttpException;
            }
            app(QaReceiptWriter::class)->admit($project);
            $id = (string) Str::uuid();
            $revision = $state->selection_revision + 1;
            DB::table('qa_selection_events')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'revision' => $revision, 'plan_id' => $plan->id, 'plan_review_id' => $review->id, 'build_id' => $selection['build_id'], 'environment_id' => $selection['environment_version_id'], 'actor_id' => $actor->id, 'authority_proof' => QaContract::canonical($proofs['current']['proof']), 'reason' => $selection['reason']]);
            DB::table('qa_project_state')->where('project_id', $project)->update(['selection_revision' => $revision, 'current_selection_id' => $id]);
            app(QaChangeWriter::class)->appendLocked($actor, $project, 'selection_changed', ['selection_id' => $id], hash('sha256', QaContract::canonical(['plan_review_id' => $review->id, 'plan_hash' => $plan->content_hash] + $selection)), $proofs['current']['proof']);

            return ['id' => $id, 'revision' => $revision, 'state' => 'selected', 'synthetic' => true];
        }, $graph['requests']);
    }
}
