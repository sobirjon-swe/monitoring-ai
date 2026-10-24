<?php

namespace App\Domain\Management;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ManagementReviewService
{
    public function __construct(private readonly ManagementReceiptWriter $writer) {}

    public function review(TrustedActor $actor, string $project, string $action, array $body, string $key): array
    {
        $op = $body['operation'] ?? '';
        if (! in_array($op, ['outcome.confirm', 'outcome.reject', 'outcome.revoke'], true)) {
            throw new InvalidArgumentException;
        }
        $body = app(ManagementContract::class)->validate($op, $body);
        $wire = $body + ['action_id' => $action];
        $department = app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $body) {
            $employee = DB::table('users')->where('id', $actor->id)->value('employee_id');
            $pm = DB::table('role_assignments')->where('project_id', $project)->where('employee_id', $employee)->where('role', 'pm')->whereRaw('valid_from<=clock_timestamp() AND (valid_to IS NULL OR valid_to>clock_timestamp())')->exists();

            return $pm ? null : DB::table('management_assignments')->where('project_id', $project)->where('id', $body['assignment_id'])->value('department_id');
        });
        $purposes = ['management.outcome.review', 'management.source.read', 'requirements.read', 'progress.read'];
        if ($department !== null) {
            $purposes[] = 'management.department.manage';
        }

        return $this->writer->run($actor, $project, $action, $op, $wire, $key, $purposes, [], [], $department, function ($proof, $graph, $rows, $evaluation) use ($actor, $project, $action, $wire, $body, $key, $op) {
            $ticket = $this->writer->beginHumanLocked($actor, $project, $op, $wire, $key, $proof);
            if ($ticket['replayed']) {
                return $this->writer->acknowledge($ticket, $evaluation);
            }
            $root = $this->writer->root($project, $action);
            if ($root['state'] !== ($op === 'outcome.revoke' ? 'reviewed' : 'awaiting_review') || $root['current_support_id'] !== $body['support_id']) {
                throw new ConflictHttpException;
            }
            $support = $rows['management_support_versions'][$body['support_id']] ?? null;
            foreach (['action_version_id', 'action_hash', 'assignment_id', 'assignment_hash', 'support_hash', 'references_hash'] as $field) {
                if (! $support || $support[$field] !== $body[$field]) {
                    throw new ConflictHttpException;
                }
            }
            if (in_array($proof['employee_id'], [$support['helper_id'], $support['recorder_id']], true)) {
                throw new AccessDeniedHttpException;
            }
            if ($op === 'outcome.confirm' && $evaluation['applicability'] !== 'current') {
                throw new ConflictHttpException;
            }
            if ($op === 'outcome.revoke' && (($root['current_review_id'] ?? null) !== $body['predecessor_review_id'] || $root['review_status'] !== 'confirmed')) {
                throw new ConflictHttpException;
            }
            $review = array_intersect_key($body, array_flip(['action_version_id', 'action_hash', 'assignment_id', 'assignment_hash', 'support_id', 'support_hash', 'references_hash', 'reason', 'predecessor_review_id']));
            DB::table('management_reviews')->insert(['id' => $ticket['output']['review_id'], 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'action_id' => $action, 'operation' => $op, 'reviewer_id' => $proof['employee_id'], 'review_hash' => $ticket['input_hash'], 'actor_id' => $actor->id, 'authority_proof' => ManagementContract::canonical($proof), 'receipt_id' => $ticket['receipt_id']] + $review);
            $confirmed = $op === 'outcome.confirm';
            $rejected = $op === 'outcome.reject';
            $projection = ['state' => $confirmed ? 'reviewed' : ($rejected ? 'assigned' : 'awaiting_review'), 'support_status' => $rejected ? 'none' : 'recorded', 'review_status' => $confirmed ? 'confirmed' : ($rejected ? 'rejected' : 'revoked'), 'current_outcome' => $confirmed ? $support['outcome'] : null, 'current_support_id' => $rejected ? null : $support['id'], 'current_review_id' => $ticket['output']['review_id']];
            $this->writer->event($ticket, $root, $projection, $proof, ['reason' => $body['reason'], 'subject_support_id' => $rejected ? $support['id'] : null]);
            $evaluation['current_outcome'] = $projection['current_outcome'];

            return $this->writer->acknowledge($ticket, $evaluation);
        });
    }
}
