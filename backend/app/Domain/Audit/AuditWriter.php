<?php

namespace App\Domain\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuditWriter
{
    public function record(string $actor, array $scope, string $action, array $resource, int $revision, string $reason): void
    {
        foreach ([$actor, $scope['tenant_id'] ?? '', $resource['id'] ?? ''] as $id) {
            if (! Str::isUuid($id)) {
                throw new InvalidArgumentException('Invalid audit identifier.');
            }
        }
        foreach ([$action, $resource['type'] ?? '', $reason] as $code) {
            if (! preg_match('/\A[a-z][a-z0-9_.]{0,79}\z/D', $code)) {
                throw new InvalidArgumentException('Audit fields must be bounded codes.');
            }
        }
        if (! in_array($action, ['organization.created', 'organization.updated', 'organization.membership_created', 'project.created', 'project.updated', 'project.assignment_created', 'project.assignment_updated', 'identity.setup', 'access.setup', 'identity.source_created', 'identity.created', 'identity.mapping_proposed', 'identity.mapping_reviewed', 'connector.created'], true)
            || ! in_array($resource['type'], ['employees', 'departments', 'department_memberships', 'reporting_relationships', 'projects', 'project_memberships', 'role_assignments', 'users', 'auth_subjects', 'auth_tokens', 'policy_versions', 'access_grants', 'source_instances', 'identities', 'identity_mappings', 'ingestion_connectors'], true)) {
            throw new InvalidArgumentException('Unsupported audit action or resource.');
        }
        if (! in_array($reason, ['local_setup', 'approved_change', 'membership_change', 'identity_review', 'access_change'], true) || $revision < 1) {
            throw new InvalidArgumentException('Invalid audit reason or revision.');
        }
        $project = $scope['project_id'] ?? null;
        if ($project !== null && ! Str::isUuid($project)) {
            throw new InvalidArgumentException('Invalid project identifier.');
        }
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $scope['tenant_id'], 'actor_id' => $actor,
            'project_id' => $project, 'action' => $action, 'resource_type' => $resource['type'],
            'resource_id' => $resource['id'], 'revision' => $revision, 'reason_code' => $reason,
            'correlation_id' => request()->attributes->get('request_id') ?? (string) Str::uuid(),
            'occurred_at' => now(),
        ]);
    }
}
