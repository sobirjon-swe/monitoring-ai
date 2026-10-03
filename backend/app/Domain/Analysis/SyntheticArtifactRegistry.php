<?php

namespace App\Domain\Analysis;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class SyntheticArtifactRegistry
{
    // The default registry is empty: E01 exposes no real artifact retrieval or source connection.
    public function __construct(private array $artifacts = []) {}

    public function get(string $artifact): array
    {
        return $this->artifacts[$artifact] ?? throw new NotFoundHttpException;
    }
}
