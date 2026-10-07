<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class QaDefinitionService
{
    public function review(TrustedActor $actor, string $project, string $definition, array $decision): array
    {
        $decision = app(QaContract::class)->validate('definition.review', $decision);
        // This new CAS decision does not inherit or consume a previous review decision.
        $graph = app(QaPlanService::class)->collectPins($actor, $project, ['definition' => [$definition]], includeCurrentReviews: false);

        return app(QaCoordination::class)->run($actor, $project, ['qa.plan.manage', 'qa.source.read'], true, function ($proofs) use ($actor, $project, $definition, $decision, $graph) {
            $plans = app(QaPlanService::class);
            $rows = $plans->loadGraphLocked($project, $graph);
            $plans->assertOriginalsLocked($graph, $proofs);
            $plans->assertCurrentLocked($project, $rows, false);
            $row = $rows['definition'][$definition];
            if ($row->review_revision !== $decision['expected_revision']) {
                throw new ConflictHttpException;
            }
            app(QaReceiptWriter::class)->admit($project);
            $id = (string) Str::uuid();
            $revision = $row->review_revision + 1;
            DB::table('qa_reviews')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'definition_id' => $definition, 'revision' => $revision, 'decision' => $decision['decision'], 'subject_hash' => $row->content_hash, 'actor_id' => $actor->id, 'authority_proof' => QaContract::canonical($proofs['current']['proof']), 'reason' => $decision['reason']]);
            DB::table('qa_definition_versions')->where('id', $definition)->update(['review_revision' => $revision, 'current_review_id' => $id, 'review_state' => $decision['decision']]);
            app(QaChangeWriter::class)->appendLocked($actor, $project, 'definition_reviewed', ['review_id' => $id], hash('sha256', QaContract::canonical(['definition_id' => $definition, 'subject_hash' => $row->content_hash] + $decision)), $proofs['current']['proof']);

            return ['id' => $id, 'revision' => $revision, 'state' => $decision['decision'], 'synthetic' => true];
        }, $graph['requests']);
    }
}
