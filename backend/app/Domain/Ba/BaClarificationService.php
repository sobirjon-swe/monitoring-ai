<?php

namespace App\Domain\Ba;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Support\LocalQaDatabase;
use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class BaClarificationService
{
    public function open(TrustedActor $actor, string $project, array $envelope, string $key, int $expectedSourceRevision): array
    {
        $envelope = app(BaContract::class)->validate('envelope', $envelope);
        if ($envelope['operation'] !== 'request.open' || $expectedSourceRevision < 0) {
            throw new InvalidArgumentException;
        }
        $body = $envelope['body'];
        $graph = $this->graph($actor, $project, null, 'request.open', $key, $envelope, $body);

        return $this->execute($actor, $project, ['ba.request.manage', 'ba.source.read'], $graph, function ($proofs, $graph) use ($actor, $project, $envelope, $body, $key, $expectedSourceRevision) {
            if (! $this->authorizeFacts($project, $graph, $proofs, $this->replayFacts($graph))) {
                return ['denied' => true];
            }
            $proof = $this->sourceProof($proofs);
            $writer = app(BaReceiptWriter::class);
            $ticket = $writer->beginSourceLocked($actor, $project, $envelope, $key, $expectedSourceRevision, $proof);
            if ($ticket['replayed']) {
                return $this->replay($ticket);
            }
            $id = (string) Str::uuid();
            DB::table('ba_clarifications')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'source_instance_id' => $envelope['source_instance_id'], 'object_id' => $envelope['object_id'], 'original_requested_at' => $body['requested_at']]);
            $request = $this->insertRequest($actor, $project, $id, $body, $ticket, $proof, $graph, 1, 1);
            $root = DB::table('ba_clarifications')->where('id', $id)->first();
            $event = $this->appendEvent($actor, $project, $root, $ticket, $proof, 'open', $request, null, null);
            $output = ['receipt_id' => $ticket['receipt_id'], 'clarification_id' => $id, 'request_version_id' => $request, 'event_id' => $event];
            $writer->bindOutputLocked($ticket, $output);

            return $this->result($output, false);
        });
    }

    public function event(TrustedActor $actor, string $project, string $clarification, array $body, string $key): array
    {
        $body = app(BaContract::class)->validate('event', $body);
        if ($body['clarification_id'] !== $clarification) {
            throw new InvalidArgumentException;
        }
        $graph = $this->graph($actor, $project, $clarification, $body['kind'], $key, null, $body['request'] ?? null);

        return $this->execute($actor, $project, ['ba.request.manage'], $graph, function ($proofs, $graph) use ($actor, $project, $clarification, $body, $key) {
            $root = $graph['rows']['clarification'][$clarification];
            $facts = $this->replayFacts($graph) ?: ['request:'.$root->current_request_id];
            if (! $this->authorizeFacts($project, $graph, $proofs, $facts)) {
                return ['denied' => true];
            }
            $ticket = app(BaReceiptWriter::class)->beginHumanLocked($actor, $project, $body['kind'], $body, $key);
            if ($ticket['replayed']) {
                return $this->replay($ticket);
            }
            $allowed = match ($body['kind']) {
                'request.revise' => ['open', 'answered', 'resolved'], 'request.reopen' => ['resolved', 'withdrawn'], 'request.withdraw' => ['open', 'answered'], 'context.add' => ['open', 'answered', 'resolved', 'withdrawn']
            };
            if (! in_array($root->state, $allowed, true)) {
                throw new ConflictHttpException;
            }
            $request = $root->current_request_id;
            $answer = $root->current_answer_id;
            $review = $root->current_review_id;
            $state = $root->state;
            if (in_array($body['kind'], ['request.revise', 'request.reopen'], true)) {
                $old = $graph['rows']['request'][$request];
                $request = $this->insertRequest($actor, $project, $clarification, $body['request'], $ticket, $proofs['current']['proof'], $graph, $old->revision + 1, $old->episode + 1);
                $answer = $review = null;
                $state = 'open';
            } elseif ($body['kind'] === 'request.withdraw') {
                $state = 'withdrawn';
            }
            $event = $this->appendEvent($actor, $project, $root, $ticket, $proofs['current']['proof'], $state, $request, $answer, $review, $body['context'] ?? null, $body['reason'] ?? null);
            $output = ['receipt_id' => $ticket['receipt_id'], 'clarification_id' => $clarification, 'event_id' => $event];
            if (isset($body['request'])) {
                $output['request_version_id'] = $request;
            }
            app(BaReceiptWriter::class)->bindOutputLocked($ticket, $output);

            return $this->result($output, false);
        });
    }

    /** Server-only graph preparation; replay outputs are selected before the authority fence. */
    public function graph(TrustedActor $actor, string $project, ?string $root, string $operation, string $key, ?array $envelope = null, ?array $request = null, array $extra = []): array
    {
        app(LocalQaDatabase::class)->assertRuntime();
        if ($request !== null) {
            $request = app(BaContract::class)->validate('request', $request);
            if (count($request['links']) < 1 || count($request['links']) > 20) {
                throw new InvalidArgumentException;
            }
        }
        $refs = $root ? ['clarification' => [$root]] : [];
        $replay = app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $operation, $key, $envelope) {
            $binding = DB::table('ba_request_bindings')->where(['project_id' => $project, 'requester_id' => $actor->id, 'operation' => $operation, 'key' => $key])->first();
            if (! $binding && $envelope) {
                $receipt = DB::table('ba_receipts')->where(['project_id' => $project, 'source_instance_id' => $envelope['source_instance_id'], 'family' => explode('.', $operation)[0], 'object_id' => $envelope['object_id'], 'source_revision' => $envelope['source_revision']])->value('id');
                if ($receipt) {
                    $binding = DB::table('ba_request_bindings')->where('project_id', $project)->where('receipt_id', $receipt)->first();
                }
            }

            return $binding;
        });
        $replayFacts = [];
        if ($replay) {
            foreach (['receipt' => 'receipt_id', 'clarification' => 'clarification_id', 'request' => 'request_version_id', 'answer' => 'answer_version_id', 'review' => 'review_id', 'event' => 'event_id'] as $kind => $field) {
                if ($replay->{$field} !== null) {
                    $refs[$kind][] = $replay->{$field};
                    if ($kind !== 'clarification') {
                        $replayFacts[] = $kind.':'.$replay->{$field};
                    }
                }
            }
            $dependencies = app(TenantContext::class)->run($actor->tenantId, function () use ($project, $replay) {
                $event = $replay->event_id === null ? null : DB::table('ba_events')->where('project_id', $project)->where('id', $replay->event_id)->first(['request_version_id', 'answer_version_id', 'review_id']);

                return $event ? ['request' => $event->request_version_id, 'answer' => $event->answer_version_id, 'review' => $event->review_id] : [];
            });
            foreach ($dependencies as $kind => $id) {
                if ($id !== null) {
                    $refs[$kind][] = $id;
                    $replayFacts[] = $kind.':'.$id;
                }
            }
        }
        if ($request && $request['decision_owner_party_version_id']) {
            $refs['party'][] = $request['decision_owner_party_version_id'];
        }
        foreach ($extra as $kind => $ids) {
            $refs[$kind] = array_merge($refs[$kind] ?? [], $ids);
        }
        foreach ($refs as &$ids) {
            $ids = array_values(array_unique($ids));
        } unset($ids);
        $graph = app(BaPins::class)->collect($actor, $project, $refs, $envelope ? [$envelope['source_instance_id']] : [], $request['links'] ?? [], prospectiveRequest: $request !== null);
        if ($graph['coverage'] !== 'complete') {
            throw new ServiceUnavailableHttpException;
        }
        $graph['replay_facts'] = $replayFacts;
        $graph['coordinator_refs'] = $request ? [$request['coordinator_id']] : [];
        $graph['request_subject_refs'] = $request ? array_values(array_unique(array_filter([$request['requester_id'], $request['decision_owner_id']]))) : [];
        if ($request) {
            $owners = array_values(array_filter([$request['requester_id'], $request['coordinator_id'], $request['decision_owner_id']]));
            $graph['active_subject_refs'] = array_values(array_unique([...$graph['active_subject_refs'], ...$owners]));
            $graph['requests'][] = ['key' => 'owners', 'actor_id' => $actor->id, 'purposes' => ['ba.request.manage'], 'required' => true, 'owner_refs' => array_values(array_unique($owners))];
        }

        return $graph;
    }

    public function execute(TrustedActor $actor, string $project, array $purposes, array $graph, Closure $work): array
    {
        $result = app(BaCoordination::class)->run($actor, $project, $purposes, true, function ($proofs) use ($project, $graph, $work) {
            $loaded = app(BaPins::class)->loadLocked($project, $graph);
            $graph['rows'] = $loaded['rows'];
            $graph['terminal'] = app(BaTerminal::class)->overlayLocked($project, $graph);

            return $work($proofs, $graph);
        }, $graph['requests'], subjectRefs: $graph['active_subject_refs'], coordinatorRefs: $graph['coordinator_refs'], requestSubjectRefs: $graph['request_subject_refs']);
        if (isset($result['denied'])) {
            throw new AccessDeniedHttpException;
        }

        return $result;
    }

    /** Observe only mandatory original invalidity; optional lineage cannot poison fresh facts. */
    public function authorizeFacts(string $project, array $graph, array $proofs, array $facts): bool
    {
        $keys = [];
        $denied = false;
        foreach ($facts as $ref) {
            [$kind, $id] = explode(':', $ref, 2);
            $row = $graph['rows'][$kind][$id] ?? null;
            $key = $graph['proof_keys'][$ref] ?? null;
            if (! $row || ! ($proofs[$key]['valid'] ?? false)) {
                $reason = $proofs[$key]['reason_code'] ?? null;
                if ($row && in_array($reason, ['access_revoked', 'source_revoked', 'role_revoked'], true)) {
                    app(BaTerminal::class)->observeLocked($project, $kind, $id, hash('sha256', $row->authority_proof), $reason);
                }
                $denied = true;

                continue;
            }
            foreach ($graph['terminal'] as $terminal) {
                if ($terminal['subject_id'] === $id || (isset($row->receipt_id) && $terminal['subject_id'] === $row->receipt_id)) {
                    $denied = true;
                }
            }
            $keys[] = $key;
        }
        if (! $denied) {
            app(BaAuthority::class)->requireDeliveryLocked(array_values(array_unique($keys)));
        }

        return ! $denied;
    }

    public function replayFacts(array $graph): array
    {
        return $graph['replay_facts'] ?? [];
    }

    public function sourceProof(array $proofs): array
    {
        $proof = $proofs['current']['proof'];
        $proof['sources'] = $proofs['sources']['proof']['sources'];

        return $proof;
    }

    public function replay(array $ticket): array
    {
        app(BaReceiptWriter::class)->bindOutputLocked($ticket, $ticket['output']);

        return $this->result($ticket['output'], true);
    }

    public function result(array $output, bool $replayed): array
    {
        return $output + ['replayed' => $replayed, 'synthetic' => true, 'ai_interpretation' => 'unavailable', 'customer_authenticity' => 'not_verified'];
    }

    public function appendEvent(TrustedActor $actor, string $project, object $root, array $ticket, array $proof, string $state, string $request, ?string $answer, ?string $review, ?string $context = null, ?string $reason = null, ?string $subjectAnswer = null): string
    {
        $id = (string) Str::uuid();
        $values = ['state' => $state, 'request_version_id' => $request, 'answer_version_id' => $answer, 'review_id' => $review, 'subject_answer_version_id' => $subjectAnswer];
        DB::table('ba_events')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'clarification_id' => $root->id, 'revision' => $root->revision + 1, 'kind' => $ticket['operation'], 'receipt_id' => $ticket['receipt_id'], 'context' => $context, 'reason' => $reason, 'actor_id' => $actor->id, 'authority_proof' => BaContract::canonical($proof)] + $values);
        DB::table('ba_clarifications')->where('project_id', $project)->where('id', $root->id)->where('revision', $root->revision)->update(['revision' => $root->revision + 1, 'current_event_id' => $id, 'state' => $state, 'current_request_id' => $request, 'current_answer_id' => $answer, 'current_review_id' => $review]);
        app(AuditWriter::class)->record($actor->id, ['tenant_id' => $actor->tenantId, 'project_id' => $project], 'ba.event_recorded', ['type' => 'ba_events', 'id' => $id], $root->revision + 1, 'approved_change');

        return $id;
    }

    private function insertRequest(TrustedActor $actor, string $project, string $root, array $body, array $ticket, array $proof, array $graph, int $revision, int $episode): string
    {
        if (array_column($proof['coordinators'] ?? [], 'employee_id') !== [$body['coordinator_id']]) {
            throw new AccessDeniedHttpException;
        }
        $subjects = array_values(array_unique(array_filter([$body['requester_id'], $body['decision_owner_id']])));
        sort($subjects, SORT_STRING);
        if (array_column($proof['request_subjects'] ?? [], 'employee_id') !== $subjects) {
            throw new AccessDeniedHttpException;
        }
        $id = (string) Str::uuid();
        $data = $body;
        unset($data['links']);
        $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project, 'clarification_id' => $root];
        DB::table('ba_request_versions')->insert(['id' => $id, 'revision' => $revision, 'episode' => $episode, 'receipt_id' => $ticket['receipt_id'], 'request_hash' => hash('sha256', BaContract::canonical($body)), 'links_hash' => hash('sha256', BaContract::canonical($body['links'])), 'actor_id' => $actor->id, 'authority_proof' => BaContract::canonical($proof)] + $scope + $data);
        foreach ($body['links'] as $input) {
            $input = (array) $input;
            $link = collect($graph['links'])->first(fn ($candidate) => ! array_diff_assoc($input, $candidate));
            if (! $link) {
                throw new ConflictHttpException;
            }
            DB::table('ba_request_links')->insert(['id' => (string) Str::uuid(), 'request_version_id' => $id] + $scope + array_intersect_key($link, array_flip(['task_version_id', 'criterion_id', 'criterion_version_id', 'relation', 'requirement_version_id', 'requirement_hash'])));
        }

        return $id;
    }
}
