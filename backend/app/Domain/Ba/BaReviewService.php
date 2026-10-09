<?php

namespace App\Domain\Ba;

use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BaReviewService
{
    public function review(TrustedActor $actor, string $project, string $clarification, array $body, string $key): array
    {
        $body = app(BaContract::class)->validate('review', $body);
        if ($body['clarification_id'] !== $clarification) {
            throw new InvalidArgumentException;
        }
        $workflow = app(BaClarificationService::class);
        $graph = $workflow->graph($actor, $project, $clarification, $body['kind'], $key);

        return $workflow->execute($actor, $project, ['ba.decision.confirm'], $graph, function ($proofs, $graph) use ($workflow, $actor, $project, $clarification, $body, $key) {
            $root = $graph['rows']['clarification'][$clarification];
            $facts = $workflow->replayFacts($graph) ?: ['request:'.$root->current_request_id, 'answer:'.($root->current_answer_id ?? '')];
            if (! $workflow->replayFacts($graph) && $body['kind'] === 'review.revoke') {
                $facts[] = 'review:'.($root->current_review_id ?? '');
            }
            if (! $workflow->authorizeFacts($project, $graph, $proofs, $facts)) {
                return ['denied' => true];
            }
            $ticket = app(BaReceiptWriter::class)->beginHumanLocked($actor, $project, $body['kind'], $body, $key);
            if ($ticket['replayed']) {
                return $workflow->replay($ticket);
            }
            $request = $graph['rows']['request'][$root->current_request_id];
            $answer = $graph['rows']['answer'][$root->current_answer_id] ?? null;
            if (! $answer || $body['request_version_id'] !== $request->id || $body['request_hash'] !== $request->request_hash || $body['links_hash'] !== $request->links_hash || $body['answer_version_id'] !== $answer->id || $body['answer_hash'] !== $answer->answer_hash || $root->state !== ($body['kind'] === 'review.revoke' ? 'resolved' : 'answered')) {
                throw new ConflictHttpException;
            }
            $answerProof = json_decode($answer->authority_proof, true, 32, JSON_THROW_ON_ERROR);
            if ($body['kind'] === 'review.confirm') {
                if ($answerProof['employee_id'] === $proofs['current']['proof']['employee_id']) {
                    throw new AccessDeniedHttpException;
                }
                $eligibility = app(BaEligibility::class)->evaluate($graph, $proofs)[$clarification];
                if ($eligibility['applicability'] !== 'current' || $answer->operation === 'answer.retract') {
                    throw new ConflictHttpException;
                }
                app(BaAuthority::class)->requireDeliveryLocked($eligibility['required_proof_keys']);
            }
            if ($body['kind'] === 'review.revoke' && $body['predecessor_review_id'] !== $root->current_review_id) {
                throw new ConflictHttpException;
            }
            $id = (string) Str::uuid();
            $data = $body;
            unset($data['expected_revision']);
            $proof = $proofs['current']['proof'];
            DB::table('ba_reviews')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'receipt_id' => $ticket['receipt_id'], 'actor_id' => $actor->id, 'authority_proof' => BaContract::canonical($proof)] + $data);
            if (($body['disposition'] ?? null) === 'scope_change_required' && ! DB::table('ba_scope_obligations')->where('project_id', $project)->where('clarification_id', $clarification)->exists()) {
                DB::table('ba_scope_obligations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'clarification_id' => $clarification, 'origin_review_id' => $id, 'origin_request_version_id' => $request->id, 'origin_answer_version_id' => $answer->id, 'request_hash' => $request->request_hash, 'links_hash' => $request->links_hash, 'answer_hash' => $answer->answer_hash]);
            }
            $state = match ($body['kind']) {
                'review.confirm' => 'resolved', 'review.reject' => 'open', 'review.revoke' => 'answered'
            };
            $event = $workflow->appendEvent($actor, $project, $root, $ticket, $proof, $state, $request->id, $answer->id, $id);
            $output = ['receipt_id' => $ticket['receipt_id'], 'clarification_id' => $clarification, 'review_id' => $id, 'event_id' => $event];
            app(BaReceiptWriter::class)->bindOutputLocked($ticket, $output);

            return $workflow->result($output, false);
        });
    }
}
