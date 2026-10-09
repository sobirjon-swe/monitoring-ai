<?php

namespace App\Domain\Ba;

use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BaReceiptWriter
{
    public const SOURCE_OPERATIONS = ['party.register', 'request.open', 'answer.record', 'answer.correct', 'answer.retract'];

    public const HUMAN_OPERATIONS = ['request.revise', 'request.reopen', 'request.withdraw', 'context.add', 'review.confirm', 'review.reject', 'review.revoke'];

    public const OUTPUT_TABLES = ['receipt_id' => 'ba_receipts', 'party_version_id' => 'ba_source_party_versions', 'clarification_id' => 'ba_clarifications', 'request_version_id' => 'ba_request_versions', 'answer_version_id' => 'ba_answer_versions', 'review_id' => 'ba_reviews', 'event_id' => 'ba_events'];

    public function beginSourceLocked(TrustedActor $actor, string $project, array $envelope, string $key, int $expectedSourceRevision, array $proof): array
    {
        $this->locked();
        $envelope = app(BaContract::class)->validate('envelope', $envelope);
        $operation = $envelope['operation'];
        $this->arguments($operation, $key);
        if ($expectedSourceRevision < 0 || ($proof['actor_id'] ?? '') !== $actor->id || ($proof['tenant_id'] ?? '') !== $actor->tenantId || ($proof['project_id'] ?? '') !== $project || ($proof['version'] ?? '') !== 'ba-authority-v1' || ! collect($proof['sources'] ?? [])->contains('id', $envelope['source_instance_id'])) {
            throw new AccessDeniedHttpException;
        }
        $this->operationProof($operation, $proof);
        $captured = $this->proofs();
        $expectedProof = $captured['current']['proof'] ?? [];
        $expectedProof['sources'] = $captured['sources']['proof']['sources'] ?? ($expectedProof['sources'] ?? []);
        if (BaContract::canonical($expectedProof) !== BaContract::canonical($proof)) {
            throw new AccessDeniedHttpException;
        }
        $hash = hash('sha256', BaContract::canonical($envelope));
        $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
        $family = explode('.', $operation)[0];
        $series = $scope + ['source_instance_id' => $envelope['source_instance_id'], 'family' => $family, 'object_id' => $envelope['object_id']];
        $bound = $this->binding($actor, $project, $operation, $key, $hash);
        $existing = DB::table('ba_receipts')->where($series)->where('source_revision', $envelope['source_revision'])->first();
        $head = DB::table('ba_source_heads')->where($series)->first();
        if ($existing !== null) {
            if ($existing->input_hash !== $hash || $existing->operation !== $operation) {
                throw new ConflictHttpException;
            }
            $output = $bound ?? $this->naturalOutput($existing->id, $project);
            $this->assertReplay($project, $existing, $output);

            return $this->ticket($actor, $project, $operation, $key, $hash, $existing->id, true, $output) + ['source_head_revision' => $head?->revision ?? 0, 'current' => $head?->receipt_id === $existing->id, 'complete' => $head?->receipt_id === $existing->id && $head->complete];
        }
        if ($bound !== null || ($head?->revision ?? 0) !== $expectedSourceRevision) {
            throw new ConflictHttpException;
        }
        // request.open has one stable root; a changed version cannot create a second question.
        if ($operation === 'request.open' && DB::table('ba_clarifications')->where($scope)->where('source_instance_id', $envelope['source_instance_id'])->where('object_id', $envelope['object_id'])->exists()) {
            throw new ConflictHttpException;
        }
        $id = (string) Str::uuid();
        DB::table('ba_receipts')->insert(['id' => $id] + $series + ['variant' => 'source', 'operation' => $operation, 'source_revision' => $envelope['source_revision'], 'input_hash' => $hash, 'envelope' => BaContract::canonical($envelope), 'actor_id' => $actor->id, 'authority_proof' => BaContract::canonical($proof), 'occurred_at' => $envelope['occurred_at'], 'source_updated_at' => $envelope['source_updated_at']]);
        $current = ! $head || $envelope['source_revision'] > $head->source_revision;
        $complete = $envelope['source_revision'] === ($head?->source_revision ?? 0) + 1;
        if ($head && in_array($family, ['party', 'answer'], true)) {
            [$field, $table] = $family === 'party' ? ['predecessor_party_version_id', 'ba_source_party_versions'] : ['predecessor_answer_version_id', 'ba_answer_versions'];
            $predecessor = $envelope['body'][$field] ?? null;
            $predecessorReceipt = $predecessor === null ? null : DB::table($table)->where('project_id', $project)->where('id', $predecessor)->value('receipt_id');
            $complete = $complete && $predecessorReceipt === $head->receipt_id;
        }
        $revision = $head?->revision ?? 0;
        if ($current) {
            $revision++;
            $values = ['source_revision' => $envelope['source_revision'], 'receipt_id' => $id, 'revision' => $revision, 'complete' => $complete];
            if ($head) {
                DB::table('ba_source_heads')->where('id', $head->id)->where('revision', $head->revision)->update($values);
            } else {
                DB::table('ba_source_heads')->insert(['id' => (string) Str::uuid()] + $series + $values);
            }
        }

        return $this->ticket($actor, $project, $operation, $key, $hash, $id, false, null) + ['source_head_revision' => $revision, 'current' => $current, 'complete' => $complete];
    }

    public function beginHumanLocked(TrustedActor $actor, string $project, string $operation, array $body, string $key): array
    {
        $this->locked();
        $this->arguments($operation, $key);
        if (! in_array($operation, self::HUMAN_OPERATIONS, true) || ($body['kind'] ?? '') !== $operation) {
            throw new InvalidArgumentException('Invalid BA human operation.');
        }
        $body = app(BaContract::class)->validate($operation, $body);
        $proofs = $this->proofs();
        $proof = $proofs['current']['proof'] ?? [];
        $this->operationProof($operation, $proof);
        $hash = hash('sha256', BaContract::canonical($body));
        $binding = $this->binding($actor, $project, $operation, $key, $hash);
        if ($binding !== null) {
            $receipt = DB::table('ba_receipts')->where('project_id', $project)->where('id', $binding['receipt_id'])->first();
            $this->assertReplay($project, $receipt, $binding);

            return $this->ticket($actor, $project, $operation, $key, $hash, $receipt->id, true, $binding);
        }
        $root = DB::table('ba_clarifications')->where('project_id', $project)->where('id', $body['clarification_id'])->first();
        if (! $root || $root->revision !== $body['expected_revision']) {
            throw new ConflictHttpException;
        }
        $proofs = $this->proofs();
        $proof = $proofs['current']['proof'] ?? null;
        if (! $proof || $proof['actor_id'] !== $actor->id || $proof['project_id'] !== $project || $proof['tenant_id'] !== $actor->tenantId) {
            throw new AccessDeniedHttpException;
        }
        $id = (string) Str::uuid();
        DB::table('ba_receipts')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'variant' => 'human', 'operation' => $operation, 'input_hash' => $hash, 'envelope' => BaContract::canonical($body), 'actor_id' => $actor->id, 'authority_proof' => BaContract::canonical($proof)]);

        return $this->ticket($actor, $project, $operation, $key, $hash, $id, false, null);
    }

    public function bindOutputLocked(array $ticket, array $output): void
    {
        $this->locked();
        $this->arguments($ticket['operation'], $ticket['key']);
        $this->assertOutput($ticket['project_id'], $ticket['operation'], $ticket['receipt_id'], $output);
        if (DB::table('ba_terminal_applicability')->where('project_id', $ticket['project_id'])->whereIn('subject_id', array_values($output))->exists()) {
            throw new AccessDeniedHttpException;
        }
        $actor = new TrustedActor($ticket['actor_id'], $ticket['tenant_id']);
        $old = $this->binding($actor, $ticket['project_id'], $ticket['operation'], $ticket['key'], $ticket['input_hash']);
        if ($old !== null) {
            if (BaContract::canonical($old) !== BaContract::canonical($output)) {
                throw new ConflictHttpException;
            }

            return;
        }
        DB::table('ba_request_bindings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $ticket['tenant_id'], 'project_id' => $ticket['project_id'], 'requester_id' => $ticket['actor_id'], 'operation' => $ticket['operation'], 'key' => $ticket['key'], 'input_hash' => $ticket['input_hash']] + $output);
    }

    public static function outputKeys(string $operation): array
    {
        return match ($operation) {
            'party.register' => ['receipt_id', 'party_version_id'],
            'request.open', 'request.revise', 'request.reopen' => ['receipt_id', 'clarification_id', 'request_version_id', 'event_id'],
            'answer.record', 'answer.correct', 'answer.retract' => ['receipt_id', 'clarification_id', 'answer_version_id', 'event_id'],
            'review.confirm', 'review.reject', 'review.revoke' => ['receipt_id', 'clarification_id', 'review_id', 'event_id'],
            'request.withdraw', 'context.add' => ['receipt_id', 'clarification_id', 'event_id'],
            default => throw new InvalidArgumentException('Invalid BA output operation.'),
        };
    }

    private function assertOutput(string $project, string $operation, string $receipt, array $output): void
    {
        $keys = array_keys($output);
        $expected = self::outputKeys($operation);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected || ($output['receipt_id'] ?? '') !== $receipt) {
            throw new InvalidArgumentException('Invalid BA typed output.');
        }
        $rows = [];
        foreach ($output as $key => $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new InvalidArgumentException('Invalid BA output identifier.');
            }
            $row = DB::table(self::OUTPUT_TABLES[$key])->where('project_id', $project)->where('id', $id)->first();
            if (! $row || (isset($row->receipt_id) && $row->receipt_id !== $receipt) || (isset($row->clarification_id) && $row->clarification_id !== ($output['clarification_id'] ?? null))) {
                throw new ConflictHttpException;
            }
            $rows[$key] = $row;
        }
        if ($rows['receipt_id']->operation !== $operation) {
            throw new ConflictHttpException;
        }
        $event = $rows['event_id'] ?? null;
        foreach (['request_version_id', 'answer_version_id', 'review_id'] as $key) {
            $eventPin = $key === 'answer_version_id' ? ($event?->subject_answer_version_id ?? $event?->answer_version_id) : $event?->{$key};
            if (isset($output[$key]) && $eventPin !== $output[$key]) {
                throw new ConflictHttpException;
            }
        }
    }

    private function assertReplay(string $project, ?object $receipt, array $output): void
    {
        if (! $receipt) {
            throw new ConflictHttpException;
        }
        $this->assertOutput($project, $receipt->operation, $receipt->id, $output);
        $proofs = $this->proofs();
        // Each mandatory saved proof must already have been captured under the open authority fence.
        $original = $proofs['receipt:'.$receipt->id] ?? null;
        $originalKey = 'receipt:'.$receipt->id;
        if ($original === null) {
            // Collector aliases may share only literally identical saved proof copies.
            foreach ($proofs as $key => $candidate) {
                if (! in_array($key, ['current', 'sources'], true) && ($candidate['valid'] ?? false) && isset($candidate['proof']) && $this->proofHash($candidate['proof']) === $this->proofHash(json_decode($receipt->authority_proof, true, 32, JSON_THROW_ON_ERROR))) {
                    $original = $candidate;
                    $originalKey = $key;
                    break;
                }
            }
        }
        if (! ($original['valid'] ?? false) || ! isset($original['proof']) || $this->proofHash($original['proof']) !== $this->proofHash(json_decode($receipt->authority_proof, true, 32, JSON_THROW_ON_ERROR))) {
            throw new AccessDeniedHttpException;
        }
        app(BaAuthority::class)->requireDeliveryLocked([$originalKey]);
        if (DB::table('ba_terminal_applicability')->where('project_id', $project)->whereIn('subject_id', array_values($output))->exists()) {
            throw new AccessDeniedHttpException;
        }
    }

    private function naturalOutput(string $receipt, string $project): array
    {
        $binding = DB::table('ba_request_bindings')->where('project_id', $project)->where('receipt_id', $receipt)->first();
        if (! $binding) {
            throw new ConflictHttpException;
        }

        return $this->output($binding);
    }

    private function binding(TrustedActor $actor, string $project, string $operation, string $key, string $hash): ?array
    {
        $row = DB::table('ba_request_bindings')->where(['tenant_id' => $actor->tenantId, 'project_id' => $project, 'requester_id' => $actor->id, 'operation' => $operation, 'key' => $key])->first();
        if (! $row) {
            return null;
        }
        if ($row->input_hash !== $hash) {
            throw new ConflictHttpException;
        }

        return $this->output($row);
    }

    private function output(object $binding): array
    {
        return array_filter(array_intersect_key((array) $binding, array_flip(self::OUTPUT_TABLES ? array_keys(self::OUTPUT_TABLES) : [])), fn ($value) => $value !== null);
    }

    private function ticket(TrustedActor $actor, string $project, string $operation, string $key, string $hash, string $receipt, bool $replayed, ?array $output): array
    {
        return ['tenant_id' => $actor->tenantId, 'project_id' => $project, 'actor_id' => $actor->id, 'operation' => $operation, 'key' => $key, 'input_hash' => $hash, 'receipt_id' => $receipt, 'replayed' => $replayed, 'output' => $output];
    }

    private function arguments(string $operation, string $key): void
    {
        if (! in_array($operation, [...self::SOURCE_OPERATIONS, ...self::HUMAN_OPERATIONS], true) || ! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $key)) {
            throw new InvalidArgumentException('Invalid BA request identity.');
        }
    }

    private function proofHash(array $proof): string
    {
        unset($proof['captured_at']);

        return hash('sha256', BaContract::canonical($proof));
    }

    private function operationProof(string $operation, array $proof): void
    {
        [$purpose, $role] = match (true) {
            $operation === 'party.register' => ['ba.fixture.manage', null],
            str_starts_with($operation, 'answer.') => ['ba.answer.record', 'ba'],
            str_starts_with($operation, 'review.') => ['ba.decision.confirm', 'pm'],
            default => ['ba.request.manage', 'ba'],
        };
        if (! in_array($purpose, $proof['purposes'] ?? [], true) || ($role !== null && ! collect($proof['roles'] ?? [])->contains('role', $role))) {
            throw new AccessDeniedHttpException;
        }
    }

    private function proofs(): array
    {
        return json_decode(DB::scalar("select current_setting('app.ba_proofs',true)") ?: '{}', true, 32, JSON_THROW_ON_ERROR);
    }

    private function locked(): void
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.ba_authority_closed',true),'')") !== '1') {
            throw new LogicException('BA receipt requires coordination.');
        }
    }
}
