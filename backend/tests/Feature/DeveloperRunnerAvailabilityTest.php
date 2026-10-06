<?php

namespace Tests\Feature;

use App\Domain\DeveloperAnalysis\DeveloperContract;
use App\Domain\DeveloperAnalysis\InvalidParserOutput;
use App\Domain\DeveloperAnalysis\LocalParserRunner;
use App\Domain\DeveloperAnalysis\RunnerAvailability;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeveloperRunnerAvailabilityTest extends TestCase
{
    public function test_disabled_runner_denies_admission_with_503(): void
    {
        config(['developer_analysis.enabled' => false]);

        try {
            app(RunnerAvailability::class)->assertReady();
            $this->fail('Disabled runner admitted work.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
    }

    public function test_enabled_without_owned_manifest_still_denies_admission(): void
    {
        config(['developer_analysis.enabled' => true]);
        config(['developer_analysis.manifest' => base_path('../.local/nonexistent-e04-manifest.json')]);

        try {
            app(RunnerAvailability::class)->assertReady();
            $this->fail('Missing runtime admitted work.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
    }

    public function test_parent_environment_canary_is_removed_from_broker_environment(): void
    {
        putenv('E04_SYNTHETIC_SECRET=synthetic-only-canary');
        try {
            $environment = RunnerAvailability::environment();
            $this->assertFalse($environment['E04_SYNTHETIC_SECRET']);
            $this->assertSame(dirname(config('developer_analysis.python')).';'.$environment['SystemRoot'].'\\System32', $environment['PATH']);
        } finally {
            putenv('E04_SYNTHETIC_SECRET');
        }
    }

    #[DataProvider('invalidOutputCases')]
    public function test_invalid_output_has_safe_typed_failure(int $exit, string $output): void
    {
        try {
            $this->runner(new SyntheticBrokerProcess($exit, $output))->run($this->input());
            $this->fail('Invalid output was accepted.');
        } catch (InvalidParserOutput $exception) {
            $this->assertSame('Invalid parser output.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public static function invalidOutputCases(): array
    {
        return [
            'broker exit2' => [2, ''],
            'malformed JSON' => [0, '{synthetic-secret-canary'],
            'schema invalid' => [0, '{}'],
            'oversized output' => [0, str_repeat('x', 262145)],
        ];
    }

    #[DataProvider('unavailableCases')]
    public function test_transport_and_timeout_remain_safe_503(int $exit, bool $timeout, string $stderr): void
    {
        try {
            $this->runner(new SyntheticBrokerProcess($exit, '', $stderr, $timeout))->run($this->input());
            $this->fail('Unavailable broker was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertNull($exception->getPrevious());
        }
    }

    public static function unavailableCases(): array
    {
        return [
            'broker exit1 without raw reason parsing' => [1, false, 'output_json synthetic-canary'],
            'timeout' => [0, true, ''],
            'stderr quota' => [0, false, str_repeat('x', 8193)],
        ];
    }

    public function test_valid_output_uses_real_contract_after_process_boundary(): void
    {
        $raw = file_get_contents(base_path('../analytics/tests/fixtures/contract-output.json'));
        $result = $this->runner(new SyntheticBrokerProcess(0, $raw))->run($this->input());
        $this->assertSame(['added' => 1, 'deleted' => 1], $result['counts']);
    }

    private function input(): array
    {
        return json_decode(file_get_contents(base_path('../analytics/tests/fixtures/contract-input.json')), true, 32, JSON_THROW_ON_ERROR);
    }

    private function runner(Process $process): LocalParserRunner
    {
        $availability = new class extends RunnerAvailability
        {
            public function assertReady(): void {}
        };

        return new class($availability, app(DeveloperContract::class), $process) extends LocalParserRunner
        {
            public function __construct(RunnerAvailability $availability, DeveloperContract $contract, private Process $syntheticProcess)
            {
                parent::__construct($availability, $contract);
            }

            protected function process(string $encoded): Process
            {
                return $this->syntheticProcess;
            }
        };
    }
}

// OS/process adapter only. No child is launched and no activation evidence is generated.
class SyntheticBrokerProcess extends Process
{
    public function __construct(private int $syntheticExit, private string $stdout, private string $stderr = '', private bool $timedOut = false)
    {
        parent::__construct(['synthetic-test-adapter']);
    }

    public function run(?callable $callback = null, array $env = []): int
    {
        if ($this->timedOut) {
            throw new ProcessTimedOutException($this, ProcessTimedOutException::TYPE_GENERAL);
        }
        $callback(Process::OUT, $this->stdout);
        $callback(Process::ERR, $this->stderr);

        return $this->syntheticExit;
    }

    public function isSuccessful(): bool
    {
        return $this->syntheticExit === 0;
    }

    public function getExitCode(): ?int
    {
        return $this->syntheticExit;
    }

    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        return $this->syntheticExit;
    }

    public function clearOutput(): static
    {
        // This adapter delivers chunks directly; it owns no Symfony output stream.
        return $this;
    }

    public function clearErrorOutput(): static
    {
        return $this;
    }
}
