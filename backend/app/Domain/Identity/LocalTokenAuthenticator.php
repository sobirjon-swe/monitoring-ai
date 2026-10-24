<?php

namespace App\Domain\Identity;

use App\Domain\Access\TrustedActor;

class LocalTokenAuthenticator
{
    public function __construct(private readonly LocalCredentialVerifier $verifier) {}

    public function authenticate(?string $token): ?TrustedActor
    {
        return $this->verifier->verifyToken($token)?->actor;
    }
}
