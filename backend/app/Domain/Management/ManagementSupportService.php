<?php

namespace App\Domain\Management;

use App\Domain\Access\TrustedActor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ManagementSupportService
{
    public function __construct(private readonly ManagementReceiptWriter $writer) {}

    public function record(TrustedActor $actor, string $project, string $action, array $envelope, string $key, int $expectedSourceRevision): array
    {
        $envelope = app(ManagementContract::class)->validate('envelope', $envelope);
        $op = $envelope['operation'];
        if (! in_array($op, ['support.record', 'support.correct', 'support.retract', 'support.decline'], true) || $envelope['body']['action_id'] !== $action) {
            throw new InvalidArgumentException;
        }
        $body = $envelope['body'];
        $refs = array_map(fn ($ref) => ['reference' => $ref, 'occurred_at' => $envelope['occurred_at'], 'assignment_id' => $body['assignment_id']], $body['references']);

        return $this->writer->run($actor, $project, $action, $op, $envelope, $key, ['management.support.record', 'management.source.read', 'requirements.read', 'progress.read'], $refs, [], null, function ($proof, $graph, $rows, $evaluation) use ($actor, $project, $action, $envelope, $key, $expectedSourceRevision, $body, $op) {
            $ticket = $this->writer->beginSourceLocked($actor, $project, $envelope, $key, $expectedSourceRevision, $proof);
            if ($ticket['replayed']) {
                return $this->writer->acknowledge($ticket, $evaluation);
            }
            $root = $this->writer->root($project, $action);
            if ($root['revision'] !== $body['expected_revision']) {
                throw new ConflictHttpException;
            }
            $assignment = $rows['management_assignments'][$body['assignment_id']] ?? null;
            $version = $rows['management_action_versions'][$body['action_version_id']] ?? null;
            if (! $assignment || ! $version || $assignment['action_id'] !== $action || $assignment['action_version_id'] !== $version['id'] || $body['action_hash'] !== $version['action_hash'] || $body['assignment_hash'] !== $assignment['assignment_hash']) {
                throw new ConflictHttpException;
            }
            if (! in_array($proof['employee_id'], [$assignment['owner_id'], $assignment['helper_id']], true) || ($op === 'support.decline' && $proof['employee_id'] !== $assignment['helper_id'])) {
                throw new AccessDeniedHttpException;
            }
            $actionRefs = array_values(array_filter($graph['references'], fn ($r) => isset($r['expected_source_snapshot_hash'])));
            foreach ($body['references'] as $reference) {
                if (! array_filter($actionRefs, fn ($r) => $r['task_version_id'] === $reference['task_version_id'] && $r['criterion_id'] === $reference['criterion_id'] && $r['criterion_version_id'] === $reference['criterion_version_id'] && $r['relation'] === $reference['relation'])) {
                    throw new ConflictHttpException;
                }
            }
            $predecessor = $body['predecessor_support_id'] ?? null;
            if ($predecessor !== null) {
                $prior = DB::table('management_support_versions')->where('project_id', $project)->where('id', $predecessor)->first();
                if (! $prior || $prior->action_id !== $action || $prior->assignment_id !== $assignment['id'] || $prior->source_instance_id !== $envelope['source_instance_id'] || $prior->object_id !== $envelope['object_id'] || $prior->source_revision >= $envelope['source_revision']) {
                    throw new ConflictHttpException;
                }
                if ($ticket['current'] && $ticket['complete'] && $root['current_support_id'] !== $predecessor) {
                    throw new ConflictHttpException;
                }
            }
            if ($ticket['current']) {
                $allowed = in_array($op, ['support.record', 'support.decline'], true) ? ['assigned'] : ['awaiting_review', 'reviewed'];
                if (! in_array($root['state'], $allowed, true) || $root['current_assignment_id'] !== $assignment['id'] || $root['current_action_version_id'] !== $version['id']) {
                    throw new ConflictHttpException;
                }
            }
            $refsHash = $this->writer->references($ticket, $body['references'], $graph, 'support_id');
            $fact = array_intersect_key($body, array_flip(['action_id', 'action_version_id', 'action_hash', 'assignment_id', 'assignment_hash', 'text', 'reason', 'outcome', 'predecessor_support_id']));
            DB::table('management_support_versions')->insert(['id' => $ticket['output']['support_id'], 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'operation' => $op, 'helper_id' => $assignment['helper_id'], 'recorder_id' => $proof['employee_id'], 'support_hash' => $ticket['input_hash'], 'references_hash' => $refsHash, 'source_instance_id' => $envelope['source_instance_id'], 'object_id' => $envelope['object_id'], 'source_revision' => $envelope['source_revision'], 'occurred_at' => $envelope['occurred_at'], 'source_updated_at' => $envelope['source_updated_at'], 'actor_id' => $actor->id, 'authority_proof' => ManagementContract::canonical($proof), 'receipt_id' => $ticket['receipt_id']] + $fact);
            $projection = [];
            if ($ticket['current']) {
                $cleared = in_array($op, ['support.retract', 'support.decline'], true);
                $projection = ['state' => $cleared ? 'assigned' : 'awaiting_review', 'support_status' => match ($op) {
                    'support.retract' => 'retracted', 'support.decline' => 'declined', default => 'recorded'
                }, 'review_status' => 'unverified', 'current_outcome' => null, 'current_support_id' => $cleared ? null : $ticket['output']['support_id'], 'current_review_id' => null];
            }
            $this->writer->event($ticket, $root, $projection, $proof, ['subject_support_id' => $ticket['output']['support_id'], 'reason' => $body['reason'] ?? null]);
            $evaluation['current_outcome'] = $ticket['current'] ? null : $evaluation['current_outcome'];
            if ($ticket['current'] && ! $ticket['complete']) {
                $evaluation['applicability'] = 'unknown';
                $evaluation['subject_applicability'] = 'unknown';
                $evaluation['current_applicability'] = 'unknown';
                $evaluation['reason_codes'][] = 'source_gap';
                $evaluation['current_reason_codes'][] = 'source_gap';
            } elseif ($ticket['current']) {
                $evaluation['current_applicability'] = $evaluation['subject_applicability'];
                $evaluation['current_reason_codes'] = $evaluation['reason_codes'];
            } else {
                $evaluation['applicability'] = $evaluation['subject_applicability'] = $evaluation['subject_applicability'] === 'current' ? 'stale' : $evaluation['subject_applicability'];
            }

            return $this->writer->acknowledge($ticket, $evaluation);
        });
    }
}
