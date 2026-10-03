<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IdentityMappingService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function createSource(string $tenant, string $actor, array $data): object
    {
        return $this->insert('source_instances', $tenant, $actor, $data, 'identity.source_created');
    }

    public function createIdentity(string $tenant, string $actor, string $source, array $data): object
    {
        $this->find('source_instances', $source);

        return $this->insert('identities', $tenant, $actor, ['source_instance_id' => $source] + $data, 'identity.created');
    }

    public function propose(string $tenant, string $actor, string $identity, array $data): object
    {
        $this->find('identities', $identity);

        return $this->insert('identity_mappings', $tenant, $actor, [
            'identity_id' => $identity, 'employee_id' => $data['employee_id'], 'status' => 'candidate',
            'changed_by' => $actor, 'reason' => $data['reason'],
            'valid_from' => $this->utc($data['valid_from']), 'valid_to' => isset($data['valid_to']) ? $this->utc($data['valid_to']) : null,
        ], 'identity.mapping_proposed');
    }

    public function review(string $tenant, string $actor, string $mapping, array $data): object
    {
        return DB::transaction(function () use ($tenant, $actor, $mapping, $data): object {
            $this->find('identity_mappings', $mapping);
            $revision = $data['expected_revision'];
            if (DB::table('identity_mappings')->where('id', $mapping)->where('revision', $revision)->update([
                'employee_id' => $data['employee_id'], 'status' => $data['status'], 'reviewer_id' => $actor,
                'changed_by' => $actor, 'reason' => $data['reason'], 'reviewed_at' => now(),
                'valid_from' => $this->utc($data['valid_from']), 'valid_to' => isset($data['valid_to']) ? $this->utc($data['valid_to']) : null,
                'revision' => $revision + 1,
            ]) !== 1) {
                throw new ConflictHttpException;
            }
            $this->audit->record($actor, ['tenant_id' => $tenant], 'identity.mapping_reviewed', ['type' => 'identity_mappings', 'id' => $mapping], $revision + 1, 'identity_review');

            return $this->find('identity_mappings', $mapping);
        });
    }

    public function history(string $mapping, ?int $after): array
    {
        $this->find('identity_mappings', $mapping);

        return DB::table('identity_mapping_histories')->where('mapping_id', $mapping)->where('revision', '>', $after ?? 0)->orderBy('revision')->limit(101)->get()->all();
    }

    public function attribution(string $identity, string $at): ?object
    {
        $this->find('identities', $identity);
        $instant = CarbonImmutable::parse($at)->utc();

        return DB::table('identity_mappings')->where('identity_id', $identity)->where('status', 'approved')
            ->where('valid_from', '<=', $instant)->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>', $instant))
            ->first(['id as mapping_id', 'identity_id', 'employee_id', 'revision', 'valid_from', 'valid_to']);
    }

    private function insert(string $table, string $tenant, string $actor, array $data, string $action): object
    {
        return DB::transaction(function () use ($table, $tenant, $actor, $data, $action): object {
            $id = (string) Str::uuid();
            DB::table($table)->insert(['id' => $id, 'tenant_id' => $tenant] + $data);
            $this->audit->record($actor, ['tenant_id' => $tenant], $action, ['type' => $table, 'id' => $id], 1, 'identity_review');

            return $this->find($table, $id);
        });
    }

    private function find(string $table, string $id): object
    {
        return DB::table($table)->where('id', $id)->first() ?? throw new NotFoundHttpException;
    }

    private function utc(string $value): string
    {
        return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.uP');
    }
}
