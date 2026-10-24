<?php

namespace App\Domain\Management;

use App\Domain\Access\TrustedActor;
use App\Support\LocalManagementDatabase;

final class ManagementFixtureService
{
    public function register(TrustedActor $actor, string $project, array $fixture, string $key, int $expectedSourceRevision): array
    {
        app(LocalManagementDatabase::class)->assertRuntime();

        return app(ManagementActionService::class)->openFixture($actor, $project, $fixture, $key, $expectedSourceRevision);
    }
}
