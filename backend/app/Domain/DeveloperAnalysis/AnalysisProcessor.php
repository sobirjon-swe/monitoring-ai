<?php

namespace App\Domain\DeveloperAnalysis;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AnalysisProcessor
{
    public function __construct(private readonly TenantContext $context, private readonly AccessGateway $access,
        private readonly DeveloperCoordination $coordination, private readonly DeveloperAuthority $authority,
        private readonly AnalysisInvalidation $invalidation, private readonly LocalParserRunner $runner,
        private readonly ResultGate $gate, private readonly AuditWriter $audit) {}

    public function drain(TrustedActor $operator, string $project, int $limit): array
    {
        $project = strtolower($project);
        if (! Str::isUuid($project) || $limit < 1 || $limit > 20 || ! app()->environment(['local', 'testing'])) {
            throw new InvalidArgumentException('Invalid local analysis batch.');
        }
        foreach (['analysis.process', 'analysis.source.read'] as $purpose) {
            $this->access->authorize($operator, $purpose, ['tenant_id' => $operator->tenantId, 'project_id' => $project]);
        }
        $hints = $this->context->run($operator->tenantId, fn () => DB::table('developer_runs as r')
            ->join('developer_outbox as o', 'o.run_id', '=', 'r.id')->where('r.project_id', $project)->where('r.applicability', 'current')
            ->whereIn('r.status', ['queued', 'running'])->where(function ($query): void {
                $query->where(function ($q): void {
                    $q->whereIn('o.status', ['pending', 'retry'])->whereRaw('o.available_at <= clock_timestamp()');
                })
                    ->orWhere(function ($q): void {
                        $q->where('o.status', 'processing')->whereRaw('o.lease_until <= clock_timestamp()');
                    });
            })->orderBy('r.id')->limit($limit)->get(['r.*'])->all());
        $states = [];
        foreach ($hints as $hint) {
            try {
                $claim = $this->claim($operator, $project, $hint);
                if ($claim === null) {
                    continue;
                }
                $validated = null;
                $reason = null;
                for ($rerun = 0; $rerun < 2; $rerun++) {
                    try {
                        $validated = $this->gate->validate($claim['input'], $this->runner->run($claim['input']));
                        break;
                    } catch (InvalidParserOutput|InvalidArgumentException) {
                        $reason = 'invalid_output';
                    } catch (HttpException) {
                        $reason = 'runner_unavailable';
                        break;
                    } catch (\Throwable) {
                        $reason = 'parser_failed';
                        break;
                    }
                }
                $states[] = $this->finish($operator, $project, $hint, $claim, $validated, $reason);
            } catch (AccessDeniedHttpException) {
                // A denied actor never receives facts and is not granted bypass authority.
                $this->invalidation->revoked($operator, $project, $hint->id);
                $states[] = 'blocked';
            }
        }

        return ['processed' => count($states), 'states' => $states];
    }

    private function requests(object $hint, ?array $processing = null, ?string $operator = null): array
    {
        $proof = json_decode($hint->authority, true);
        $requests = [['actor_id' => $hint->requester_id, 'purposes' => $proof['purposes'], 'pinned_proof' => $proof]];
        if ($processing !== null && $operator !== $hint->requester_id) {
            $requests[] = ['actor_id' => $operator, 'purposes' => $processing['purposes'], 'pinned_proof' => $processing];
        }

        return $requests;
    }

    private function claim(TrustedActor $operator, string $project, object $hint): ?array
    {
        return $this->coordination->run($operator, $project, ['analysis.process', 'analysis.source.read'], false,
            function ($proofs) use ($operator, $project, $hint): ?array {
                $run = DB::table('developer_runs')->where('project_id', $project)->where('id', $hint->id)->first();
                if (! $this->identity($run, $hint) || $run->applicability !== 'current' || ! in_array($run->status, ['queued', 'running'], true)) {
                    return null;
                }
                app(AnalysisPins::class)->lockSource($operator, $project, $run->snapshot_id);
                if ($this->invalidation->observe($operator, $run, 'claim')) {
                    return null;
                }
                $run = DB::table('developer_runs')->where('id', $run->id)->lockForUpdate()->first();
                $outbox = DB::table('developer_outbox')->where('run_id', $run->id)->lockForUpdate()->first();
                if (! $outbox || $run->attempt_count !== $outbox->attempt_count
                    || ! DB::scalar("select (? in ('pending','retry') and ?::timestamptz <= clock_timestamp()) or (?='processing' and ?::timestamptz <= clock_timestamp())", [$outbox->status, $outbox->available_at, $outbox->status, $outbox->lease_until])) {
                    return null;
                }
                if ($run->attempt_count >= 5) {
                    $this->terminal($run->id, $outbox->id, 'attempts_exhausted');

                    return null;
                }
                $token = (string) Str::uuid();
                $until = DB::scalar("select clock_timestamp() + interval '60 seconds'");
                $attempt = $run->attempt_count + 1;
                $processing = DeveloperContract::canonical($proofs[$operator->id]);
                DB::table('developer_runs')->where('id', $run->id)->update(['status' => 'running', 'attempt_count' => $attempt, 'lease_token' => $token, 'lease_until' => $until, 'reason_code' => null]);
                DB::table('developer_outbox')->where('id', $outbox->id)->update(['status' => 'processing', 'attempt_count' => $attempt, 'lease_token' => $token, 'lease_until' => $until, 'reason_code' => null, 'processing_authority' => $processing, 'processing_authority_hash' => hash('sha256', $processing)]);

                return ['input' => json_decode($run->input, true), 'token' => $token, 'attempt' => $attempt, 'processing' => $proofs[$operator->id]];
            }, $this->requests($hint));
    }

    private function finish(TrustedActor $operator, string $project, object $hint, array $claim, ?array $validated, ?string $reason): string
    {
        return $this->coordination->run($operator, $project, ['analysis.process', 'analysis.source.read'], false,
            function ($proofs) use ($operator, $project, $hint, $claim, $validated, $reason): string {
                $run = DB::table('developer_runs')->where('project_id', $project)->where('id', $hint->id)->first();
                if (! $this->identity($run, $hint) || $run->applicability !== 'current') {
                    return 'blocked';
                }
                app(AnalysisPins::class)->lockSource($operator, $project, $run->snapshot_id);
                if ($this->invalidation->observe($operator, $run, 'publication')) {
                    return 'blocked';
                }
                $run = DB::table('developer_runs')->where('id', $run->id)->lockForUpdate()->first();
                $outbox = DB::table('developer_outbox')->where('run_id', $run->id)->lockForUpdate()->first();
                if (! $outbox || $run->status !== 'running' || $outbox->status !== 'processing' || $run->lease_token !== $claim['token'] || $outbox->lease_token !== $claim['token']
                    || $run->attempt_count !== $claim['attempt'] || $outbox->attempt_count !== $claim['attempt'] || $run->lease_until !== $outbox->lease_until
                    || ! DB::scalar('select ?::timestamptz > clock_timestamp()', [$run->lease_until])) {
                    return 'lost_lease';
                }
                $this->authority->assertEffective($proofs, DB::scalar('select clock_timestamp()'));
                $currentProcessing = $proofs[$operator->id];
                $claimedProcessing = json_decode($outbox->processing_authority, true);
                if (! is_array($claimedProcessing) || ! hash_equals($outbox->processing_authority_hash, hash('sha256', DeveloperContract::canonical($claimedProcessing)))
                    || DeveloperContract::canonical($claimedProcessing) !== DeveloperContract::canonical($claim['processing'])) {
                    throw new AccessDeniedHttpException;
                }
                unset($currentProcessing['captured_at'], $claimedProcessing['captured_at']);
                if (DeveloperContract::canonical($currentProcessing) !== DeveloperContract::canonical($claimedProcessing)) {
                    throw new AccessDeniedHttpException;
                }
                if ($validated === null) {
                    if ($reason === 'invalid_output' || $run->attempt_count === 5) {
                        $this->terminal($run->id, $outbox->id, $reason === 'invalid_output' ? 'invalid_output' : 'attempts_exhausted');

                        return 'dead_letter';
                    }
                    $delay = [5, 30, 120, 600][$run->attempt_count - 1];
                    DB::table('developer_runs')->where('id', $run->id)->update(['status' => 'queued', 'lease_token' => null, 'lease_until' => null, 'reason_code' => $reason]);
                    DB::table('developer_outbox')->where('id', $outbox->id)->update(['status' => 'retry', 'lease_token' => null, 'lease_until' => null, 'reason_code' => $reason, 'available_at' => DB::raw("clock_timestamp() + interval '$delay seconds'")]);

                    return 'retry';
                }
                $validated = $this->gate->validate(json_decode($run->input, true), $validated['output']);
                $output = DeveloperContract::canonical($validated['output']);
                $proof = DeveloperContract::canonical(json_decode($outbox->processing_authority, true));
                $id = (string) Str::uuid();
                $scope = ['tenant_id' => $operator->tenantId, 'project_id' => $project, 'snapshot_id' => $run->snapshot_id, 'run_id' => $run->id];
                DB::table('developer_results')->insert($scope + ['id' => $id, 'input_hash' => $run->input_hash, 'output_hash' => hash('sha256', $output), 'output' => $output, 'processing_authority' => $proof, 'processing_authority_hash' => hash('sha256', $proof)]);
                foreach ($validated['findings'] as $finding) {
                    $finding['facts']['scope'] = ['tenant_id' => $operator->tenantId, 'project_id' => $project];
                    if ($finding['kind'] === 'provenance_notice') {
                        $input = json_decode($run->input, true);
                        $finding['facts']['patch_group_id'] = $validated['output']['patch']['status'] === 'exact'
                            ? hash('sha256', DeveloperContract::canonical(['tenant_id' => $operator->tenantId, 'project_id' => $project,
                                'source_instance_id' => $input['snapshot']['source_instance_id'], 'repository_id' => $input['snapshot']['repository_id'],
                                'pins' => $input['pins'], 'patch_hash' => $validated['output']['patch']['hash']])) : null;
                    }
                    DB::table('developer_findings')->insert($scope + ['id' => (string) Str::uuid(), 'result_id' => $id, 'kind' => $finding['kind'], 'facts' => DeveloperContract::canonical($finding['facts']), 'summary' => $finding['summary']]);
                }
                $status = $validated['output']['coverage']['status'] === 'partial' ? 'partial' : 'succeeded';
                DB::table('developer_runs')->where('id', $run->id)->update(['status' => $status, 'lease_token' => null, 'lease_until' => null, 'completed_at' => DB::raw('clock_timestamp()'), 'reason_code' => null]);
                DB::table('developer_outbox')->where('id', $outbox->id)->update(['status' => 'completed', 'lease_token' => null, 'lease_until' => null, 'completed_at' => DB::raw('clock_timestamp()')]);
                $this->audit->record($operator->id, ['tenant_id' => $operator->tenantId, 'project_id' => $project], 'developer.result_published', ['type' => 'developer_results', 'id' => $id], 1, 'approved_change');
                $this->authority->assertEffective($proofs, DB::scalar('select clock_timestamp()'));
                if (! DB::scalar('select ?::timestamptz > clock_timestamp()', [$run->lease_until])) {
                    throw new \RuntimeException('Developer lease expired before publication.');
                }

                return $status;
            }, $this->requests($hint, $claim['processing'], $operator->id));
    }

    private function identity(?object $run, object $hint): bool
    {
        return $run !== null && $run->id === $hint->id && $run->requester_id === $hint->requester_id && $run->authority === $hint->authority && $run->input_hash === $hint->input_hash && $run->input === $hint->input;
    }

    private function terminal(string $run, string $outbox, string $reason): void
    {
        DB::table('developer_runs')->where('id', $run)->update(['status' => 'error', 'lease_token' => null, 'lease_until' => null, 'completed_at' => DB::raw('clock_timestamp()'), 'reason_code' => $reason]);
        DB::table('developer_outbox')->where('id', $outbox)->update(['status' => 'dead_letter', 'lease_token' => null, 'lease_until' => null, 'reason_code' => $reason]);
    }
}
