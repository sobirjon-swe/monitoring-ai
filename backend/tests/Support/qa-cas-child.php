<?php

use App\Domain\Access\TrustedActor;
use App\Domain\Qa\QaDefectService;
use App\Domain\Qa\QaDefinitionService;
use App\Domain\Qa\QaHandoffService;
use App\Domain\Qa\QaPlanService;
use App\Domain\Qa\QaSelectionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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
[$script, $tenant, $actor, $project, $mode, $encoded] = $argv;
$pins = json_decode($encoded, false, 16, JSON_THROW_ON_ERROR);
$pins = json_decode(json_encode($pins, JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR);
foreach (['event', 'decision'] as $field) {
    if (isset($pins[$field]['pins']) && $pins[$field]['pins'] === []) {
        $pins[$field]['pins'] = new stdClass;
    }
}
echo 'ready pid='.$identity->pid."\n";
flush();
if (trim(fgets(STDIN)) !== 'go') {
    exit(91);
}
if (isset($pins['expire_after_insert'])) {
    $waiting = false;
    DB::listen(function ($query) use ($pins, &$waiting) {
        if ($waiting || ! str_starts_with($query->sql, 'insert into "audit_events"')) {
            return;
        }
        $waiting = true;
        echo "work_written=1\n";
        flush();
        // All event/head/change/outbox/audit writes exist. Cross the real boundary before final publication.
        while (DB::scalar('select clock_timestamp() < ?::timestamptz', [$pins['expire_after_insert']])) {
            DB::select('select pg_sleep(0.02)');
        }
    });
    unset($pins['expire_after_insert']);
}
try {
    $trusted = new TrustedActor($actor, $tenant);
    $result = match ($mode) {
        'selection' => $app->make(QaSelectionService::class)->select($trusted, $project, $pins),
        'plan-review' => $app->make(QaPlanService::class)->review($trusted, $project, $pins['plan_id'], $pins['decision']),
        'definition-review' => $app->make(QaDefinitionService::class)->review($trusted, $project, $pins['definition_id'], $pins['decision']),
        'defect' => $app->make(QaDefectService::class)->transition($trusted, $project, $pins['defect_id'], $pins['event']),
        'handoff' => $app->make(QaHandoffService::class)->transition($trusted, $project, $pins['handoff_id'], $pins['event']),
        default => throw new LogicException('Unsupported test child mode.'),
    };
    echo 'revision='.$result['revision']."\n";
} catch (ConflictHttpException) {
    echo "status=409\n";
} catch (AccessDeniedHttpException) {
    echo "status=403\n";
}
