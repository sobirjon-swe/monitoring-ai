<?php

use App\Domain\Access\TrustedActor;
use App\Domain\Management\ManagementAuthority;
use App\Domain\Management\ManagementCoordination;
use App\Support\LocalManagementDatabase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Fixed TEST-only concurrent authority runner. It never writes domain/source facts.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

try {
    config(['logging.default' => 'null']);
    if (! $app->environment('testing')) {
        throw new LogicException('TEST authority child refused.');
    }
    app(LocalManagementDatabase::class)->assertRuntime();
    $raw = fgets(STDIN, 131073);
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $actor = new TrustedActor($input['actor_id'], $input['tenant_id']);
    DB::beginTransaction();
    echo 'ready pid='.DB::scalar('select pg_backend_pid()').PHP_EOL;
    flush();
    if (trim(fgets(STDIN) ?: '') !== 'go') {
        throw new LogicException('Missing authority barrier.');
    }
    $result = app(ManagementCoordination::class)->run($actor, $input['project_id'], ['management.read'], false, $input['graph'], function ($proofs) {
        $keys = array_values(array_filter(array_keys($proofs), fn ($key) => str_starts_with($key, 'original:')));
        app(ManagementAuthority::class)->requireDeliveryLocked($keys);
        foreach ($keys as $key) {
            if (! $proofs[$key]['valid']) {
                throw new LogicException('Original authority failed.');
            }
        }

        return count($keys);
    });
    if ($result !== 3) {
        throw new LogicException('Missing mixed authority result.');
    }
    foreach (['app.tenant_id', 'app.management_authority_closed', 'app.management_proofs', 'app.management_required_keys', 'app.qa_responsibility'] as $setting) {
        if (DB::scalar('select nullif(current_setting(?,true),\'\')', [$setting]) !== null) {
            throw new LogicException('Leaked authority setting.');
        }
    }
    echo 'status=ok mixed=3'.PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Management TEST authority child refused: '.$e::class.PHP_EOL);
    exit(1);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
