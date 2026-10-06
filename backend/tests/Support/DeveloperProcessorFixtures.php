<?php

namespace Tests\Support;

use App\Domain\DeveloperAnalysis\AnalysisRequestService;
use App\Domain\DeveloperAnalysis\LocalParserRunner;
use App\Domain\DeveloperAnalysis\RunnerAvailability;
use App\Domain\DeveloperAnalysis\SyntheticMrRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait DeveloperProcessorFixtures
{
    protected function queuedWithPins(string $project, array $pins, ?array $fixture = null): string
    {
        $receipt = app(SyntheticMrRegistry::class)->register($this->actor(), $project, $fixture ?? $this->mr(), 0);
        $this->app->instance(RunnerAvailability::class, new class extends RunnerAvailability
        {
            public function assertReady(): void {}
        });

        return app(AnalysisRequestService::class)->request($this->actor(), $project, ['snapshot_id' => $receipt['snapshot_id'], 'mode' => 'deterministic'] + $pins, 'pin-request')['id'];
    }

    protected function queuedRun(string $project): array
    {
        $run = $this->storedRun($project);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('developer_outbox')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $project, 'run_id' => $run['id']]));

        return $run;
    }

    protected function fixtureOutput(array $input): array
    {
        $snapshot = $input['snapshot'];
        $output = json_decode(file_get_contents(base_path('../analytics/tests/fixtures/contract-output.json')), true);
        foreach (['run_id', 'request_id', 'input_hash', 'pins', 'analyzer_version', 'rule_version'] as $key) {
            $output[$key] = $input[$key];
        }
        $output['snapshot'] = array_intersect_key($snapshot, array_flip(['id', 'hash', 'base_sha', 'head_sha']));
        $output['redaction_hash'] = $snapshot['hash'];
        $output['patch']['hash'] = $snapshot['hash'];
        $output['files'][0]['old_path'] = $snapshot['paths'][0]['old_path'];
        $output['files'][0]['new_path'] = $snapshot['paths'][0]['new_path'];
        foreach ($output['locators'] as &$locator) {
            $locator['path'] = $snapshot['paths'][0][$locator['side'].'_path'];
            foreach (['snapshot_id' => 'id', 'snapshot_hash' => 'hash', 'base_sha' => 'base_sha', 'head_sha' => 'head_sha'] as $key => $field) {
                $locator[$key] = $snapshot[$field];
            }
        }

        return $output;
    }

    protected function runner(callable $operation): void
    {
        // Explicit behavioral-test injection only; the production runner stays fail-closed.
        $this->app->instance(LocalParserRunner::class, new class($operation) extends LocalParserRunner
        {
            public function __construct(private $operation) {}

            public function run(array $input): array
            {
                return ($this->operation)($input);
            }
        });
    }

    protected function scoped(callable $work): mixed
    {
        return app(TenantContext::class)->run($this->tenant, $work(...));
    }
}
