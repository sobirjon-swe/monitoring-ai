<?php

use App\Domain\Access\TrustedActor;
use App\Domain\Qa\QaInvalidation;
use App\Domain\Qa\QaMaintenanceProcessor;
use App\Domain\Qa\QaReceiptWriter;
use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne('select pg_backend_pid() as pid,current_database() as database,current_user as role,r.rolsuper,r.rolbypassrls from pg_roles r where r.rolname=current_user');
if (! $app->environment('testing') || $identity->database !== 'monitoring_ai_test' || $identity->role !== 'monitoring_ai_app' || $identity->rolsuper || $identity->rolbypassrls) {
    exit(90);
}
foreach (['pgsql', 'pgsql_migration'] as $connection) {
    $config = config('database.connections.'.$connection);
    if (! in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true) || (int) $config['port'] !== 55432 || ! in_array($config['url'] ?? null, [null, ''], true) || ! empty($config['read']) || ! empty($config['write'])) {
        exit(90);
    }
}
[$script,$tenant,$actor,$project,$mode] = $argv;
if (! in_array($mode, ['pause', 'crash', 'claim', 'publish', 'observe-restore'], true)) {
    exit(91);
}
$trusted = new TrustedActor($actor, $tenant);
if ($mode === 'observe-restore') {
    [$operation,$key,$purpose] = array_slice($argv, 5);
    if (! in_array($operation, QaReceiptWriter::OPERATIONS, true) || ! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $key) || ! in_array($purpose, ['qa.fixture.manage', 'qa.plan.manage', 'qa.execution.record', 'qa.defect.review', 'qa.handoff.manage'], true)) {
        exit(91);
    }
    $context = $app->make(TenantContext::class);
    $grants = $context->run($tenant, function () use ($project, $actor, $purpose) {
        $rows = DB::table('access_grants')->where('project_id', $project)->where('user_id', $actor)->where('purpose', $purpose)->whereNull('revoked_at')->get(['id', 'revision', 'revoked_at']);
        DB::table('access_grants')->whereIn('id', $rows->pluck('id'))->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => DB::raw('revision+1')]);

        return $rows;
    });
    $app->make(QaInvalidation::class)->refreshDenied($trusted, $project, $operation, $key);
    $context->run($tenant, function () use ($grants) {
        foreach ($grants as $grant) {
            DB::table('access_grants')->where('id', $grant->id)->update(['revoked_at' => $grant->revoked_at, 'revision' => $grant->revision]);
        }
    });
    $observed = $context->run($tenant, fn () => DB::table('qa_invalidations')->where('project_id', $project)->exists());
    echo 'observed='.(int) $observed."\n";
    exit(0);
}
$processor = $app->make(QaMaintenanceProcessor::class);
$claim = $processor->claim($trusted, $project);
echo 'pid='.$identity->pid."\nclaim=".json_encode($claim, JSON_THROW_ON_ERROR)."\n";
flush();
if ($mode === 'crash') {
    exit(44);
}
if ($mode === 'pause' && trim(fgets(STDIN)) !== 'publish') {
    exit(91);
}
if (in_array($mode, ['pause', 'publish'], true) && $claim !== null) {
    echo 'result='.json_encode($processor->processClaim($trusted, $project, $claim), JSON_THROW_ON_ERROR)."\n";
}
