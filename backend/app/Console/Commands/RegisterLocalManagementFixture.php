<?php

namespace App\Console\Commands;

use App\Domain\Identity\LocalTokenAuthenticator;
use App\Domain\Management\ManagementContract;
use App\Domain\Management\ManagementFixtureService;
use App\Support\LocalManagementDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class RegisterLocalManagementFixture extends Command
{
    protected $signature = 'app:register-local-management-fixture {project} {fixture : Local synthetic management JSON fixture} {--token-file= : Existing authorized bearer token file} {--expected-source-revision= : Source-head CAS revision} {--idempotency-key= : Scoped ASCII request key}';

    protected $description = 'Register an explicit synthetic management action through an existing authorized local actor';

    public function handle(LocalManagementDatabase $guard, LocalTokenAuthenticator $auth, ManagementContract $contract, ManagementFixtureService $fixtures): int
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
                || ! $this->plainLocalPath($tokenFile) || ! $this->plainLocalPath($fixtureFile)
                || ! is_file($tokenFile) || ! is_file($fixtureFile)) {
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
            if ($envelope['operation'] !== 'action.open') {
                throw new LogicException;
            }
            $result = $fixtures->register($actor, strtolower($project), $envelope, $key, (int) $revision);
            $this->line('receipt_id='.$result['receipt_id'].' action_id='.$result['action_id'].' replayed='.($result['replayed'] ? '1' : '0'));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Local management fixture registration failed.');

            return self::FAILURE;
        }
    }

    private function plainLocalPath(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        // Normalize only for lexical admission; never decode or alter the path used for I/O.
        $windows = str_replace('/', '\\', $path);
        if (str_starts_with($windows, '\\\\') || str_starts_with($windows, '\\??\\')) {
            return false;
        }
        $withoutDrive = preg_match('/\A[A-Za-z]:/', $path) ? substr($path, 2) : $path;
        if (str_contains($withoutDrive, ':')) {
            return false;
        }

        // Windows recognizes device aliases after component suffix normalization, including extensions.
        foreach (explode('\\', str_replace('/', '\\', $withoutDrive)) as $component) {
            $name = rtrim(explode('.', rtrim($component, ' .'), 2)[0], ' ');
            if (preg_match('/\A(?:CON|PRN|AUX|NUL|CONIN\$|CONOUT\$|COM[1-9¹²³]|LPT[1-9¹²³])\z/iuD', $name)) {
                return false;
            }
        }

        return true;
    }
}
