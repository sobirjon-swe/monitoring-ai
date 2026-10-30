<?php

namespace App\Domain\Browser\BaDemo;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaFixtureService;
use App\Domain\Lifecycle\RequirementService;
use App\Domain\Lifecycle\TaskService;
use App\Support\LocalManagementDatabase;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** TEST-only business fixture. Credential output and commit receipt are separate responsibilities. */
final class BaDemoFixtureService
{
    public const TEMPORARY = ['requirements.manage', 'requirements.approve'];

    public function provision(BaDemoManifest $manifest, string $secretHash, CarbonInterface $expiresAt): array
    {
        if (! app()->environment('testing') || DB::transactionLevel() < 1 || config('database.connections.pgsql.database') !== 'monitoring_ai_test'
            || config('qa.runtime_role') !== 'monitoring_ai_app'
            || ! is_int(config('qa.local_port')) || config('qa.local_port') < 1 || config('qa.local_port') > 65535
            || config('qa.database_marker') !== 'monitoring-ai-local-v1:'
            || config('qa.local_databases') !== ['local' => 'monitoring_ai_dev', 'testing' => 'monitoring_ai_test']
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $secretHash)
            || $expiresAt->lessThanOrEqualTo(now()) || $expiresAt->greaterThan(now()->addHours(8))) {
            throw new LogicException('Synthetic BA fixture refused.');
        }
        app(LocalManagementDatabase::class)->assertRuntime();
        $m = $manifest->data;

        return DB::transaction(fn () => app(TenantContext::class)->run($m['tenant_id'], function () use ($manifest, $m, $secretHash, $expiresAt): array {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))', ['ba-demo:'.$m['tenant_id']]);
            if (DB::table('tenants')->where('id', $m['tenant_id'])->exists()) {
                throw new LogicException('Synthetic BA workspace already exists.');
            }
            $tenant = $m['tenant_id'];
            $project = $m['project_id'];
            DB::table('tenants')->insert(['id' => $tenant, 'name' => 'SYNTHETIC F02 BA workspace '.$manifest->hash]);
            DB::table('projects')->insert(['id' => $project, 'tenant_id' => $tenant, 'name' => 'SYNTHETIC F02 BA fictional data', 'timezone' => 'Asia/Tashkent']);
            DB::table('policy_versions')->insert(['id' => $m['policy_id'], 'tenant_id' => $tenant, 'version' => 1, 'status' => 'active']);
            DB::table('departments')->insert(['id' => $m['department_id'], 'tenant_id' => $tenant, 'name' => 'SYNTHETIC BA']);
            foreach (['writer', 'producer'] as $key) {
                $p = $m[$key];
                DB::table('employees')->insert(['id' => $p['employee_id'], 'tenant_id' => $tenant, 'display_name' => $p['name']]);
                DB::table('users')->insert(['id' => $p['user_id'], 'tenant_id' => $tenant, 'employee_id' => $p['employee_id'], 'display_name' => $p['name']]);
                DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'project_id' => $project, 'employee_id' => $p['employee_id'], 'valid_from' => now()->subMinute()]);
                DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'department_id' => $m['department_id'], 'employee_id' => $p['employee_id'], 'valid_from' => now()->subMinute()]);
                DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'project_id' => $project, 'employee_id' => $p['employee_id'], 'role' => $p['role'], 'valid_from' => now()->subMinute()]);
                foreach (array_merge($p['grants'], $key === 'producer' ? self::TEMPORARY : []) as $purpose) {
                    $id = (string) Str::uuid();
                    DB::table('access_grants')->insert(['id' => $id, 'tenant_id' => $tenant, 'user_id' => $p['user_id'], 'policy_version_id' => $m['policy_id'], 'purpose' => $purpose, 'scope_type' => 'project', 'project_id' => $project, 'valid_from' => now()->subMinute()]);
                    app(AuditWriter::class)->record($p['user_id'], ['tenant_id' => $tenant, 'project_id' => $project], 'access.setup', ['type' => 'access_grants', 'id' => $id], 1, 'local_setup');
                }
            }
            $writer = $m['writer'];
            DB::table('auth_subjects')->insert(['id' => $writer['subject_id'], 'tenant_id' => $tenant, 'user_id' => $writer['user_id'], 'issuer' => 'local-development', 'subject' => 'ba-demo-v1.'.$manifest->hash]);
            DB::table('auth_tokens')->insert(['id' => $writer['token_id'], 'tenant_id' => $tenant, 'auth_subject_id' => $writer['subject_id'], 'secret_hash' => $secretHash, 'expires_at' => $expiresAt]);
            DB::table('source_instances')->insert(['id' => $m['source_id'], 'tenant_id' => $tenant, 'kind' => 'manual', 'label' => 'SYNTHETIC F02 fixture']);
            DB::table('ingestion_connectors')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'project_id' => $project, 'source_instance_id' => $m['source_id'], 'adapter' => 'synthetic-v1', 'status' => 'active']);
            $actor = new TrustedActor($m['producer']['user_id'], $tenant);
            $requirements = app(RequirementService::class);
            $draft = $requirements->createDraft($actor, $project, ['title' => 'SYNTHETIC BA requirement', 'owner_id' => $writer['employee_id'], 'stakeholder' => 'Fictional customer', 'business_outcome' => 'Synthetic clarification', 'nfr_constraints' => ['Synthetic only'], 'dependencies' => [], 'criteria' => [['key' => 'F02-A', 'text' => 'SYNTHETIC criterion', 'required_test_keys' => ['synthetic-f02-verification']]]]);
            $requirement = $requirements->approve($actor, $project, $draft['id'], 1, 0, 'Synthetic fixture');
            $criterion = $requirement['criteria'][0];
            $task = app(TaskService::class)->create($actor, $project, ['title' => 'SYNTHETIC task', 'assignee_id' => $writer['employee_id'], 'estimate_basis' => 'Fictional', 'remaining_effort' => null, 'status' => 'in_progress', 'expected_test_keys' => ['synthetic-f02-verification'], 'criterion_links' => [['criterion_id' => $criterion['id'], 'criterion_version_id' => $criterion['version_id']]]], $requirement['generation']);
            $at = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
            $envelope = fn ($operation, $body, $object) => ['schema_version' => 'ba-evidence-v1', 'operation' => $operation, 'source_instance_id' => $m['source_id'], 'object_id' => 'synthetic-f02-'.$object, 'source_revision' => 1, 'occurred_at' => $at, 'source_updated_at' => $at, 'body' => $body];
            $party = app(BaFixtureService::class)->registerParty($actor, $project, $envelope('party.register', ['party_reference' => 'synthetic-f02-customer', 'party_kind' => 'customer', 'label' => 'SYNTHETIC customer — authenticity not verified', 'predecessor_party_version_id' => null], 'party'), 'synthetic-f02-party', 0);
            $result = app(BaClarificationService::class)->open($actor, $project, $envelope('request.open', ['question' => 'SYNTHETIC fictional customer clarification', 'requester_id' => $m['producer']['employee_id'], 'coordinator_id' => $writer['employee_id'], 'decision_party' => 'customer', 'decision_owner_id' => null, 'decision_owner_party_version_id' => $party['party_version_id'], 'requested_at' => $at, 'links' => [['task_version_id' => $task['version_id'], 'criterion_id' => $criterion['id'], 'criterion_version_id' => $criterion['version_id'], 'relation' => 'blocks']]], 'request'), 'synthetic-f02-request', 0);
            $ids = DB::table('access_grants')->where('user_id', $m['producer']['user_id'])->whereIn('purpose', self::TEMPORARY)->pluck('id');
            DB::table('access_grants')->whereIn('id', $ids)->update(['revoked_at' => now(), 'revision' => 2]);
            foreach ($ids as $id) {
                app(AuditWriter::class)->record($m['producer']['user_id'], ['tenant_id' => $tenant, 'project_id' => $project], 'access.setup', ['type' => 'access_grants', 'id' => $id], 2, 'access_change');
            }

            return ['tenant_id' => $tenant, 'project_id' => $project, 'clarification_id' => $result['clarification_id']];
        }));
    }
}
