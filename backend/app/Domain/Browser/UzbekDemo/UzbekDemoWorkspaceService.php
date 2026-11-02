<?php

namespace App\Domain\Browser\UzbekDemo;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Domain\Browser\Demo\DemoCredentialOutput;
use App\Domain\PeopleRoles\PeopleRolesService;
use App\Support\LocalManagementDatabase;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class UzbekDemoWorkspaceService
{
    public const SOURCE = 'f1200000-0000-4000-8000-000000000500';

    public const REVIEWER = 'f1200000-0000-4000-8000-000000000501';

    public const ASSIGNER = 'f1200000-0000-4000-8000-000000000502';

    public const OLD_TENANT = 'f0100000-0000-4000-8000-000000000001';

    public function __construct(private readonly TenantContext $context, private readonly DemoCredentialOutput $output) {}

    public function prepare(UzbekDemoManifest $manifest, string $outputPath): array
    {
        $expected = app()->environment('testing') ? 'monitoring_ai_test' : 'monitoring_ai_dev';
        if (! app()->environment(['local', 'testing']) || config('database.connections.pgsql.database') !== $expected
            || config('qa.runtime_role') !== 'monitoring_ai_app' || config('qa.database_marker') !== 'monitoring-ai-local-v1:'
            || ! is_int(config('qa.local_port')) || config('qa.local_port') < 1 || config('qa.local_port') > 65535
            || config('qa.local_databases') !== ['local' => 'monitoring_ai_dev', 'testing' => 'monitoring_ai_test']
            || (app()->environment('local') && DB::transactionLevel() !== 0)) {
            throw new LogicException('Uzbek demo runtime refused.');
        }
        app(LocalManagementDatabase::class)->assertRuntime();
        $m = $manifest->data;
        $old = $this->context->run(self::OLD_TENANT, fn () => $this->snapshot(self::OLD_TENANT));
        try {
            $this->output->open($outputPath);
            $journal = $this->output->read();
            if ($journal === null) {
                $journal = ['version' => 'uzbek-demo-output-v1', 'manifest_hash' => $manifest->hash, 'tenant_id' => $m['tenant_id'], 'expires_at' => CarbonImmutable::now('UTC')->addHours(8)->format('Y-m-d\TH:i:s.u\Z'), 'credentials' => [], 'snapshot' => null];
                foreach ($m['interactive'] as $p) {
                    $journal['credentials'][] = ['key' => $p['key'], 'user_id' => $p['user_id'], 'token_id' => $p['token_id'], 'token' => $m['tenant_id'].'.'.$p['token_id'].'.'.bin2hex(random_bytes(32))];
                }
                $this->output->write($journal);
            }
            $this->validateJournal($manifest, $journal);
            $replayed = $this->context->run($m['tenant_id'], function () use ($manifest, $m, &$journal): bool {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))', ['uzbek-demo:'.$m['tenant_id']]);
                if (DB::table('tenants')->where('id', $m['tenant_id'])->exists()) {
                    if (! is_array($journal['snapshot']) || $journal['snapshot'] !== $this->snapshot($m['tenant_id'])) {
                        throw new LogicException('Existing Uzbek demo conflicts with receipt.');
                    }
                    $this->verifyCredentials($journal);

                    return true;
                }
                $this->identities($manifest, $journal);
                foreach ($m['projects'] as $project) {
                    app(UzbekDemoScenario::class)->populate($m, $project);
                }
                $temporary = DB::table('access_grants')->where('user_id', self::SOURCE)->whereIn('purpose', UzbekDemoGrants::TEMPORARY)->pluck('id');
                DB::table('access_grants')->whereIn('id', $temporary)->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revision' => 2]);
                foreach ($temporary as $id) {
                    app(AuditWriter::class)->record(self::SOURCE, ['tenant_id' => $m['tenant_id']], 'access.setup', ['type' => 'access_grants', 'id' => $id], 2, 'access_change');
                }
                $this->verifyCredentials($journal);
                $journal['snapshot'] = $this->snapshot($m['tenant_id']);
                $this->output->write($journal);

                return false;
            });
            if ($old !== $this->context->run(self::OLD_TENANT, fn () => $this->snapshot(self::OLD_TENANT))) {
                throw new LogicException('Existing F01 preservation check failed.');
            }
            $this->validateJournal($manifest, $journal);
            $this->output->publish();

            return ['status' => $replayed ? 'replayed' : 'published', 'tenant_id' => $m['tenant_id'], 'projects' => $m['projects'], 'credentials_file' => $outputPath, 'expires_at' => $journal['expires_at'], 'old_tenant_fingerprint' => hash('sha256', json_encode($old, JSON_THROW_ON_ERROR))];
        } finally {
            $this->output->close();
        }
    }

    private function validateJournal(UzbekDemoManifest $manifest, array $journal): void
    {
        $m = $manifest->data;
        if (array_keys($journal) !== ['version', 'manifest_hash', 'tenant_id', 'expires_at', 'credentials', 'snapshot'] || $journal['version'] !== 'uzbek-demo-output-v1' || $journal['manifest_hash'] !== $manifest->hash || $journal['tenant_id'] !== $m['tenant_id'] || ! is_string($journal['expires_at']) || ! preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z\z/D', $journal['expires_at']) || ! is_array($journal['credentials']) || ! array_is_list($journal['credentials']) || count($journal['credentials']) !== 2 || ($journal['snapshot'] !== null && ! is_array($journal['snapshot']))) {
            throw new LogicException('Uzbek credential journal refused.');
        }
        $expires = CarbonImmutable::parse($journal['expires_at']);
        if ($expires->lessThanOrEqualTo(CarbonImmutable::now('UTC')) || $expires->greaterThan(CarbonImmutable::now('UTC')->addHours(8))) {
            throw new LogicException('Uzbek demo credential lifetime refused.');
        }
        foreach ($m['interactive'] as $i => $p) {
            $c = $journal['credentials'][$i];
            if (! is_array($c) || array_keys($c) !== ['key', 'user_id', 'token_id', 'token'] || $c['key'] !== $p['key'] || $c['user_id'] !== $p['user_id'] || $c['token_id'] !== $p['token_id'] || ! is_string($c['token']) || ! preg_match('/\A'.preg_quote($m['tenant_id'].'.'.$p['token_id'].'.', '/').'[a-f0-9]{64}\z/D', $c['token'])) {
                throw new LogicException('Uzbek credential binding refused.');
            }
        }
    }

    private function identities(UzbekDemoManifest $manifest, array $journal): void
    {
        $m = $manifest->data;
        $scope = ['tenant_id' => $m['tenant_id']];
        DB::table('tenants')->insert(['id' => $m['tenant_id'], 'name' => 'DEMO — fictional Uzbek IT workspace '.$manifest->hash]);
        DB::table('policy_versions')->insert(['id' => $m['policy_id'], 'version' => 1, 'status' => 'active'] + $scope);
        foreach (['technical_department_id' => 'DEMO IT', 'ba_department_id' => 'DEMO Tahlil'] as $key => $name) {
            DB::table('departments')->insert(['id' => $m[$key], 'name' => $name] + $scope);
        }
        foreach ($m['projects'] as $project) {
            DB::table('projects')->insert(['id' => $project['id'], 'name' => 'DEMO — '.$project['name'], 'timezone' => 'Asia/Tashkent'] + $scope);
        }
        foreach ($m['people'] as $p) {
            DB::table('employees')->insert(['id' => $p['employee_id'], 'display_name' => $p['name']] + $scope);
            DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'department_id' => $m[in_array($p['role'], ['ba'], true) ? 'ba_department_id' : 'technical_department_id'], 'employee_id' => $p['employee_id'], 'valid_from' => now()->subMinute()] + $scope);
            foreach ($m['projects'] as $project) {
                DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'project_id' => $project['id'], 'employee_id' => $p['employee_id'], 'valid_from' => now()->subMinute()] + $scope);
                DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'project_id' => $project['id'], 'employee_id' => $p['employee_id'], 'role' => $p['role'], 'valid_from' => now()->subMinute()] + $scope);
            }
        }
        // Native BA and management sources require explicit system responsibility roles.
        // The tokenless source shares a fictional employee, never interactive privileges.
        foreach ($m['projects'] as $project) {
            foreach (['ba', 'pm'] as $role) {
                DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'project_id' => $project['id'], 'employee_id' => $m['technical'][0]['employee_id'], 'role' => $role, 'valid_from' => now()->subMinute()] + $scope);
            }
        }
        foreach ([...$m['interactive'], ...$m['technical']] as $p) {
            $name = isset($p['subject_id']) ? collect($m['people'])->firstWhere('employee_id', $p['employee_id'])['name'] : 'DEMO technical tokenless '.$p['key'];
            DB::table('users')->insert(['id' => $p['user_id'], 'employee_id' => $p['employee_id'], 'display_name' => $name] + $scope);
        }
        foreach (['employees', 'departments', 'department_memberships', 'projects', 'project_memberships', 'role_assignments', 'users', 'policy_versions'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                app(AuditWriter::class)->record(self::SOURCE, $scope, 'identity.setup', ['type' => $table, 'id' => $row->id], 1, 'local_setup');
            }
        }
        foreach ($m['interactive'] as $i => $p) {
            DB::table('auth_subjects')->insert(['id' => $p['subject_id'], 'user_id' => $p['user_id'], 'issuer' => 'local-development', 'subject' => 'uzbek-demo-v1.'.$manifest->hash.'.'.$p['key']] + $scope);
            DB::table('auth_tokens')->insert(['id' => $p['token_id'], 'auth_subject_id' => $p['subject_id'], 'secret_hash' => hash('sha256', explode('.', $journal['credentials'][$i]['token'])[2]), 'expires_at' => $journal['expires_at']] + $scope);
            foreach (['auth_subjects' => $p['subject_id'], 'auth_tokens' => $p['token_id']] as $table => $id) {
                app(AuditWriter::class)->record(self::SOURCE, $scope, 'identity.setup', ['type' => $table, 'id' => $id], 1, 'local_setup');
            }
            foreach ($m['projects'] as $project) {
                foreach ([...UzbekDemoGrants::READ, ...($p['key'] === 'pm' ? ['roles.manage'] : [])] as $purpose) {
                    $this->grant($m, $p['user_id'], $purpose, $project['id'], null, $journal['expires_at']);
                }
            }
            foreach (['role_catalog.read', ...($p['key'] === 'admin' ? ['role_catalog.manage'] : [])] as $purpose) {
                $this->grant($m, $p['user_id'], $purpose, null, null, $journal['expires_at']);
            }
        }
        foreach ($m['projects'] as $project) {
            foreach ([self::SOURCE => [...UzbekDemoGrants::SOURCE, ...UzbekDemoGrants::TEMPORARY], self::REVIEWER => UzbekDemoGrants::REVIEWER, self::ASSIGNER => UzbekDemoGrants::ASSIGNER] as $user => $purposes) {
                foreach ($purposes as $purpose) {
                    $this->grant($m, $user, $purpose, $project['id']);
                }
            }
        }
        $this->grant($m, self::ASSIGNER, 'management.department.manage', null, $m['technical_department_id']);
        DB::table('source_instances')->insert(['id' => $m['source_id'], 'kind' => 'manual', 'label' => 'DEMO Uzbek synthetic canonical receipts v1'] + $scope);
        foreach ($m['projects'] as $project) {
            DB::table('ingestion_connectors')->insert(['id' => (string) Str::uuid(), 'project_id' => $project['id'], 'source_instance_id' => $m['source_id'], 'adapter' => 'synthetic-v1', 'status' => 'active'] + $scope);
        }
        $roles = [];
        $admin = new TrustedActor($m['interactive'][1]['user_id'], $m['tenant_id']);
        $pm = new TrustedActor($m['interactive'][0]['user_id'], $m['tenant_id']);
        foreach ($m['people'] as $p) {
            $roles[$p['catalog_role']] ??= app(PeopleRolesService::class)->create($admin, ['request_id' => (string) Str::uuid(), 'name' => $p['catalog_role'], 'description' => 'DEMO — descriptive responsibility, no implicit system permissions']);
            foreach ($m['projects'] as $project) {
                app(PeopleRolesService::class)->assign($pm, $project['id'], ['employee_id' => $p['employee_id'], 'role_catalog_id' => $roles[$p['catalog_role']]['item']['id'], 'expected_role_revision' => 1, 'request_id' => (string) Str::uuid()]);
            }
        }
    }

    private function grant(array $m, string $user, string $purpose, ?string $project = null, ?string $department = null, ?string $expires = null): void
    {
        $id = (string) Str::uuid();
        DB::table('access_grants')->insert(['id' => $id, 'tenant_id' => $m['tenant_id'], 'user_id' => $user, 'policy_version_id' => $m['policy_id'], 'purpose' => $purpose, 'scope_type' => $department ? 'department' : ($project ? 'project' : 'tenant'), 'project_id' => $project, 'department_id' => $department, 'valid_from' => now()->subMinute(), 'valid_to' => $expires]);
        app(AuditWriter::class)->record($user, array_filter(['tenant_id' => $m['tenant_id'], 'project_id' => $project]), 'access.setup', ['type' => 'access_grants', 'id' => $id], 1, 'local_setup');
    }

    private function verifyCredentials(array $journal): void
    {
        foreach ($journal['credentials'] as $c) {
            $row = DB::table('auth_tokens as t')->join('auth_subjects as s', 's.id', '=', 't.auth_subject_id')->where('t.id', $c['token_id'])->where('s.user_id', $c['user_id'])->whereNull('t.revoked_at')->whereNull('s.revoked_at')->select('t.secret_hash', 't.expires_at')->first();
            if (! $row || ! hash_equals($row->secret_hash, hash('sha256', explode('.', $c['token'])[2])) || ! CarbonImmutable::parse($row->expires_at)->equalTo(CarbonImmutable::parse($journal['expires_at']))) {
                throw new LogicException('Uzbek credential binding refused.');
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
}
