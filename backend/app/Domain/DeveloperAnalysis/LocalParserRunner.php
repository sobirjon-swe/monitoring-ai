<?php

namespace App\Domain\DeveloperAnalysis;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Throwable;

class LocalParserRunner
{
    public function __construct(private RunnerAvailability $availability, private DeveloperContract $contract) {}

    public function run(array $input): array
    {
        $this->availability->assertReady();
        $encoded = DeveloperContract::canonical($this->contract->input($input));
        if (strlen($encoded) > 1048576) {
            throw new HttpException(503, 'Developer analysis runner unavailable.');
        }

        try {
            $process = $this->process($encoded);
            $output = '';
            $stderrBytes = 0;
            $outputOverflow = false;
            $stderrOverflow = false;
            $process->run(function (string $type, string $chunk) use ($process, &$output, &$stderrBytes, &$outputOverflow, &$stderrOverflow): void {
                if ($type === Process::OUT) {
                    if (strlen($output) + strlen($chunk) > 262144) {
                        $outputOverflow = true;
                    } else {
                        $output .= $chunk;
                    }
                } else {
                    $stderrBytes += strlen($chunk);
                    $stderrOverflow = $stderrBytes > 8192;
                }
                $process->clearOutput();
                $process->clearErrorOutput();
                if ($outputOverflow || $stderrOverflow) {
                    $process->stop(0);
                }
            });
            if ($stderrOverflow) {
                throw new HttpException(503, 'Developer analysis runner unavailable.');
            }
            if ($outputOverflow || $process->getExitCode() === 2) {
                throw new InvalidParserOutput;
            }
            if (! $process->isSuccessful()) {
                throw new HttpException(503, 'Developer analysis runner unavailable.');
            }

            try {
                return $this->contract->output(json_decode($output, true, 32, JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                throw new InvalidParserOutput;
            }
        } catch (InvalidParserOutput $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new HttpException(503, 'Developer analysis runner unavailable.');
        }
    }

    protected function process(string $encoded): Process
    {
        return new Process([
            config('developer_analysis.python'), '-I', '-B', '-S', config('developer_analysis.broker'),
        ], dirname(config('developer_analysis.python')), RunnerAvailability::environment(), $encoded, 15);
    }
}
