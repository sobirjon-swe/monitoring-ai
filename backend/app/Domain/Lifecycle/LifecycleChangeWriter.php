<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class LifecycleChangeWriter
{
    public const KINDS = ['criterion_replaced', 'criterion_removed', 'policy_activated', 'baseline_activated', 'evidence_changed', 'qa_changed', 'acceptance_changed', 'project_closed'];

    public function record(TrustedActor $actor, string $projectId, string $purpose, string $subjectKind, string $subjectId, string $inputVersion, string $reason): string
    {
        if (! in_array($subjectKind, self::KINDS, true) || ! Str::isUuid($subjectId) || strlen($inputVersion) > 100 || ! trim($inputVersion) || mb_strlen($reason) > 500 || ! trim($reason) || str_contains($reason, "\0")) {
            throw new InvalidArgumentException('Invalid lifecycle change.');
        }
        $binding = DB::scalar("select nullif(current_setting('app.lifecycle_mutation',true),'')");
        if (DB::transactionLevel() === 0 || $binding === null) {
            throw new LogicException('Lifecycle change requires coordination.');
        }
        app(AccessGateway::class)->authorize($actor, $purpose, ['tenant_id' => $actor->tenantId, 'project_id' => $projectId]);
        $state = DB::table('lifecycle_project_state')->where('project_id', $projectId)->first() ?? throw new LogicException('Lifecycle state missing.');
        if ($binding !== json_encode([$actor->tenantId, $projectId, $actor->id, $purpose, $state->generation], JSON_THROW_ON_ERROR)) {
            throw new LogicException('Lifecycle change requires coordination.');
        }
        $key = hash('sha256', json_encode([$subjectKind, $subjectId, $inputVersion]));
        if ($old = DB::table('lifecycle_changes')->where('project_id', $projectId)->where('idempotency_key', $key)->value('id')) {
            return $old;
        }
        $id = (string) Str::uuid();
        DB::table('lifecycle_changes')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $projectId, 'subject_kind' => $subjectKind, 'subject_id' => $subjectId, 'input_version' => $inputVersion, 'reason' => $reason, 'actor_id' => $actor->id, 'authorizing_purpose' => $purpose, 'generation' => $state->generation + 1, 'idempotency_key' => $key]);
        DB::table('lifecycle_outbox')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $actor->tenantId, 'project_id' => $projectId, 'change_id' => $id]);

        return $id;
    }
}
