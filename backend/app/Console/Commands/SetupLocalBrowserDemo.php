<?php

namespace App\Console\Commands;

use App\Domain\Browser\Demo\DemoManifest;
use App\Domain\Browser\Demo\DemoWorkspaceService;
use Illuminate\Console\Command;
use Throwable;

class SetupLocalBrowserDemo extends Command
{
    protected $signature = 'app:setup-local-browser-demo {--manifest= : Exact reviewed local synthetic manifest} {--credentials-file= : New operator-owned protected local output file}';

    protected $description = 'Explicitly prepare the separately reviewed local synthetic browser workspace';

    public function handle(DemoWorkspaceService $service): int
    {
        try {
            // The production entry point is DEV-only. TEST can exercise the injectable service,
            // never this command as a path to TEST or a different persistent database.
            if (! app()->environment('local') || config('database.connections.pgsql.database') !== 'monitoring_ai_dev'
                || ! is_string($manifest = $this->option('manifest')) || ! is_string($output = $this->option('credentials-file'))) {
                throw new \LogicException;
            }
            $result = $service->prepare(DemoManifest::fromFile($manifest), $output);
            $this->line('Synthetic browser workspace '.$result['status'].'; tenant_id='.$result['tenant_id'].' project_id='.$result['project_id']);
            $this->line('Protected credentials file: '.$result['credentials_file']);

            return self::SUCCESS;
        } catch (Throwable) {
            // Do not report exception messages: credential journal and database context are private.
            $this->error('Synthetic browser setup refused; inspect the protected local setup journal.');

            return self::FAILURE;
        }
    }
}
