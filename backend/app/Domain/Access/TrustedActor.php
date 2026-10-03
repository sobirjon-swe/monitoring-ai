<?php

namespace App\Domain\Access;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class TrustedActor
{
    public function __construct(public string $id, public string $tenantId)
    {
        if (! Str::isUuid($id) || ! Str::isUuid($tenantId)) {
            throw new InvalidArgumentException('Invalid actor context.');
        }
    }
}
