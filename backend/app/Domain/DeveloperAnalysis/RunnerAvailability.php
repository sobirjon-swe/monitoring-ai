<?php

namespace App\Domain\DeveloperAnalysis;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Throwable;

class RunnerAvailability
{
    public function assertReady(): void
    {
        if (config('developer_analysis.enabled') !== true || PHP_OS_FAMILY !== 'Windows'
            || ! is_file(config('developer_analysis.manifest'))
            || ! is_file(config('developer_analysis.activation'))
            || ! is_file(config('developer_analysis.python'))
            || ! is_file(config('developer_analysis.broker'))) {
            throw new HttpException(503, 'Developer analysis runner unavailable.');
        }

        try {
            $process = new Process([
                config('developer_analysis.python'), '-I', '-B', '-S',
                config('developer_analysis.broker'), '--check',
            ], dirname(config('developer_analysis.python')), self::environment(), null, 15);
            $output = '';
            $bytes = 0;
            $overflow = false;
            $process->run(function (string $type, string $chunk) use ($process, &$output, &$bytes, &$overflow): void {
                $bytes += strlen($chunk);
                if ($bytes > 8192) {
                    $overflow = true;
                    $process->stop(0);
                } elseif ($type === Process::OUT) {
                    $output .= $chunk;
                }
                $process->clearOutput();
                $process->clearErrorOutput();
            });
            if ($overflow || ! $process->isSuccessful() || json_decode($output, true, 8, JSON_THROW_ON_ERROR) !== ['ready' => true]) {
                throw new HttpException(503, 'Developer analysis runner unavailable.');
            }
        } catch (Throwable) {
            throw new HttpException(503, 'Developer analysis runner unavailable.');
        }
    }

    public static function environment(): array
    {
        // Symfony merges the parent environment unless inherited keys are explicitly removed.
        $environment = array_fill_keys(array_unique(array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER))), false);
        $environment['SystemRoot'] = getenv('SystemRoot') ?: 'C:\\Windows';
        $environment['WINDIR'] = $environment['SystemRoot'];
        $environment['PATH'] = dirname(config('developer_analysis.python')).';'.$environment['SystemRoot'].'\\System32';

        return $environment;
    }
}
