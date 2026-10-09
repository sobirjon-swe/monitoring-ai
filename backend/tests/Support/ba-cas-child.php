<?php

use App\Domain\Access\TrustedActor;
use App\Domain\Ba\BaAnswerService;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaReviewService;
use App\Support\LocalQaDatabase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    exit(90);
}
$app->make(LocalQaDatabase::class)->assertRuntime();
if (DB::scalar('select current_database()') !== 'monitoring_ai_test') {
    exit(90);
}
[$script, $tenant, $actor, $project, $mode, $encoded, $key] = $argv;
$pins = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
echo 'ready pid='.DB::scalar('select pg_backend_pid()')."\n";
flush();
if (trim(fgets(STDIN)) !== 'go') {
    exit(91);
}
try {
    $trusted = new TrustedActor($actor, $tenant);
    match ($mode) {
        'answer' => $app->make(BaAnswerService::class)->record($trusted, $project, $pins['root'], $pins['envelope'], $key, $pins['source_cas']),
        'review' => $app->make(BaReviewService::class)->review($trusted, $project, $pins['root'], $pins['body'], $key),
        'reopen' => $app->make(BaClarificationService::class)->event($trusted, $project, $pins['root'], $pins['body'], $key),
        default => throw new LogicException('Unsupported BA test child mode.'),
    };
    echo "status=201\n";
} catch (ConflictHttpException) {
    echo "status=409\n";
}
