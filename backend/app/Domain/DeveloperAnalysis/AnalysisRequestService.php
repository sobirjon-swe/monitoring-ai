<?php

namespace App\Domain\DeveloperAnalysis;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AnalysisRequestService
{
    public function __construct(private readonly TenantContext $context, private readonly AccessGateway $access,
        private readonly DeveloperCoordination $coordination, private readonly AnalysisPins $pins,
        private readonly RunnerAvailability $availability, private readonly DeveloperContract $contract, private readonly AuditWriter $audit) {}

    public function request(TrustedActor $actor, string $project, array $payload, string $key): array
    {
        $project = strtolower($project);
        $keys = ['snapshot_id', 'mode', 'criterion_version_ids', 'task_version_ids'];
        if (array_diff(array_keys($payload), $keys) || array_diff($keys, array_keys($payload)) || ! is_string($payload['snapshot_id'] ?? null)
            || ! Str::isUuid($payload['snapshot_id']) || ($payload['mode'] ?? null) !== 'deterministic' || ! preg_match('/\A[!-~]{1,128}\z/D', $key)) {
            throw ValidationException::withMessages(['payload' => 'Invalid analysis request.']);
        }
        $payload['snapshot_id'] = strtolower($payload['snapshot_id']);
        $payload = array_replace($payload, AnalysisPins::normalize(array_intersect_key($payload, array_flip(['criterion_version_ids', 'task_version_ids']))));
        $hash = hash('sha256', DeveloperContract::canonical(['project_id' => $project, 'payload' => $payload]));

        try {
            return $this->context->run($actor->tenantId, function () use ($actor, $project, $payload, $key, $hash): array {
                foreach (['analysis.request', 'analysis.source.read'] as $purpose) {
                    $this->access->authorize($actor, $purpose, ['tenant_id' => $actor->tenantId, 'project_id' => $project]);
                }
                // Serialize the unique tenant/requester key before project acquisition, including cross-project retries.
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$actor->tenantId.'|'.$actor->id.'|analysis.request|'.$key]);
                $hint = DB::table('developer_runs')->where('requester_id', $actor->id)->where('operation', 'analysis.request')->where('idempotency_key', $key)->first();
                if ($hint) {
                    foreach (['analysis.read', 'analysis.source.read'] as $purpose) {
                        $this->access->authorize($actor, $purpose, ['tenant_id' => $actor->tenantId, 'project_id' => $hint->project_id]);
                    }
                    if ($hint->project_id !== $project) {
                        throw new ConflictHttpException;
                    }
                }
                $requests = $hint ? [['actor_id' => $hint->requester_id, 'purposes' => json_decode($hint->authority, true)['purposes'], 'pinned_proof' => json_decode($hint->authority, true)]] : [];

                return $this->coordination->run($actor, $project, $hint ? ['analysis.request', 'analysis.read', 'analysis.source.read'] : ['analysis.request', 'analysis.source.read'], false,
                    function ($proofs) use ($actor, $project, $payload, $key, $hash, $hint): array {
                        if ($hint) {
                            // The immutable hint binds the source lock before any run/outbox lock.
                            $this->pins->lockSource($actor, $project, $hint->snapshot_id);
                        }
                        $existing = DB::table('developer_runs')->where('requester_id', $actor->id)->where('operation', 'analysis.request')->where('idempotency_key', $key)->lockForUpdate()->first();
                        if ($existing) {
                            if (! $hint || $existing->id !== $hint->id || $existing->project_id !== $project
                                || $existing->snapshot_id !== $hint->snapshot_id || $existing->authority !== $hint->authority
                                || $existing->input_hash !== $hint->input_hash || ! hash_equals($existing->payload_hash, $hash)) {
                                throw new ConflictHttpException;
                            }
                            if (app(AnalysisInvalidation::class)->observe($actor, $existing, 'request-retry')) {
                                $existing = DB::table('developer_runs')->where('project_id', $project)->where('id', $existing->id)->first();
                            }

                            return ['id' => $existing->id, 'status' => $existing->status, 'applicability' => $this->pins->applicability($actor, $existing)];
                        }
                        if (DB::table('lifecycle_project_state')->where('project_id', $project)->value('is_closed')) {
                            throw new ConflictHttpException;
                        }
                        $snapshot = DB::table('developer_snapshots')->where('project_id', $project)->where('id', $payload['snapshot_id'])->first();
                        if (! $snapshot) {
                            throw new NotFoundHttpException;
                        }
                        if (! $this->pins->snapshotCurrent($actor, $project, $snapshot)) {
                            throw new ConflictHttpException;
                        }
                        $pins = $this->pins->capture($actor, $project, array_intersect_key($payload, array_flip(['criterion_version_ids', 'task_version_ids'])));
                        $this->availability->assertReady();
                        if (DB::table('developer_runs')->where('project_id', $project)->whereIn('status', ['queued', 'running'])->count() >= 100) {
                            throw new HttpException(429, 'Analysis queue full.', null, ['Retry-After' => '5']);
                        }
                        $id = (string) Str::uuid();
                        $request = (string) Str::uuid();
                        $input = ['version' => 'developer-analysis-input-v1', 'run_id' => $id, 'request_id' => $request, 'pins' => $pins,
                            'analyzer_version' => 'unified-diff-v1', 'rule_version' => 'developer-taxonomy-v1',
                            'snapshot' => ['id' => $snapshot->id, 'hash' => $snapshot->content_hash, 'base_sha' => $snapshot->base_sha, 'head_sha' => $snapshot->head_sha,
                                'source_instance_id' => $snapshot->source_instance_id, 'repository_id' => $snapshot->repository_id, 'mr_id' => $snapshot->mr_id,
                                'revision_ordinal' => $snapshot->revision_ordinal, 'paths' => json_decode($snapshot->paths, true), 'redacted_diff' => $snapshot->safe_diff, 'redaction' => json_decode($snapshot->redaction, true)]];
                        $input['input_hash'] = hash('sha256', DeveloperContract::canonical($input));
                        $this->contract->input($input);
                        $authority = DeveloperContract::canonical($proofs[$actor->id]);
                        DB::table('developer_runs')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'request_id' => $request, 'requester_id' => $actor->id,
                            'snapshot_id' => $snapshot->id, 'idempotency_key' => $key, 'payload_hash' => $hash, 'input_hash' => $input['input_hash'], 'input' => DeveloperContract::canonical($input),
                            'pins' => DeveloperContract::canonical($pins), 'authority' => $authority, 'authority_hash' => hash('sha256', $authority), 'captured_at' => $proofs[$actor->id]['captured_at'],
                            'analyzer_version' => $input['analyzer_version'], 'rule_version' => $input['rule_version'], 'redaction_version' => $input['snapshot']['redaction']['version']]);
                        DB::table('developer_outbox')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'run_id' => $id]);
                        $this->audit->record($actor->id, ['tenant_id' => $actor->tenantId, 'project_id' => $project], 'developer.run_requested', ['type' => 'developer_runs', 'id' => $id], 1, 'approved_change');

                        return ['id' => $id, 'status' => 'queued', 'applicability' => 'current'];
                    }, $requests);
            });
        } catch (AccessDeniedHttpException $exception) {
            // Rollback must reopen authority before conservative maintenance; this grants no delivery.
            $this->context->run($actor->tenantId, function () use ($actor, $project, $key): void {
                $id = DB::table('developer_runs')->where('tenant_id', $actor->tenantId)->where('project_id', $project)
                    ->where('requester_id', $actor->id)->where('operation', 'analysis.request')->where('idempotency_key', $key)->value('id');
                if ($id !== null) {
                    app(AnalysisInvalidation::class)->refreshRun($actor, $project, $id);
                }
            });
            throw $exception;
        }
    }
}
