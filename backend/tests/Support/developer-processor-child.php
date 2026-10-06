<?php

// Fixed test-only worker: no production readiness flag or parser fallback exists.
use App\Domain\Access\TrustedActor;
use App\Domain\DeveloperAnalysis\AnalysisProcessor;
use App\Domain\DeveloperAnalysis\LocalParserRunner;
use App\Domain\DeveloperAnalysis\ResultGate;
use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeveloperProcessorFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne("select current_database() as database, current_user as role, r.rolsuper, r.rolbypassrls, shobj_description(d.oid,'pg_database') as marker from pg_database d join pg_roles r on r.rolname=current_user where d.datname=current_database()");
if (! $app->environment('testing') || $identity->database !== 'monitoring_ai_test' || $identity->role !== 'monitoring_ai_app'
    || $identity->rolsuper || $identity->rolbypassrls || ! str_starts_with($identity->marker ?? '', 'monitoring-ai-local-v1:')) {
    exit(90);
}
class DeveloperChildFixture
{
    use DeveloperProcessorFixtures;

    public function output(array $input): array
    {
        return $this->fixtureOutput($input);
    }
}
$fixture = new DeveloperChildFixture;
$app->instance(LocalParserRunner::class, new class($fixture) extends LocalParserRunner
{
    public function __construct(private DeveloperChildFixture $fixture) {}

    public function run(array $input): array
    {
        return $this->fixture->output($input);
    }
});
[$script, $tenant, $actor, $project, $run, $mode] = $argv;
$scope = new TrustedActor($actor, $tenant);
$processor = $app->make(AnalysisProcessor::class);
if ($mode === 'drain') {
    $result = $processor->drain($scope, $project, 1);
    echo 'processed='.$result['processed']."\n";
    exit(0);
}
$hint = $app->make(TenantContext::class)->run($tenant, fn () => DB::table('developer_runs')->where('project_id', $project)->where('id', $run)->first());
$claim = (new ReflectionMethod($processor, 'claim'))->invoke($processor, $scope, $project, $hint);
if (! $claim) {
    exit(91);
}
if ($mode === 'crash_claim') {
    exit(41);
}
$validated = $app->make(ResultGate::class)->validate($claim['input'], $fixture->output($claim['input']));
if ($mode === 'crash_compute') {
    exit(42);
}
echo "claimed\n";
flush();
if (trim(fgets(STDIN)) !== 'finish') {
    exit(92);
}
echo (new ReflectionMethod($processor, 'finish'))->invoke($processor, $scope, $project, $hint, $claim, $validated, null)."\n";
