<?php

use App\Domain\Access\TrustedActor;
use App\Domain\PeopleRoles\PeopleRolesService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne('select pg_backend_pid() as pid,current_database() as database,current_user as role,r.rolsuper,r.rolbypassrls from pg_roles r where r.rolname=current_user');
if (! $app->environment('testing') || $identity->database !== 'monitoring_ai_test' || $identity->role !== 'monitoring_ai_app' || $identity->rolsuper || $identity->rolbypassrls) {
    exit(90);
}
foreach (['pgsql', 'pgsql_migration'] as $name) {
    $config = config('database.connections.'.$name);
    if (! in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true) || (int) $config['port'] !== (int) config('qa.local_port') || ! in_array($config['url'] ?? null, [null, ''], true) || ! empty($config['read']) || ! empty($config['write'])) {
        exit(90);
    }
}
[$script,$tenant,$actor,$project,$encoded] = $argv;
echo 'ready pid='.$identity->pid."\n";
flush();
if (trim(fgets(STDIN)) !== 'go') {
    exit(91);
}
try {
    $result = $app->make(PeopleRolesService::class)->assign(new TrustedActor($actor, $tenant), $project, json_decode($encoded, true, 16, JSON_THROW_ON_ERROR));
    echo "status=201\n";
} catch (HttpExceptionInterface $e) {
    echo 'status='.$e->getStatusCode()."\n";
}
