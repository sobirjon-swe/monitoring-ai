<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class QaChangeWriter
{
    public const SUBJECTS = ['receipt_id' => 'qa_receipts', 'definition_id' => 'qa_definition_versions', 'entry_id' => 'qa_plan_entry_snapshots', 'plan_id' => 'qa_plan_versions', 'selection_id' => 'qa_selection_events', 'execution_id' => 'qa_execution_versions', 'review_id' => 'qa_reviews', 'defect_event_id' => 'qa_defect_events', 'handoff_event_id' => 'qa_handoff_events'];

    public function appendLocked(TrustedActor $actor, string $project, string $kind, array $subject, string $inputHash, array $proof): string
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.qa_authority_closed',true),'')") !== '1') {
            throw new LogicException('QA change requires coordination.');
        }
        if (count($subject) !== 1 || array_diff(array_keys($subject), array_keys(self::SUBJECTS)) || ! Str::isUuid(array_values($subject)[0]) || ! preg_match('/\A[0-9a-f]{64}\z/D', $inputHash) || ! in_array($kind, ['source_recorded', 'definition_reviewed', 'entry_created', 'plan_created', 'plan_reviewed', 'selection_changed', 'execution_recorded', 'defect_changed', 'handoff_changed'], true)) {
            throw new InvalidArgumentException('Invalid QA change.');
        }
        if (($proof['version'] ?? '') !== 'qa-authority-v1' || ($proof['actor_id'] ?? '') !== $actor->id || ($proof['project_id'] ?? '') !== $project || ($proof['tenant_id'] ?? '') !== $actor->tenantId) {
            throw new LogicException('Invalid QA change authority.');
        }
        app(QaReceiptWriter::class)->admit($project);
        $id = (string) Str::uuid();
        $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
        DB::table('qa_changes')->insert(['id' => $id, 'kind' => $kind, 'input_hash' => $inputHash, 'actor_id' => $actor->id, 'authority_proof' => QaContract::canonical($proof)] + $scope + $subject);
        $seq = DB::table('qa_changes')->where('id', $id)->value('change_seq');
        DB::table('qa_applicability')->insert(['id' => (string) Str::uuid(), 'change_id' => $id, 'change_seq' => $seq] + $scope);
        DB::table('qa_outbox')->insert(['id' => (string) Str::uuid(), 'change_id' => $id] + $scope);
        app(AuditWriter::class)->record($actor->id, $scope, 'qa.'.$kind, ['type' => self::SUBJECTS[array_key_first($subject)], 'id' => array_values($subject)[0]], 1, 'approved_change');

        return $id;
    }
}
