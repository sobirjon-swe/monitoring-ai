<?php

namespace App\Domain\Browser\Demo;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaFixtureService;
use App\Domain\Lifecycle\BaselineService;
use App\Domain\Lifecycle\DodPolicyService;
use App\Domain\Lifecycle\RequirementService;
use App\Domain\Lifecycle\TaskService;
use App\Domain\Management\ManagementActionService;
use App\Domain\Management\ManagementSupportService;
use App\Domain\Qa\QaHandoffService;
use App\Support\LocalManagementDatabase;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class DemoWorkspaceService
{
    public const PRODUCER = 'f0100000-0000-4000-8000-000000000201';

    public const PRODUCER_EMPLOYEE = 'f0100000-0000-4000-8000-000000000200';

    public const ASSIGNER = 'f0100000-0000-4000-8000-000000000211';

    public const ASSIGNER_EMPLOYEE = 'f0100000-0000-4000-8000-000000000210';

    public const TEMPORARY = ['requirements.manage', 'requirements.approve', 'policy.approve', 'baseline.approve'];

    public function __construct(private readonly TenantContext $context, private readonly DemoCredentialOutput $output) {}

    public function prepare(DemoManifest $manifest, string $outputPath): array
    {
        $expected = app()->environment('testing') ? 'monitoring_ai_test' : 'monitoring_ai_dev';
        if (config('database.connections.pgsql.database') !== $expected || config('qa.runtime_role') !== 'monitoring_ai_app'
            || ! is_int(config('qa.local_port')) || config('qa.local_port') < 1 || config('qa.local_port') > 65535
            || config('qa.database_marker') !== 'monitoring-ai-local-v1:'
            || config('qa.local_databases') !== ['local' => 'monitoring_ai_dev', 'testing' => 'monitoring_ai_test']) {
            throw new LogicException('Synthetic setup runtime refused.');
        }
        app(LocalManagementDatabase::class)->assertRuntime();
        if ((app()->environment('local') && DB::transactionLevel() !== 0) || ! app()->environment(['local', 'testing'])) {
            throw new LogicException('Synthetic setup runtime refused.');
        }
        $m = $manifest->data;
        // File ownership, restrictive ACL and new-target reservation precede secrets and DB writes.
        try {
            $this->output->open($outputPath);
            $journal = $this->output->read();
            if ($journal === null) {
                $journal = ['version' => 'browser-demo-output-v1', 'manifest_hash' => $manifest->hash, 'tenant_id' => $m['tenant_id'], 'project_id' => $m['project_id'], 'expires_at' => CarbonImmutable::now('UTC')->addHours(8)->format('Y-m-d\TH:i:s.u\Z'), 'credentials' => [], 'snapshot' => null];
                foreach ($m['personas'] as $persona) {
                    $journal['credentials'][] = ['key' => $persona['key'], 'user_id' => $persona['user_id'], 'token_id' => $persona['token_id'], 'token' => $m['tenant_id'].'.'.$persona['token_id'].'.'.bin2hex(random_bytes(32))];
                }
                $this->output->write($journal);
            }
            $this->validateJournal($manifest, $journal);
            $replayed = $this->context->run($m['tenant_id'], function () use ($manifest, $m, &$journal): bool {
                // Serialize exact workspace retries without locking unrelated tenants.
                DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))', ['browser-demo:'.$m['tenant_id']]);
                if (DB::table('tenants')->where('id', $m['tenant_id'])->exists()) {
                    if (! is_array($journal['snapshot']) || $journal['snapshot'] !== $this->snapshot($m['tenant_id'])) {
                        throw new LogicException('Existing synthetic workspace is incompatible.');
                    }
                    $this->verifyCredentials($journal);

                    return true;
                }
                if (CarbonImmutable::parse($journal['expires_at'])->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                    throw new LogicException('Uncommitted synthetic credentials expired; explicit operator recovery is required.');
                }
                $this->identities($manifest, $journal);
                $this->facts($m);
                $this->verifyCredentials($journal);
                $journal['snapshot'] = $this->snapshot($m['tenant_id']);
                // Persist the commit receipt before PostgreSQL commits. A precommit crash rolls back
                // DB state; a postcommit crash resumes only after exact canonical/hash verification.
                $this->output->write($journal);

                return false;
            });
            if (CarbonImmutable::parse($journal['expires_at'])->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                throw new LogicException('Synthetic credentials expired; explicit operator recovery is required.');
            }
            $this->output->publish();

            return ['status' => $replayed ? 'replayed' : 'published', 'tenant_id' => $m['tenant_id'], 'project_id' => $m['project_id'], 'credentials_file' => $outputPath, 'expires_at' => $journal['expires_at'], 'personas' => array_map(fn ($p) => array_intersect_key($p, array_flip(['key', 'name', 'role', 'employee_id', 'user_id'])), $m['personas'])];
        } finally {
            $this->output->close();
        }
    }

    private function validateJournal(DemoManifest $manifest, array $journal): void
    {
        $m = $manifest->data;
        if (array_keys($journal) !== ['version', 'manifest_hash', 'tenant_id', 'project_id', 'expires_at', 'credentials', 'snapshot'] || $journal['version'] !== 'browser-demo-output-v1'
            || $journal['manifest_hash'] !== $manifest->hash || $journal['tenant_id'] !== $m['tenant_id'] || $journal['project_id'] !== $m['project_id']
            || ! is_string($journal['expires_at']) || ! preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z\z/D', $journal['expires_at']) || ! is_array($journal['credentials']) || ! array_is_list($journal['credentials']) || count($journal['credentials']) !== 6
            || ($journal['snapshot'] !== null && ! is_array($journal['snapshot']))) {
            throw new LogicException('Synthetic credential journal refused.');
        }
        if (CarbonImmutable::parse($journal['expires_at'])->greaterThan(CarbonImmutable::now('UTC')->addHours(8))) {
            throw new LogicException('Synthetic credential validity refused.');
        }
        foreach ($m['personas'] as $i => $p) {
            $credential = $journal['credentials'][$i];
            if (! is_array($credential) || array_keys($credential) !== ['key', 'user_id', 'token_id', 'token'] || $credential['key'] !== $p['key'] || $credential['user_id'] !== $p['user_id'] || $credential['token_id'] !== $p['token_id']
                || ! is_string($credential['token']) || ! preg_match('/\A'.preg_quote($m['tenant_id'].'.'.$p['token_id'].'.', '/').'[a-f0-9]{64}\z/D', $credential['token'])) {
                throw new LogicException('Synthetic credential journal refused.');
            }
        }
    }

    private function identities(DemoManifest $manifest, array $journal): void
    {
        $m = $manifest->data;
        $tenant = $m['tenant_id'];
        DB::table('tenants')->insert(['id' => $tenant, 'name' => 'SYNTHETIC F01 browser workspace '.$manifest->hash]);
        DB::table('projects')->insert(['id' => $m['project_id'], 'tenant_id' => $tenant, 'name' => 'SYNTHETIC F01 monitoring — fictional data', 'timezone' => 'Asia/Tashkent']);
        DB::table('policy_versions')->insert(['id' => $m['policy_id'], 'tenant_id' => $tenant, 'version' => 1, 'status' => 'active']);
        foreach (['technical_department_id' => 'SYNTHETIC Technical', 'ba_department_id' => 'SYNTHETIC BA'] as $key => $name) {
            DB::table('departments')->insert(['id' => $m[$key], 'tenant_id' => $tenant, 'name' => $name]);
        }
        $common = ['project.read', 'requirements.read', 'progress.read', 'ingestion.read'];
        $module = [
            'pm' => ['qa.read', 'qa.source.read', 'ba.read', 'ba.source.read', 'management.read', 'management.source.read'],
            'qa' => ['qa.read', 'qa.source.read'],
            'developer' => ['qa.read', 'qa.source.read', 'management.read', 'management.source.read'],
            'ba' => ['ba.read', 'ba.source.read', 'management.read', 'management.source.read'],
            'technical_head' => ['qa.read', 'qa.source.read', 'management.read', 'management.source.read', 'qa.department.read', 'management.department.read'],
            'ba_head' => ['ba.read', 'ba.source.read', 'management.read', 'management.source.read', 'ba.department.read', 'management.department.read'],
        ];
        foreach ($m['personas'] as $i => $p) {
            $this->principal($m, $p['user_id'], $p['employee_id'], 'SYNTHETIC '.$p['name'], [$p['role']], in_array($p['key'], ['ba', 'ba_head'], true) ? $m['ba_department_id'] : $m['technical_department_id']);
            DB::table('auth_subjects')->insert(['id' => $p['subject_id'], 'tenant_id' => $tenant, 'user_id' => $p['user_id'], 'issuer' => 'local-development', 'subject' => 'browser-demo-v1.'.$manifest->hash.'.'.$p['key']]);
            DB::table('auth_tokens')->insert(['id' => $p['token_id'], 'tenant_id' => $tenant, 'auth_subject_id' => $p['subject_id'], 'secret_hash' => hash('sha256', explode('.', $journal['credentials'][$i]['token'])[2]), 'expires_at' => $journal['expires_at']]);
            foreach ([...$common, ...$module[$p['key']]] as $purpose) {
                $department = str_contains($purpose, '.department.') ? (in_array($p['key'], ['ba', 'ba_head'], true) ? $m['ba_department_id'] : $m['technical_department_id']) : null;
                $this->grant($m, $p['user_id'], $purpose, $department);
            }
            foreach (['users' => $p['user_id'], 'auth_subjects' => $p['subject_id'], 'auth_tokens' => $p['token_id']] as $table => $id) {
                app(AuditWriter::class)->record($p['user_id'], ['tenant_id' => $tenant, 'project_id' => $m['project_id']], 'identity.setup', ['type' => $table, 'id' => $id], 1, 'local_setup');
            }
        }
        $this->principal($m, self::PRODUCER, self::PRODUCER_EMPLOYEE, 'SYNTHETIC tokenless source producer', ['pm', 'qa', 'ba'], $m['technical_department_id']);
        $this->principal($m, self::ASSIGNER, self::ASSIGNER_EMPLOYEE, 'SYNTHETIC tokenless assignment producer', ['department_head'], $m['technical_department_id']);
        foreach (['requirements.read', 'progress.read', 'qa.handoff.manage', 'qa.read', 'qa.source.read', 'ba.fixture.manage', 'ba.request.manage', 'ba.read', 'ba.source.read', 'management.action.manage', 'management.support.record', 'management.read', 'management.source.read', ...self::TEMPORARY] as $purpose) {
            $this->grant($m, self::PRODUCER, $purpose);
        }
        foreach (['requirements.read', 'progress.read', 'management.support.assign', 'management.source.read', 'management.read'] as $purpose) {
            $this->grant($m, self::ASSIGNER, $purpose);
        }
        $this->grant($m, self::ASSIGNER, 'management.department.manage', $m['technical_department_id']);
        DB::table('source_instances')->insert(['id' => $m['source_id'], 'tenant_id' => $tenant, 'kind' => 'manual', 'label' => 'SYNTHETIC F01 reviewed fixture v1']);
        DB::table('ingestion_connectors')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'project_id' => $m['project_id'], 'source_instance_id' => $m['source_id'], 'adapter' => 'synthetic-v1', 'status' => 'active']);
    }

    private function principal(array $m, string $user, string $employee, string $name, array $roles, string $department): void
    {
        $scope = ['tenant_id' => $m['tenant_id']];
        DB::table('employees')->insert(['id' => $employee, 'display_name' => $name] + $scope);
        DB::table('users')->insert(['id' => $user, 'employee_id' => $employee, 'display_name' => $name] + $scope);
        DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'project_id' => $m['project_id'], 'employee_id' => $employee, 'valid_from' => now()->subMinute()] + $scope);
        DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'department_id' => $department, 'employee_id' => $employee, 'valid_from' => now()->subMinute()] + $scope);
        foreach ($roles as $role) {
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'project_id' => $m['project_id'], 'employee_id' => $employee, 'role' => $role, 'valid_from' => now()->subMinute()] + $scope);
        }
    }

    private function grant(array $m, string $user, string $purpose, ?string $department = null): void
    {
        $id = (string) Str::uuid();
        DB::table('access_grants')->insert(['id' => $id, 'tenant_id' => $m['tenant_id'], 'user_id' => $user, 'policy_version_id' => $m['policy_id'], 'purpose' => $purpose, 'scope_type' => $department ? 'department' : 'project', 'project_id' => $department ? null : $m['project_id'], 'department_id' => $department, 'valid_from' => now()->subMinute()]);
        app(AuditWriter::class)->record($user, ['tenant_id' => $m['tenant_id'], 'project_id' => $m['project_id']], 'access.setup', ['type' => 'access_grants', 'id' => $id], 1, 'local_setup');
    }

    private function facts(array $m): void
    {
        $actor = new TrustedActor(self::PRODUCER, $m['tenant_id']);
        $project = $m['project_id'];
        $people = array_column($m['personas'], null, 'key');
        $requirements = app(RequirementService::class);
        $draft = $requirements->createDraft($actor, $project, ['title' => 'SYNTHETIC F01 evidence workflow', 'owner_id' => $people['ba']['employee_id'], 'stakeholder' => 'Fictional customer', 'business_outcome' => 'Demonstrate explicit missing verification and waiting obligations', 'nfr_constraints' => ['Synthetic local-only fixture'], 'dependencies' => [], 'criteria' => [['key' => 'F01-A', 'text' => 'SYNTHETIC criterion awaiting required QA and human acceptance', 'required_test_keys' => ['synthetic-f01-verification']]]]);
        $requirement = $requirements->approve($actor, $project, $draft['id'], 1, 0, 'Synthetic reviewed fixture');
        $criterion = $requirement['criteria'][0];
        $policy = app(DodPolicyService::class)->approve($actor, $project, ['fictional' => true, 'mandatory_qa' => true, 'human_acceptance' => true], $requirement['generation']);
        $baseline = app(BaselineService::class)->approve($actor, $project, [['criterion_version_id' => $criterion['version_id'], 'weight' => 100]], $policy['id'], null, $policy['generation'], 'Synthetic initial baseline');
        $task = app(TaskService::class)->create($actor, $project, ['title' => 'SYNTHETIC Steve — implementation awaiting verification', 'assignee_id' => $people['developer']['employee_id'], 'estimate_basis' => 'Fictional task; actual hours unavailable', 'remaining_effort' => null, 'status' => 'in_progress', 'expected_test_keys' => ['synthetic-f01-verification'], 'criterion_links' => [['criterion_id' => $criterion['id'], 'criterion_version_id' => $criterion['version_id']]]], $baseline['generation']);
        $at = $this->instant();
        $envelope = fn (string $schema, string $operation, array $body, string $object) => ['schema_version' => $schema, 'operation' => $operation, 'source_instance_id' => $m['source_id'], 'object_id' => 'synthetic-f01-'.$object, 'source_revision' => 1, 'occurred_at' => $at, 'source_updated_at' => $at, 'body' => $body];
        app(QaHandoffService::class)->open($actor, $project, $envelope('qa-evidence-v1', 'handoff', ['task_version_id' => $task['version_id'], 'criterion_version_id' => $criterion['version_id'], 'handoff_type' => 'support_requested', 'owner_id' => $people['qa']['employee_id'], 'occurred_at' => $at, 'reason' => 'SYNTHETIC QA support requested; verification still missing'], 'handoff'), 'synthetic-f01-handoff');
        $party = app(BaFixtureService::class)->registerParty($actor, $project, $envelope('ba-evidence-v1', 'party.register', ['party_reference' => 'synthetic-f01-customer', 'party_kind' => 'customer', 'label' => 'SYNTHETIC customer — authenticity not verified', 'predecessor_party_version_id' => null], 'party'), 'synthetic-f01-party', 0);
        $link = ['task_version_id' => $task['version_id'], 'criterion_id' => $criterion['id'], 'criterion_version_id' => $criterion['version_id'], 'relation' => 'blocks'];
        app(BaClarificationService::class)->open($actor, $project, $envelope('ba-evidence-v1', 'request.open', ['question' => 'SYNTHETIC waiting for fictional customer acceptance details', 'requester_id' => $people['pm']['employee_id'], 'coordinator_id' => $people['ba']['employee_id'], 'decision_party' => 'customer', 'decision_owner_id' => null, 'decision_owner_party_version_id' => $party['party_version_id'], 'requested_at' => $at, 'links' => [$link]], 'request'), 'synthetic-f01-request', 0);
        $reference = ['kind' => 'lifecycle'] + $link;
        $action = app(ManagementActionService::class)->open($actor, $project, $envelope('management-evidence-v1', 'action.open', ['owner_id' => self::PRODUCER_EMPLOYEE, 'reason' => 'SYNTHETIC support coordination; underlying acceptance remains pending', 'references' => [$reference]], 'action'), 'synthetic-f01-action', 0);
        $assignment = app(ManagementActionService::class)->event(new TrustedActor(self::ASSIGNER, $m['tenant_id']), $project, $action['action_id'], ['operation' => 'assignment.set', 'expected_revision' => 1, 'owner_id' => self::PRODUCER_EMPLOYEE, 'helper_id' => $people['developer']['employee_id'], 'department_id' => $m['technical_department_id'], 'reason' => 'SYNTHETIC bounded support assignment'], 'synthetic-f01-assignment');
        $stored = DB::table('management_assignments')->where('id', $assignment['assignment_id'])->first();
        $supportAt = $this->instant();
        $support = $envelope('management-evidence-v1', 'support.record', ['action_id' => $action['action_id'], 'expected_revision' => 2, 'action_version_id' => $stored->action_version_id, 'action_hash' => $stored->action_hash, 'assignment_id' => $stored->id, 'assignment_hash' => $stored->assignment_hash, 'references' => [$reference], 'text' => 'SYNTHETIC coordination completed; QA and customer decision still pending', 'outcome' => 'completed', 'predecessor_support_id' => null], 'support');
        $support['occurred_at'] = $supportAt;
        $support['source_updated_at'] = $supportAt;
        app(ManagementSupportService::class)->record($actor, $project, $action['action_id'], $support, 'synthetic-f01-support', 0);
        // Approved exception retains tokenless source authority. Temporary lifecycle setup
        // authority is explicitly removed before any website credential is published.
        $temporary = DB::table('access_grants')->where('user_id', self::PRODUCER)->whereIn('purpose', self::TEMPORARY)->pluck('id');
        DB::table('access_grants')->whereIn('id', $temporary)->update(['revoked_at' => $this->instant(), 'revision' => 2]);
        foreach ($temporary as $grant) {
            app(AuditWriter::class)->record(self::PRODUCER, ['tenant_id' => $m['tenant_id'], 'project_id' => $project], 'access.setup', ['type' => 'access_grants', 'id' => $grant], 2, 'access_change');
        }
    }

    private function verifyCredentials(array $journal): void
    {
        foreach ($journal['credentials'] as $credential) {
            $row = DB::table('auth_tokens as t')->join('auth_subjects as s', 's.id', '=', 't.auth_subject_id')->where('t.id', $credential['token_id'])->where('s.user_id', $credential['user_id'])->whereNull('t.revoked_at')->whereNull('s.revoked_at')->select('t.secret_hash', 't.expires_at')->first();
            if (! $row || ! hash_equals($row->secret_hash, hash('sha256', explode('.', $credential['token'])[2])) || ! CarbonImmutable::parse($row->expires_at)->equalTo(CarbonImmutable::parse($journal['expires_at']))) {
                throw new LogicException('Synthetic credential binding refused.');
            }
        }
    }

    private function snapshot(string $tenant): array
    {
        $result = [];
        // Discover only public base tables with tenant_id. Exact ordered row hashes form
        // the durable recovery receipt; unrelated tenants never enter it or change it.
        $tables = DB::select("select c.table_name from information_schema.columns c join information_schema.tables t using(table_schema,table_name) where c.table_schema='public' and c.column_name='tenant_id' and t.table_type='BASE TABLE' order by c.table_name");
        foreach ($tables as $table) {
            $name = $table->table_name;
            if (! preg_match('/\A[a-z][a-z0-9_]*\z/D', $name)) {
                throw new LogicException('Synthetic snapshot inventory refused.');
            }
            $rows = DB::select('select to_jsonb(t)::text as value from "'.$name.'" t where tenant_id=?::uuid order by to_jsonb(t)::text', [$tenant]);
            $result[$name] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode(array_column($rows, 'value'), JSON_THROW_ON_ERROR))];
        }
        $result['tenants'] = ['count' => 1, 'sha256' => hash('sha256', json_encode(DB::table('tenants')->where('id', $tenant)->first(), JSON_THROW_ON_ERROR))];

        return $result;
    }

    private function instant(): string
    {
        return CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
