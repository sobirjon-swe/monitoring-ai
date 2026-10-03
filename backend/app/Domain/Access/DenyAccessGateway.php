<?php

namespace App\Domain\Access;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class DenyAccessGateway implements AccessGateway
{
    public function authorize(TrustedActor $actor, string $purpose, array $scope): void
    {
        throw new AccessDeniedHttpException;
    }
}
