<?php

namespace App\Domain\Identity;

use App\Domain\Access\TrustedActor;
use Carbon\CarbonImmutable;

final readonly class VerifiedLocalCredential
{
    public function __construct(public TrustedActor $actor, public string $tokenId, public string $subjectId, public CarbonImmutable $expiresAt, public string $fingerprint, public ?string $employeeId, public string $displayName) {}
}
