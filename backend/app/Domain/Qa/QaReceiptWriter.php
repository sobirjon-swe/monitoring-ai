<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class QaReceiptWriter
{
    public const OPERATIONS = ['build', 'environment', 'definition', 'execution', 'defect.report', 'handoff.open', 'plan.entry', 'plan.compose'];

    public const OUTPUTS = ['build' => ['receipt_id'], 'environment' => ['receipt_id'], 'definition' => ['receipt_id'], 'execution' => ['receipt_id'], 'defect.report' => ['receipt_id', 'defect_id'], 'handoff.open' => ['receipt_id', 'handoff_id'], 'plan.entry' => ['entry_id'], 'plan.compose' => ['plan_id']];

    public function recordLocked(TrustedActor $actor, string $project, array $envelope, string $key, int $expectedSourceRevision, array $proof): array
    {
        $this->locked();
        app(QaContract::class)->validate('envelope', $envelope);
        $hash = hash('sha256', QaContract::canonical($envelope));
        $operation = match ($envelope['operation']) {
            'defect' => 'defect.report', 'handoff' => 'handoff.open', default => $envelope['operation']
        };
        $request = $this->requestLocked($actor, $project, $operation, $key, $hash);
        $source = ['tenant_id' => $actor->tenantId, 'project_id' => $project, 'source_instance_id' => $envelope['source_instance_id'], 'object_id' => $envelope['object_id']];
        $existing = DB::table('qa_receipts')->where($source)->where('source_revision', $envelope['source_revision'])->first();
        if ($existing) {
            if ($existing->payload_hash !== $hash || $existing->kind !== $envelope['operation']) {
                throw new ConflictHttpException;
            }
            $head = DB::table('qa_source_heads')->where($source)->first();
            $table = ['build' => 'qa_build_versions', 'environment' => 'qa_environment_versions', 'definition' => 'qa_definition_versions', 'execution' => 'qa_execution_versions', 'defect' => 'qa_defects', 'handoff' => 'qa_handoffs'][$existing->kind];

            return ['receipt_id' => $existing->id, 'record_id' => DB::table($table)->where('receipt_id', $existing->id)->value('id'), 'source_head_revision' => $head?->revision ?? 0, 'current' => $head?->receipt_id === $existing->id, 'replayed' => true, 'input_hash' => $hash];
        }
        if ($request !== null) {
            throw new ConflictHttpException;
        }
        $head = DB::table('qa_source_heads')->where($source)->lockForUpdate()->first();
        if (($head?->revision ?? 0) !== $expectedSourceRevision) {
            throw new ConflictHttpException;
        }
        $this->admit($project);
        if (($proof['version'] ?? '') !== 'qa-authority-v1' || ($proof['actor_id'] ?? '') !== $actor->id || ($proof['project_id'] ?? '') !== $project || ($proof['tenant_id'] ?? '') !== $actor->tenantId) {
            throw new LogicException('Invalid receipt authority.');
        }
        $id = (string) Str::uuid();
        DB::table('qa_receipts')->insert(['id' => $id] + $source + ['source_revision' => $envelope['source_revision'], 'kind' => $envelope['operation'], 'payload_hash' => $hash, 'envelope' => QaContract::canonical($envelope), 'actor_id' => $actor->id, 'authority_proof' => QaContract::canonical($proof), 'occurred_at' => $envelope['occurred_at'], 'source_updated_at' => $envelope['source_updated_at']]);
        $current = ! $head || $envelope['source_revision'] > $head->source_revision;
        $revision = $head?->revision ?? 0;
        if ($current) {
            $revision++;
            $values = ['source_revision' => $envelope['source_revision'], 'receipt_id' => $id, 'revision' => $revision];
            if ($head) {
                DB::table('qa_source_heads')->where('id', $head->id)->update($values);
            } else {
                DB::table('qa_source_heads')->insert(['id' => (string) Str::uuid()] + $source + $values);
            }
        }

        return ['receipt_id' => $id, 'record_id' => null, 'source_head_revision' => $revision, 'current' => $current, 'replayed' => false, 'input_hash' => $hash];
    }

    public function requestLocked(TrustedActor $actor, string $project, string $operation, string $key, string $payloadHash): ?array
    {
        $this->locked();
        $this->arguments($operation, $key, $payloadHash);
        $row = DB::table('qa_request_keys')->where(['tenant_id' => $actor->tenantId, 'project_id' => $project, 'requester_id' => $actor->id, 'operation' => $operation, 'key' => $key])->first();
        if (! $row) {
            return null;
        }
        if ($row->payload_hash !== $payloadHash) {
            throw new ConflictHttpException;
        }

        return array_filter(array_intersect_key((array) $row, array_flip(['receipt_id', 'entry_id', 'plan_id', 'defect_id', 'handoff_id'])), fn ($v) => $v !== null);
    }

    public function bindRequestLocked(TrustedActor $actor, string $project, string $operation, string $key, string $payloadHash, array $typedOutput): void
    {
        $this->locked();
        $this->arguments($operation, $key, $payloadHash);
        if (count($typedOutput) !== 1 || array_diff(array_keys($typedOutput), self::OUTPUTS[$operation]) || ! Str::isUuid(array_values($typedOutput)[0])) {
            throw new InvalidArgumentException('Invalid request output.');
        }
        $old = $this->requestLocked($actor, $project, $operation, $key, $payloadHash);
        if ($old !== null) {
            if ($old !== $typedOutput) {
                throw new ConflictHttpException;
            }

            return;
        }
        DB::table('qa_request_keys')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'requester_id' => $actor->id, 'operation' => $operation, 'key' => $key, 'payload_hash' => $payloadHash] + $typedOutput);
    }

    public function admit(string $project): void
    {
        if (DB::table('qa_outbox')->where('project_id', $project)->where('is_catchup', false)->whereIn('status', ['pending', 'processing', 'retry'])->count() >= 100) {
            throw new ServiceUnavailableHttpException;
        }
    }

    private function arguments(string $operation, string $key, string $hash): void
    {
        if (! in_array($operation, self::OPERATIONS, true) || ! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $key) || ! preg_match('/\A[0-9a-f]{64}\z/D', $hash)) {
            throw new InvalidArgumentException('Invalid QA request identity.');
        }
    }

    private function locked(): void
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.qa_authority_closed',true),'')") !== '1') {
            throw new LogicException('QA receipt requires coordination.');
        }
    }
}
