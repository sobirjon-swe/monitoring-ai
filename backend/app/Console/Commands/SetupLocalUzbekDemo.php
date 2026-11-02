<?php

namespace App\Console\Commands;

use App\Domain\Browser\UzbekDemo\UzbekDemoManifest;
use App\Domain\Browser\UzbekDemo\UzbekDemoWorkspaceService;
use Illuminate\Console\Command;
use Throwable;

final class SetupLocalUzbekDemo extends Command
{
    protected $signature = 'app:setup-local-uzbek-demo {--manifest= : Exact reviewed manifest} {--credentials-file= : Protected new owner-only local file}';

    protected $description = 'Explicitly create the reviewed isolated fictional Uzbek demo locally';

    public function handle(UzbekDemoWorkspaceService $service): int
    {
        try {
            if (! app()->environment('local') || config('database.connections.pgsql.database') !== 'monitoring_ai_dev' || ! is_string($manifest = $this->option('manifest')) || ! is_string($output = $this->option('credentials-file'))) {
                throw new \LogicException;
            }
            $result = $service->prepare(UzbekDemoManifest::fromFile($manifest), $output);
            $this->line('Uzbek DEMO '.$result['status'].'; tenant_id='.$result['tenant_id']);
            $this->line('Protected credentials file: '.$result['credentials_file']);
            $this->line('Existing F01 fingerprint: '.$result['old_tenant_fingerprint']);

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Uzbek DEMO setup refused; inspect the protected local journal.');

            return self::FAILURE;
        }
    }
}
