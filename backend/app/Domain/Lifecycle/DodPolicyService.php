<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DodPolicyService
{
    public function __construct(private readonly ProjectCoordination $coordination, private readonly AuditWriter $audit) {}

    public function approve(TrustedActor $actor, string $projectId, array $data, int $expectedGeneration): array
    {
        if (count($data) !== 3 || ($data['mandatory_qa'] ?? null) !== true || ($data['human_acceptance'] ?? null) !== true || ($data['fictional'] ?? null) !== true) {
            throw ValidationException::withMessages(['policy' => 'Invalid fictional policy.']);
        }
        $r = $this->coordination->run($actor, $projectId, 'policy.approve', $expectedGeneration, function () use ($actor, $projectId, $data): array {
            $id = (string) Str::uuid();
            $version = (int) DB::table('dod_policy_versions')->where('project_id', $projectId)->max('version') + 1;
            ksort($data);
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $json);
            DB::table('dod_policy_versions')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $projectId, 'version' => $version, 'policy' => $json, 'content_hash' => $hash, 'approved_by' => $actor->id]);
            $this->audit->record($actor->id, ['tenant_id' => $actor->tenantId, 'project_id' => $projectId], 'policy.approved', ['type' => 'dod_policy_versions', 'id' => $id], $version, 'approved_change');

            return ['id' => $id, 'version' => $version, 'hash' => $hash];
        });

        return $r['result'] + ['generation' => $r['generation']];
    }
}
