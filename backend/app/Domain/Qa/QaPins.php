<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class QaPins
{
    // Sixteen typed slots per at most 2000 selected definitions; callers retain their smaller route bounds.
    public const MAX_REFERENCE_ROWS = 2000 * 16;

    public const TABLES = ['receipt' => 'qa_receipts', 'build' => 'qa_build_versions', 'environment' => 'qa_environment_versions', 'definition' => 'qa_definition_versions', 'entry' => 'qa_plan_entry_snapshots', 'plan' => 'qa_plan_versions', 'selection' => 'qa_selection_events', 'execution' => 'qa_execution_versions', 'review' => 'qa_reviews', 'defect' => 'qa_defects', 'defect_event' => 'qa_defect_events', 'handoff' => 'qa_handoffs', 'handoff_event' => 'qa_handoff_events', 'criterion_version' => 'criterion_versions', 'task_version' => 'task_versions', 'baseline' => 'baselines'];

    public function collect(TrustedActor $actor, string $project, array $subjects): array
    {
        $this->normalize($subjects);

        return app(TenantContext::class)->run($actor->tenantId, function () use ($project, $subjects) {
            $rows = $this->rows($project, $subjects, false);
            $requests = [];
            $receiptIds = [];
            foreach ($rows as $group) {
                foreach ($group as $row) {
                    if (isset($row->receipt_id)) {
                        $receiptIds[] = $row->receipt_id;
                    }
                }
            }
            $receipts = DB::table('qa_receipts')->where('project_id', $project)->whereIn('id', array_values(array_unique($receiptIds)))->get()->keyBy('id');
            foreach ($rows as $kind => $group) {
                foreach ($group as $row) {
                    $proofs = [];
                    if (isset($row->authority_proof)) {
                        $proofs[] = json_decode($row->authority_proof, true);
                    }
                    if (isset($row->receipt_id)) {
                        $receipt = $receipts->get($row->receipt_id);
                        if ($receipt) {
                            $proofs[] = json_decode($receipt->authority_proof, true);
                        }
                    }
                    foreach ($proofs as $i => $proof) {
                        $requests[] = ['key' => $kind.':'.$row->id.':'.$i, 'actor_id' => $proof['actor_id'], 'purposes' => $proof['purposes'], 'pinned_proof' => $proof, 'required' => false];
                    }
                }
            }

            return ['requests' => $requests, 'refs' => $subjects, 'input_hash' => hash('sha256', QaContract::canonical($subjects))];
        });
    }

    public function loadLocked(string $project, array $refs): array
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.qa_authority_closed',true),'')") !== '1') {
            throw new LogicException('QA pins require coordination.');
        }
        $this->normalize($refs);

        return $this->rows($project, $refs, true);
    }

    private function normalize(array $refs): void
    {
        if (array_diff(array_keys($refs), array_keys(self::TABLES))) {
            throw new InvalidArgumentException('Invalid QA pins.');
        }
        $count = 0;
        foreach ($refs as $ids) {
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) !== count(array_unique($ids))) {
                throw new InvalidArgumentException('Invalid QA pins.');
            }
            foreach ($ids as $id) {
                if (! is_string($id) || ! Str::isUuid($id)) {
                    throw new InvalidArgumentException('Invalid QA pin.');
                }
            }
            $count += count($ids);
        }
        if ($count > self::MAX_REFERENCE_ROWS) {
            throw new InvalidArgumentException('QA pin graph exceeds supported slots.');
        }
    }

    private function rows(string $project, array $refs, bool $lock): array
    {
        $result = [];
        foreach (self::TABLES as $kind => $table) {
            if (! isset($refs[$kind])) {
                continue;
            }
            $q = DB::table($table)->where('project_id', $project)->whereIn('id', $refs[$kind])->orderBy('id');
            // The project lock protects QA pointers. Immutable facts deliberately have no UPDATE privilege.
            $rows = ($lock && in_array($kind, ['definition', 'plan', 'defect', 'handoff'], true) ? $q->sharedLock() : $q)->get();
            if ($rows->count() !== count($refs[$kind])) {
                throw new NotFoundHttpException;
            }
            $result[$kind] = $rows->keyBy('id')->all();
        }

        return $result;
    }
}
