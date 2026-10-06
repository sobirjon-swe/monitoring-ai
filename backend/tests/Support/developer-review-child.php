<?php

// Fixed test-only review worker; never activates the parser or contacts a source.
use App\Domain\Access\TrustedActor;
use App\Domain\DeveloperAnalysis\FindingReviewService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne("select current_database() as database,current_user as role,r.rolsuper,r.rolbypassrls,shobj_description(d.oid,'pg_database') as marker from pg_database d join pg_roles r on r.rolname=current_user where d.datname=current_database()");
if (! $app->environment('testing') || $identity->database !== 'monitoring_ai_test' || $identity->role !== 'monitoring_ai_app'
    || $identity->rolsuper || $identity->rolbypassrls || ! str_starts_with($identity->marker ?? '', 'monitoring-ai-local-v1:')
    || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1'], true)
    || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)) {
    exit(90);
}
[$script, $tenant, $actor, $finding] = $argv;
echo "ready\n";
flush();
if (trim(fgets(STDIN)) !== 'go') {
    exit(91);
}
try {
    $result = $app->make(FindingReviewService::class)->review(new TrustedActor($actor, $tenant), $finding, 0, 'verified', 'Concurrent synthetic review');
    echo 'revision='.$result['review_revision']."\n";
} catch (ConflictHttpException) {
    echo "status=409\n";
}
