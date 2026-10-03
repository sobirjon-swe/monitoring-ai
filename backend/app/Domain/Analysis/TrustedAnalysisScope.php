<?php

namespace App\Domain\Analysis;

use App\Domain\Access\TrustedActor;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class TrustedAnalysisScope
{
    public function __construct(public TrustedActor $actor, public string $projectId, public string $purpose = 'analysis.context')
    {
        if (! Str::isUuid($projectId) || $purpose !== 'analysis.context') {
            throw new InvalidArgumentException('Invalid trusted analysis scope.');
        }
    }
}
