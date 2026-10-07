<?php

namespace App\Console\Commands;

use App\Domain\Identity\LocalTokenAuthenticator;
use App\Domain\Qa\QaContract;
use App\Domain\Qa\QaFixtureService;
use App\Support\LocalQaDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class RegisterLocalQaFixture extends Command
{
    protected $signature = 'app:qa-fixture {project} {fixture : Local QA JSON fixture} {--token-file= : Existing local bearer token file} {--expected-source-revision= : Source-head CAS revision} {--idempotency-key= : Optional scoped ASCII request key}';

    protected $description = 'Register a bounded synthetic QA fixture through an existing authorized local actor';

    public function handle(LocalQaDatabase $guard, LocalTokenAuthenticator $auth, QaContract $contract, QaFixtureService $fixtures): int
    {
        try {
            $guard->assertRuntime();
            $project = $this->argument('project');
            $revision = $this->option('expected-source-revision');
            $tokenFile = $this->option('token-file');
            $fixtureFile = $this->argument('fixture');
            if (! is_string($project) || ! Str::isUuid($project) || ! is_string($revision)
                || ! preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D', $revision) || (int) $revision > 9007199254740991
                || ! is_string($tokenFile) || ! is_file($tokenFile) || ! is_string($fixtureFile) || ! is_file($fixtureFile)) {
                throw new LogicException;
            }
            // A token is read only from an explicit file, never from argv or fixture content.
            $token = file_get_contents($tokenFile, false, null, 0, 513);
            if (! is_string($token) || strlen($token) > 512 || ! ($actor = $auth->authenticate(trim($token)))) {
                throw new LogicException;
            }
            $raw = file_get_contents($fixtureFile, false, null, 0, 65537);
            if (! is_string($raw) || strlen($raw) > 65536) {
                throw new LogicException;
            }
            $envelope = $contract->validate('envelope', $contract->parse($raw));
            if (! in_array($envelope['operation'], ['build', 'environment', 'definition'], true)) {
                throw new LogicException;
            }
            $key = $this->option('idempotency-key');
            if ($key === null) {
                $key = hash('sha256', QaContract::canonical(array_intersect_key($envelope, array_flip(['operation', 'source_instance_id', 'object_id', 'source_revision']))));
            }
            if (! is_string($key) || ! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $key)) {
                throw new LogicException;
            }
            $result = $fixtures->register($actor, strtolower($project), $envelope, $key, (int) $revision);
            $this->line('receipt_id='.$result['receipt_id'].' record_id='.$result['record_id'].' source_head_revision='.$result['source_head_revision'].' replayed='.($result['replayed'] ? '1' : '0'));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Local QA fixture registration failed.');

            return self::FAILURE;
        }
    }
}
