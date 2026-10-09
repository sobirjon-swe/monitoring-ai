<?php

namespace App\Domain\Ba;

use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BaAnswerService
{
    public function record(TrustedActor $actor, string $project, string $clarification, array $envelope, string $key, int $expectedSourceRevision): array
    {
        $envelope = app(BaContract::class)->validate('envelope', $envelope);
        if (! in_array($envelope['operation'], ['answer.record', 'answer.correct', 'answer.retract'], true) || $envelope['body']['clarification_id'] !== $clarification || $expectedSourceRevision < 0) {
            throw new InvalidArgumentException;
        }
        $workflow = app(BaClarificationService::class);
        $body = $envelope['body'];
        $extra = [];
        if (isset($body['author_party_version_id'])) {
            $extra['party'] = [$body['author_party_version_id']];
        }
        if ($body['predecessor_answer_version_id'] !== null) {
            $extra['answer'] = [$body['predecessor_answer_version_id']];
        }
        $graph = $workflow->graph($actor, $project, $clarification, $envelope['operation'], $key, $envelope, extra: $extra);

        return $workflow->execute($actor, $project, ['ba.answer.record', 'ba.source.read'], $graph, function ($proofs, $graph) use ($workflow, $actor, $project, $clarification, $envelope, $body, $key, $expectedSourceRevision) {
            $root = $graph['rows']['clarification'][$clarification];
            $facts = $workflow->replayFacts($graph) ?: ['request:'.$root->current_request_id];
            if (! $workflow->authorizeFacts($project, $graph, $proofs, $facts)) {
                return ['denied' => true];
            }
            $proof = $workflow->sourceProof($proofs);
            $writer = app(BaReceiptWriter::class);
            $ticket = $writer->beginSourceLocked($actor, $project, $envelope, $key, $expectedSourceRevision, $proof);
            if ($ticket['replayed']) {
                return $workflow->replay($ticket);
            }
            $request = $graph['rows']['request'][$root->current_request_id];
            if ($body['expected_revision'] !== $root->revision || $body['request_version_id'] !== $request->id || $body['request_hash'] !== $request->request_hash || $body['links_hash'] !== $request->links_hash) {
                throw new ConflictHttpException;
            }
            $allowed = $envelope['operation'] === 'answer.record' ? ['open', 'answered'] : ['answered', 'resolved'];
            if (! in_array($root->state, $allowed, true)) {
                throw new ConflictHttpException;
            }
            if ($body['predecessor_answer_version_id'] !== null) {
                $previous = $graph['rows']['answer'][$body['predecessor_answer_version_id']] ?? null;
                if (! $previous || $previous->source_instance_id !== $envelope['source_instance_id'] || $previous->object_id !== $envelope['object_id'] || $previous->clarification_id !== $clarification || $previous->source_revision >= $envelope['source_revision'] || ($ticket['current'] && $root->current_answer_id !== $previous->id)) {
                    throw new ConflictHttpException;
                }
            }
            $id = (string) Str::uuid();
            $data = $body;
            unset($data['expected_revision']);
            DB::table('ba_answer_versions')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'receipt_id' => $ticket['receipt_id'], 'source_instance_id' => $envelope['source_instance_id'], 'object_id' => $envelope['object_id'], 'source_revision' => $envelope['source_revision'], 'operation' => $envelope['operation'], 'answer_hash' => hash('sha256', BaContract::canonical($body)), 'actor_id' => $actor->id, 'authority_proof' => BaContract::canonical($proof)] + $data);
            // A late source version is durable history; it cannot replace the newest answer.
            $currentAnswer = $ticket['current'] ? $id : $root->current_answer_id;
            $currentReview = $ticket['current'] ? null : $root->current_review_id;
            $state = $ticket['current'] ? ($envelope['operation'] === 'answer.retract' ? 'open' : 'answered') : $root->state;
            $event = $workflow->appendEvent($actor, $project, $root, $ticket, $proof, $state, $root->current_request_id, $currentAnswer, $currentReview, subjectAnswer: $id);
            $output = ['receipt_id' => $ticket['receipt_id'], 'clarification_id' => $clarification, 'answer_version_id' => $id, 'event_id' => $event];
            $writer->bindOutputLocked($ticket, $output);

            return $workflow->result($output, false);
        });
    }
}
