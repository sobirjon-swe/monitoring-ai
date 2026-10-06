<?php

namespace Tests\Support;

use App\Domain\Access\TrustedActor;
use App\Domain\DeveloperAnalysis\DeveloperContract;
use App\Domain\DeveloperAnalysis\DeveloperCoordination;
use App\Domain\DeveloperAnalysis\SyntheticMrRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class DeveloperAnalysisTestCase extends AccessTestCase
{
    protected function developerProject(): string
    {
        $this->fixture();
        $project = $this->project();
        foreach (['analysis.fixture.manage', 'analysis.request', 'analysis.read', 'analysis.source.read', 'analysis.review', 'analysis.process'] as $purpose) {
            $this->grant($purpose, $project);
        }

        return $project;
    }

    protected function actor(): TrustedActor
    {
        return new TrustedActor($this->user, $this->tenant);
    }

    protected function mr(): array
    {
        return require __DIR__.'/../Fixtures/developer-mr.php';
    }

    protected function storedRun(string $project): array
    {
        $receipt = app(SyntheticMrRegistry::class)->register($this->actor(), $project, $this->mr(), 0);

        return app(DeveloperCoordination::class)->run($this->actor(), $project, ['analysis.request', 'analysis.source.read'], true, function ($proofs) use ($project, $receipt): array {
            $run = (string) Str::uuid();
            $request = (string) Str::uuid();
            $snapshot = DB::table('developer_snapshots')->where('id', $receipt['snapshot_id'])->first();
            $input = $this->inputEnvelope($snapshot, $run, $request);
            $authority = DeveloperContract::canonical($proofs[$this->user]);
            DB::table('developer_runs')->insert([
                'id' => $run, 'tenant_id' => $this->tenant, 'project_id' => $project, 'request_id' => $request, 'snapshot_id' => $snapshot->id, 'requester_id' => $this->user,
                'idempotency_key' => $run, 'payload_hash' => str_repeat('a', 64), 'input_hash' => $input['input_hash'], 'input' => DeveloperContract::canonical($input), 'pins' => '{"criterion_version_ids":[],"task_version_ids":[]}',
                'authority' => $authority, 'authority_hash' => hash('sha256', $authority), 'analyzer_version' => 'unified-diff-v1', 'rule_version' => 'developer-taxonomy-v1', 'redaction_version' => 'secret-gate-v1', 'captured_at' => $proofs[$this->user]['captured_at'],
            ]);

            return ['id' => $run, 'snapshot_id' => $snapshot->id, 'input' => $input, 'authority' => $authority];
        });
    }

    protected function inputEnvelope(object $snapshot, string $run, string $request): array
    {
        $input = ['version' => 'developer-analysis-input-v1', 'run_id' => $run, 'request_id' => $request, 'pins' => ['criterion_version_ids' => [], 'task_version_ids' => []], 'analyzer_version' => 'unified-diff-v1', 'rule_version' => 'developer-taxonomy-v1',
            'snapshot' => ['id' => $snapshot->id, 'hash' => $snapshot->content_hash, 'base_sha' => $snapshot->base_sha, 'head_sha' => $snapshot->head_sha, 'source_instance_id' => $snapshot->source_instance_id, 'repository_id' => $snapshot->repository_id, 'mr_id' => $snapshot->mr_id,
                'revision_ordinal' => $snapshot->revision_ordinal, 'paths' => json_decode($snapshot->paths, true), 'redacted_diff' => $snapshot->safe_diff, 'redaction' => json_decode($snapshot->redaction, true)]];
        $input['input_hash'] = hash('sha256', DeveloperContract::canonical($input));

        return $input;
    }
}
