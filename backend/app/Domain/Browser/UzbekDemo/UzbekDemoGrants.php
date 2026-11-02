<?php

namespace App\Domain\Browser\UzbekDemo;

/** Original authority is fixture-specific, tokenless and confined to this manifest. */
final class UzbekDemoGrants
{
    public const READ = ['project.read', 'requirements.read', 'progress.read', 'ingestion.read', 'qa.read', 'qa.source.read', 'ba.read', 'ba.source.read', 'management.read', 'management.source.read', 'employees.read', 'roles.read'];

    public const TEMPORARY = ['requirements.approve', 'policy.approve', 'baseline.approve'];

    public const SOURCE = ['project.read', 'requirements.read', 'requirements.manage', 'progress.read', 'ingestion.accept', 'ingestion.read', 'qa.fixture.manage', 'qa.execution.record', 'qa.handoff.manage', 'qa.read', 'qa.source.read', 'ba.fixture.manage', 'ba.request.manage', 'ba.read', 'ba.source.read', 'management.action.manage', 'management.support.record', 'management.read', 'management.source.read'];

    public const REVIEWER = ['project.read', 'requirements.read', 'progress.read', 'ingestion.read', 'evidence.review', 'acceptance.review', 'qa.plan.manage', 'qa.read', 'qa.source.read'];

    public const ASSIGNER = ['project.read', 'requirements.read', 'progress.read', 'management.support.assign', 'management.read', 'management.source.read'];
}
