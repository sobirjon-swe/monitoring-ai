<?php

namespace App\Domain\Browser;

final class DemoProvenance
{
    // Approved uzbek-demo-v1 manifest; display names never attest synthetic provenance.
    private const TENANT = 'f1200000-0000-4000-8000-000000000001';

    private const PROJECTS = ['f1200000-0000-4000-8000-000000000010', 'f1200000-0000-4000-8000-000000000011', 'f1200000-0000-4000-8000-000000000012'];

    public static function synthetic(string $tenant, string $project): ?bool
    {
        return $tenant === self::TENANT && in_array($project, self::PROJECTS, true) ? true : null;
    }
}
