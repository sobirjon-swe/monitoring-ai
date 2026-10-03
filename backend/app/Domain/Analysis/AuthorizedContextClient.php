<?php

namespace App\Domain\Analysis;

use App\Domain\Access\AccessGateway;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AuthorizedContextClient
{
    public function __construct(private readonly AccessGateway $access, private readonly SyntheticArtifactRegistry $registry, private readonly AnalysisSchemaValidator $schemas) {}

    public function getSnapshot(TrustedAnalysisScope $scope, string $artifactId): AuthorizedSnapshot
    {
        $this->access->authorize($scope->actor, $scope->purpose, ['tenant_id' => $scope->actor->tenantId, 'project_id' => $scope->projectId]);
        if (! Str::isUuid($artifactId)) {
            throw new AccessDeniedHttpException;
        }
        $artifact = $this->registry->get($artifactId);
        if (($artifact['tenant_id'] ?? null) !== $scope->actor->tenantId
            || ($artifact['project_id'] ?? null) !== $scope->projectId || ($artifact['approved'] ?? null) !== true) {
            throw new AccessDeniedHttpException;
        }
        $payload = [
            'schema_version' => $artifact['schema_version'] ?? null,
            'tenant_id' => $scope->actor->tenantId, 'project_id' => $scope->projectId, 'actor_id' => $scope->actor->id,
            'purpose' => $scope->purpose, 'artifact_id' => $artifactId, 'artifact_version' => $artifact['artifact_version'] ?? null,
            'evidence_ids' => $artifact['evidence_ids'] ?? null, 'content' => $artifact['content'] ?? null, 'coverage' => $artifact['coverage'] ?? null,
        ];
        $hash = hash('sha256', $this->canonical($payload));
        $payload['snapshot_hash'] = $hash;
        $this->schemas->validate('context', $payload);

        return new AuthorizedSnapshot($artifactId, $hash, $payload);
    }

    public function validateOutput(TrustedAnalysisScope $scope, AuthorizedSnapshot $snapshot, array $output): void
    {
        // A constructed snapshot is data, not authorization. Recheck revocation and registry binding.
        $current = $this->getSnapshot($scope, $snapshot->artifactId);
        if (! hash_equals($current->hash, $snapshot->hash) || $this->canonical($current->payload) !== $this->canonical($snapshot->payload)) {
            throw new AccessDeniedHttpException;
        }
        $this->schemas->validate('output', $output);
        if ($output['tenant_id'] !== $scope->actor->tenantId || $output['project_id'] !== $scope->projectId
            || $output['purpose'] !== $scope->purpose || ! hash_equals($current->hash, $output['input_snapshot_hash'])) {
            throw new AccessDeniedHttpException;
        }
        foreach ($output['findings'] as $finding) {
            if (array_diff($finding['evidence_ids'], $current->payload['evidence_ids'])) {
                throw new AccessDeniedHttpException;
            }
        }
    }

    private function canonical(array $payload): string
    {
        return json_encode($this->sorted($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => $this->sorted($item), $value);
    }
}
