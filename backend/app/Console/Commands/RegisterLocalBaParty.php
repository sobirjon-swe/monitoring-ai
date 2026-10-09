<?php

namespace App\Console\Commands;

use App\Domain\Ba\BaContract;
use App\Domain\Ba\BaFixtureService;
use App\Domain\Identity\LocalTokenAuthenticator;
use App\Support\LocalBaDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class RegisterLocalBaParty extends Command
{
    protected $signature = 'app:register-local-ba-party {project} {fixture : Local synthetic party JSON fixture} {--token-file= : Existing authorized bearer token file} {--expected-source-revision= : Source-head CAS revision} {--idempotency-key= : Scoped ASCII request key}';

    protected $description = 'Register an explicit synthetic BA source party through an existing authorized local actor';

    public function handle(LocalBaDatabase $guard, LocalTokenAuthenticator $auth, BaContract $contract, BaFixtureService $fixtures): int
    {
        try {
            $guard->assertRuntime();
            $project = $this->argument('project');
            $revision = $this->option('expected-source-revision');
            $tokenFile = $this->option('token-file');
            $fixtureFile = $this->argument('fixture');
            $key = $this->option('idempotency-key');
            if (! is_string($project) || ! Str::isUuid($project) || ! is_string($revision)
                || ! preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D', $revision) || (int) $revision > 9007199254740991
                || ! is_string($key) || ! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $key)
                || ! is_string($tokenFile) || ! is_file($tokenFile) || ! is_string($fixtureFile) || ! is_file($fixtureFile)) {
                throw new LogicException;
            }
            // Tokens are accepted only from an explicit local file, never argv or fixture content.
            $token = file_get_contents($tokenFile, false, null, 0, 513);
            if (! is_string($token) || strlen($token) > 512 || ! ($actor = $auth->authenticate(trim($token)))) {
                throw new LogicException;
            }
            $raw = file_get_contents($fixtureFile, false, null, 0, 65537);
            if (! is_string($raw) || strlen($raw) > 65536) {
                throw new LogicException;
            }
            $envelope = $contract->validate('envelope', $contract->parse($raw));
            if ($envelope['operation'] !== 'party.register') {
                throw new LogicException;
            }
            $result = $fixtures->registerParty($actor, strtolower($project), $envelope, $key, (int) $revision);
            $this->line('receipt_id='.$result['receipt_id'].' party_version_id='.$result['party_version_id'].' replayed='.($result['replayed'] ? '1' : '0'));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Local BA party registration failed.');

            return self::FAILURE;
        }
    }
}
