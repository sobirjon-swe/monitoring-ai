<?php

namespace App\Domain\Analysis;

final readonly class AuthorizedSnapshot
{
    public function __construct(public string $artifactId, public string $hash, public array $payload) {}
}
