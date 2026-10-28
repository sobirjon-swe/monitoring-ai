<?php

namespace App\Domain\Browser;

use App\Domain\Access\TrustedActor;
use App\Domain\Ba\BaClarificationService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BrowserBaAuthority
{
    public function check(TrustedActor $actor, string $project, string $clarification, string $key, ?int $expectedRevision = null): void
    {
        $service = app(BaClarificationService::class);
        $graph = $service->graph($actor, $project, $clarification, 'context.add', $key);
        $service->execute($actor, $project, ['ba.request.manage'], $graph, function ($proofs, $graph) use ($service, $project, $clarification, $expectedRevision) {
            $root = $graph['rows']['clarification'][$clarification] ?? null;
            if (! $root) {
                throw new NotFoundHttpException;
            }
            $facts = $service->replayFacts($graph) ?: ['request:'.$root->current_request_id];
            if (! $service->authorizeFacts($project, $graph, $proofs, $facts)) {
                throw new AccessDeniedHttpException;
            }
            if ($expectedRevision !== null && (int) $root->revision !== $expectedRevision) {
                throw new ConflictHttpException;
            }

            return [];
        });
    }
}
