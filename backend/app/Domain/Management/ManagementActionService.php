<?php

namespace App\Domain\Management;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ManagementActionService
{
    public function __construct(private readonly ManagementReceiptWriter $writer) {}

    public function open(TrustedActor $actor, string $project, array $envelope, string $key, int $expectedSourceRevision): array
    {
        return $this->openInternal($actor, $project, $envelope, $key, $expectedSourceRevision, false);
    }

    /** Trusted fixture entry point; its extra grant shares the ordinary final commit fence. */
    public function openFixture(TrustedActor $actor, string $project, array $envelope, string $key, int $expectedSourceRevision): array
    {
        return $this->openInternal($actor, $project, $envelope, $key, $expectedSourceRevision, true);
    }

    private function openInternal(TrustedActor $actor, string $project, array $envelope, string $key, int $expectedSourceRevision, bool $fixture): array
    {
        $envelope = app(ManagementContract::class)->validate('envelope', $envelope);
        if ($envelope['operation'] !== 'action.open') {
            throw new InvalidArgumentException;
        }
        $body = $envelope['body'];
        $refs = array_map(fn ($ref) => ['reference' => $ref, 'occurred_at' => $envelope['occurred_at']], $body['references']);

        $purposes = ['management.action.manage', 'management.source.read', 'requirements.read', 'progress.read'];
        if ($fixture) {
            $purposes[] = 'management.fixture.manage';
        }

        return $this->writer->run($actor, $project, null, 'action.open', $envelope, $key, $purposes, $refs, [$body['owner_id']], null, function ($proof, $graph, $rows, $evaluation) use ($actor, $project, $envelope, $key, $expectedSourceRevision, $body) {
            $ticket = $this->writer->beginSourceLocked($actor, $project, $envelope, $key, $expectedSourceRevision, $proof);
            if (! $ticket['replayed']) {
                $now = DB::scalar('select clock_timestamp()');
                DB::table('management_actions')->insert(['id' => $ticket['output']['action_id'], 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'source_instance_id' => $envelope['source_instance_id'], 'object_id' => $envelope['object_id'], 'original_opened_at' => $envelope['occurred_at'], 'episode_opened_at' => $now]);
                $this->version($ticket, $body, $proof, $graph, 1, 1, $envelope['occurred_at']);
                $root = $this->writer->root($project, $ticket['output']['action_id']);
                $this->writer->event($ticket, $root, ['current_action_version_id' => $ticket['output']['action_version_id']], $proof);
            }

            return $this->writer->acknowledge($ticket, $evaluation);
        });
    }

    public function event(TrustedActor $actor, string $project, string $action, array $body, string $key): array
    {
        $op = $body['operation'] ?? '';
        if (! in_array($op, ['action.revise', 'action.reopen', 'action.withdraw', 'assignment.set', 'context.add'], true)) {
            throw new InvalidArgumentException;
        }
        $body = app(ManagementContract::class)->validate($op, $body);
        $wire = $body + ['action_id' => $action];
        $input = $body['action'] ?? null;
        $prospective = $input['references'] ?? [];
        $named = $input ? [$input['owner_id']] : ($op === 'assignment.set' ? [$body['owner_id'], $body['helper_id']] : []);
        $department = $op === 'assignment.set' ? $body['department_id'] : null;
        $purposes = [$op === 'assignment.set' ? 'management.support.assign' : 'management.action.manage', 'management.source.read', 'requirements.read', 'progress.read'];
        if ($department) {
            $purposes[] = 'management.department.manage';
        }
        if ($op === 'context.add') {
            // An unusable manage grant must not shadow valid recording authority.
            // The selected alternative is captured and time-fenced again below.
            $pmGrant = app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project) {
                $employee = DB::table('users')->where('id', $actor->id)->where('status', 'active')->value('employee_id');
                $roles = DB::table('role_assignments')->where('project_id', $project)->where('employee_id', $employee)->where('role', 'pm')->whereRaw('valid_from<=clock_timestamp() AND (valid_to IS NULL OR valid_to>clock_timestamp())')->limit(2)->pluck('id');
                $grants = DB::table('access_grants as g')->join('policy_versions as p', fn ($q) => $q->on('p.id', '=', 'g.policy_version_id')->on('p.tenant_id', '=', 'g.tenant_id'))->where('g.project_id', $project)->where('g.user_id', $actor->id)->where('g.purpose', 'management.action.manage')->where('g.scope_type', 'project')->whereNull('g.revoked_at')->where('p.status', 'active')->whereRaw('g.valid_from<=clock_timestamp() AND (g.valid_to IS NULL OR g.valid_to>clock_timestamp())')->limit(2)->pluck('g.id');

                return $employee !== null && $roles->count() === 1 && $grants->count() === 1;
            });
            if (! $pmGrant) {
                $purposes[0] = 'management.support.record';
            }
        }

        return $this->writer->run($actor, $project, $action, $op, $wire, $key, $purposes, $prospective, $named, $department, function ($proof, $graph, $rows, $evaluation) use ($actor, $project, $action, $wire, $body, $key, $op, $input, $purposes) {
            $ticket = $this->writer->beginHumanLocked($actor, $project, $op, $wire, $key, $proof);
            if ($ticket['replayed']) {
                return $this->writer->acknowledge($ticket, $evaluation);
            }
            $root = $this->writer->root($project, $action);
            if ($op === 'context.add' && $purposes[0] === 'management.support.record') {
                $version = $rows['management_action_versions'][$root['current_action_version_id']];
                $assignment = $rows['management_assignments'][$root['current_assignment_id'] ?? ''] ?? null;
                if (! in_array($proof['employee_id'], [$version['owner_id'], $assignment['helper_id'] ?? null], true)) {
                    throw new AccessDeniedHttpException;
                }
            }
            $allowed = match ($op) {
                'action.revise' => ['open', 'assigned', 'awaiting_review', 'reviewed'],
                'action.reopen' => ['reviewed', 'withdrawn'],
                'action.withdraw', 'assignment.set' => ['open', 'assigned', 'awaiting_review', 'reviewed'],
                default => ['open', 'assigned', 'awaiting_review', 'reviewed', 'withdrawn'],
            };
            if (! in_array($root['state'], $allowed, true)) {
                throw new ConflictHttpException;
            }
            $projection = [];
            if ($input) {
                $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
                $revision = DB::table('management_action_versions')->where('project_id', $project)->where('action_id', $action)->max('revision') + 1;
                $this->version($ticket, $input, $proof, $graph, $revision, $root['episode'] + 1, $at);
                $projection = ['episode' => $root['episode'] + 1, 'episode_opened_at' => $at, 'state' => 'open', 'support_status' => 'none', 'review_status' => 'unverified', 'current_outcome' => null, 'current_action_version_id' => $ticket['output']['action_version_id'], 'current_assignment_id' => null, 'current_support_id' => null, 'current_review_id' => null];
            } elseif ($op === 'assignment.set') {
                $version = $rows['management_action_versions'][$root['current_action_version_id']];
                if ($body['owner_id'] !== $version['owner_id']) {
                    throw new ConflictHttpException;
                }
                $assignment = array_intersect_key($body, array_flip(['owner_id', 'helper_id', 'department_id', 'reason']));
                $assignment += ['action_id' => $action, 'action_version_id' => $version['id'], 'action_hash' => $version['action_hash']];
                DB::table('management_assignments')->insert(['id' => $ticket['output']['assignment_id'], 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'assignment_hash' => hash('sha256', ManagementContract::canonical($assignment)), 'actor_id' => $actor->id, 'authority_proof' => ManagementContract::canonical($proof), 'receipt_id' => $ticket['receipt_id']] + $assignment);
                $projection = ['state' => 'assigned', 'support_status' => 'none', 'review_status' => 'unverified', 'current_outcome' => null, 'current_assignment_id' => $ticket['output']['assignment_id'], 'current_support_id' => null, 'current_review_id' => null];
            } elseif ($op === 'action.withdraw') {
                $projection = ['state' => 'withdrawn', 'current_outcome' => null];
            }
            $this->writer->event($ticket, $root, $projection, $proof, array_intersect_key($body, array_flip(['reason', 'context'])));
            if ($op !== 'context.add') {
                $evaluation['current_outcome'] = null;
                if ($input) {
                    $evaluation['current_applicability'] = $evaluation['subject_applicability'];
                    $evaluation['current_reason_codes'] = $evaluation['reason_codes'];
                }
            }

            return $this->writer->acknowledge($ticket, $evaluation);
        });
    }

    private function version(array $ticket, array $body, array $proof, array $graph, int $revision, int $episode, string $at): void
    {
        $hash = $this->writer->references($ticket, $body['references'], $graph, 'action_version_id');
        DB::table('management_action_versions')->insert(['id' => $ticket['output']['action_version_id'], 'tenant_id' => $ticket['tenant_id'], 'project_id' => $ticket['project_id'], 'action_id' => $ticket['output']['action_id'], 'revision' => $revision, 'episode' => $episode, 'owner_id' => $body['owner_id'], 'requester_id' => $proof['employee_id'], 'reason' => $body['reason'], 'occurred_at' => $at, 'action_hash' => hash('sha256', ManagementContract::canonical(['owner_id' => $body['owner_id'], 'requester_id' => $proof['employee_id'], 'reason' => $body['reason'], 'references_hash' => $hash, 'episode' => $episode])), 'references_hash' => $hash, 'actor_id' => $ticket['actor_id'], 'authority_proof' => ManagementContract::canonical($proof), 'receipt_id' => $ticket['receipt_id']]);
    }
}
