<?php

namespace App\Console\Commands;

use App\Domain\Access\TrustedActor;
use App\Domain\Qa\QaMaintenanceProcessor;
use App\Support\LocalQaDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class DrainLocalQaMaintenance extends Command
{
    protected $signature = 'app:qa-drain {tenant} {actor} {project} {--limit=20 : Maximum claims, 1 to 20}';

    protected $description = 'Explicit authorized local operator drain of durable synthetic QA maintenance';

    public function handle(LocalQaDatabase $guard, QaMaintenanceProcessor $processor): int
    {
        try {
            $guard->assertRuntime();
            foreach (['tenant', 'actor', 'project'] as $name) {
                if (! is_string($this->argument($name)) || ! Str::isUuid($this->argument($name))) {
                    throw new LogicException;
                }
            }
            $limit = $this->option('limit');
            if (! is_string($limit) || ! preg_match('/\A(?:[1-9]|1[0-9]|20)\z/D', $limit)) {
                throw new LogicException;
            }
            $counts = $processor->drain(new TrustedActor(strtolower($this->argument('actor')), strtolower($this->argument('tenant'))), strtolower($this->argument('project')), (int) $limit);
            $parts = [];
            foreach (['claimed', 'completed', 'retry', 'fenced', 'processed'] as $name) {
                $parts[] = $name.'='.$counts[$name];
            }
            $this->line(implode(' ', $parts));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Local QA maintenance drain failed.');

            return self::FAILURE;
        }
    }
}
