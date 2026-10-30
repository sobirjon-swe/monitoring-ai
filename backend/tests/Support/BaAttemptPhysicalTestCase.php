<?php

namespace Tests\Support;

use App\Domain\Access\TrustedActor;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaFixtureService;
use App\Domain\Lifecycle\BaselineService;
use App\Domain\Lifecycle\DodPolicyService;
use App\Domain\Lifecycle\RequirementService;
use App\Domain\Lifecycle\TaskService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class BaAttemptPhysicalTestCase extends TestCase
{
    protected string $tenant;

    protected string $user;

    protected string $employee;

    protected string $token;

    protected string $policy;

    protected string $p;

    protected string $policyId;

    protected string $readGrant;

    protected string $connector;

    protected array $criteria;

    protected array $envelope;

    protected array $draft;

    protected int $generation;

    protected ?string $baselineId = null;

    private string $physicalDatabase;

    private string $physicalMarker;

    protected function setUp(): void
    {
        parent::setUp();
        $manifest = getenv('F02_PHYSICAL_MANIFEST');
        if (! is_string($manifest) || $manifest === '') {
            $this->markTestSkipped('Disposable physical-commit manifest not supplied.');
        }
        $path = realpath($manifest);
        $dir = realpath(base_path('../.local/f02-disposable-test'));
        if ($path === false || $dir === false || dirname($path) !== $dir) {
            throw new \RuntimeException('Disposable manifest location refused.');
        }
        $m = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $run = $m['run'] ?? null;
        if (! is_string($run) || ! preg_match('/\A[0-9a-f]{32}\z/D', $run)
            || basename($path) !== $run.'.json' || ($m['database'] ?? null) !== 'monitoring_ai_f02_commit_'.$run
            || ($m['marker'] ?? null) !== 'monitoring-ai-f02-disposable-v1:'.$run) {
            throw new \RuntimeException('Disposable manifest identity refused.');
        }
        foreach (array_keys(getenv()) as $key) {
            if (str_starts_with(strtoupper($key), 'PG')) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
        $config = config('database.connections.pgsql');
        if (! app()->environment('testing') || config('database.default') !== 'pgsql'
            || ($config['driver'] ?? null) !== 'pgsql' || ($config['host'] ?? null) !== '127.0.0.1'
            || (string) ($config['port'] ?? '') !== (string) config('qa.local_port') || ($config['username'] ?? null) !== 'monitoring_ai_app'
            || ! in_array($config['url'] ?? null, [null, ''], true) || ! empty($config['read'])
            || ! empty($config['write']) || ! empty($config['options'])) {
            throw new \RuntimeException('Disposable runtime connection refused.');
        }
        $this->physicalDatabase = $m['database'];
        $this->physicalMarker = $m['marker'];
        config(['database.connections.pgsql.database' => $this->physicalDatabase]);
        DB::purge('pgsql');
        $this->assertPhysicalConnection(DB::connection('pgsql'));
        config(['qa.local_databases.testing' => $this->physicalDatabase, 'qa.database_marker' => $this->physicalMarker,
            'logging.default' => 'null', 'session.driver' => 'array', 'session.secure' => false]);
        app('url')->forceRootUrl('http://127.0.0.1:18080');
        $this->withServerVariables(['HTTP_HOST' => '127.0.0.1:18080', 'SERVER_PORT' => 18080]);
        $this->tenant = (string) Str::uuid();
        $this->user = (string) Str::uuid();
        $this->employee = (string) Str::uuid();
        $this->policy = (string) Str::uuid();
    }

    protected function reconnectRuntime(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \RuntimeException('Cannot reconnect inside a transaction.');
        }
        DB::purge('pgsql');
        $this->assertPhysicalConnection(DB::connection('pgsql'));
    }

    protected function observer(): Connection
    {
        config(['database.connections.ba_physical_observer' => config('database.connections.pgsql')]);
        DB::purge('ba_physical_observer');
        $connection = DB::connection('ba_physical_observer');
        $this->assertPhysicalConnection($connection);
        if ($connection->scalar('select pg_backend_pid()') === DB::scalar('select pg_backend_pid()')) {
            throw new \RuntimeException('Observer must be a distinct PostgreSQL backend.');
        }

        return $connection;
    }

    private function assertPhysicalConnection(Connection $connection): void
    {
        $identity = $connection->selectOne("select current_database() as db, session_user as login, current_user as role, inet_server_addr()::text as address, inet_server_port() as port, r.rolsuper, r.rolcreatedb, r.rolcreaterole, r.rolreplication, r.rolbypassrls, pg_get_userbyid(d.datdba) as owner, shobj_description(d.oid,'pg_database') as marker from pg_database d join pg_roles r on r.rolname=current_user where d.datname=current_database()");
        if ($identity->db !== $this->physicalDatabase || $identity->login !== 'monitoring_ai_app'
            || $identity->role !== 'monitoring_ai_app' || ! in_array($identity->address, ['127.0.0.1', '127.0.0.1/32'], true)
            || (int) $identity->port !== (int) config('qa.local_port') || $identity->rolsuper || $identity->rolcreatedb || $identity->rolcreaterole || $identity->rolreplication || $identity->rolbypassrls
            || $identity->owner !== 'monitoring_ai_owner' || $identity->marker !== $this->physicalMarker
            || $connection->scalar("select exists(select 1 from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and c.relkind in ('r','p') and pg_get_userbyid(c.relowner)=current_user)")
            || $connection->scalar("select exists(select 1 from pg_roles r where r.rolname <> current_user and pg_has_role(current_user, r.oid, 'MEMBER'))")) {
            throw new \RuntimeException('Disposable runtime identity refused.');
        }
    }

    protected function tearDown(): void
    {
        if (app()->bound('db')) {
            DB::purge('ba_physical_observer');
            DB::purge('pgsql');
        }
        parent::tearDown();
    }

    protected function fixture(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('auth_tokens'), 'Local identity substrate is missing.');
        app(TenantContext::class)->run($this->tenant, function (): void {
            DB::table('tenants')->insert(['id' => $this->tenant, 'name' => 'Synthetic access tenant']);
            DB::table('employees')->insert(['id' => $this->employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic employee']);
            DB::table('users')->insert(['id' => $this->user, 'tenant_id' => $this->tenant, 'employee_id' => $this->employee, 'display_name' => 'Synthetic user']);
            $subject = (string) Str::uuid();
            DB::table('auth_subjects')->insert(['id' => $subject, 'tenant_id' => $this->tenant, 'user_id' => $this->user, 'issuer' => 'local-development', 'subject' => $this->user]);
            $tokenId = (string) Str::uuid();
            $secret = bin2hex(random_bytes(32));
            $this->token = $this->tenant.'.'.$tokenId.'.'.$secret;
            DB::table('auth_tokens')->insert(['id' => $tokenId, 'tenant_id' => $this->tenant, 'auth_subject_id' => $subject, 'secret_hash' => hash('sha256', $secret), 'expires_at' => now()->addHour()]);
            DB::table('policy_versions')->insert(['id' => $this->policy, 'tenant_id' => $this->tenant, 'version' => 1, 'status' => 'active']);
        });
    }

    protected function grant(string $purpose, ?string $project = null, ?string $department = null): string
    {
        return app(TenantContext::class)->run($this->tenant, function () use ($purpose, $project, $department): string {
            $id = (string) Str::uuid();
            DB::table('access_grants')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'user_id' => $this->user, 'policy_version_id' => $this->policy, 'purpose' => $purpose, 'scope_type' => $project ? 'project' : ($department ? 'department' : 'tenant'), 'project_id' => $project, 'department_id' => $department, 'valid_from' => now()->subDay()]);

            return $id;
        });
    }

    protected function project(): string
    {
        return app(TenantContext::class)->run($this->tenant, function (): string {
            $project = (string) Str::uuid();
            DB::table('projects')->insert(['id' => $project, 'tenant_id' => $this->tenant, 'name' => 'Synthetic project']);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $project, 'employee_id' => $this->employee, 'valid_from' => now()->subDay()]);

            return $project;
        });
    }

    protected function lifecycle(): string
    {
        $this->fixture();
        $p = $this->project();
        foreach (['requirements.read', 'requirements.manage', 'requirements.approve', 'policy.approve'] as $purpose) {
            $this->grant($purpose, $p);
        }

        return $p;
    }

    protected function actor(): TrustedActor
    {
        return new TrustedActor($this->user, $this->tenant);
    }

    protected function requirement(): array
    {
        return ['title' => 'Synthetic requirement', 'owner_id' => $this->employee, 'stakeholder' => 'Synthetic stakeholder', 'business_outcome' => 'Synthetic outcome', 'nfr_constraints' => ['Synthetic NFR'], 'dependencies' => [], 'criteria' => [['key' => 'A', 'text' => 'Reject', 'required_test_keys' => ['test-a']], ['key' => 'B', 'text' => 'Verify', 'required_test_keys' => ['test-b']]]];
    }

    protected function requestInput(?array $links = null): array
    {
        $party = $this->party();
        $task = $this->baTask();
        $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');

        return ['question' => 'Synthetic CRM duplicate rule?', 'requester_id' => $this->employee, 'coordinator_id' => $this->employee, 'decision_party' => 'customer', 'decision_owner_id' => null, 'decision_owner_party_version_id' => $party['party_version_id'], 'requested_at' => $at, 'links' => $links ?? [['task_version_id' => $task['version_id'], 'criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id'], 'relation' => 'blocks']]];
    }

    protected function openRequest(?array $body = null, string $object = 'question'): array
    {
        $body ??= $this->requestInput();

        return app(BaClarificationService::class)->open($this->actor(), $this->p, $this->baEnvelope('request.open', $body, $object), $object, 0) + ['body' => $body];
    }

    protected function baInputs(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        foreach (['ba.fixture.manage', 'ba.request.manage', 'ba.answer.record', 'ba.decision.confirm', 'ba.read', 'ba.source.read'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
    }

    protected function baRole(string $role = 'ba', bool $replacement = false): string
    {
        return app(TenantContext::class)->run($this->tenant, function () use ($role, $replacement) {
            $id = (string) Str::uuid();
            DB::table('role_assignments')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => $role, 'valid_from' => $replacement ? now() : now()->subDay()]);

            return $id;
        });
    }

    protected function baEnvelope(string $operation = 'party.register', array $body = [], string $object = 'customer-1', int $revision = 1): array
    {
        $source = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_connectors')->where('id', $this->connector)->value('source_instance_id'));

        return ['schema_version' => 'ba-evidence-v1', 'operation' => $operation, 'source_instance_id' => $source, 'object_id' => $object, 'source_revision' => $revision, 'occurred_at' => '2026-10-06T00:00:00.000000Z', 'source_updated_at' => '2026-10-06T00:00:00.000000Z', 'body' => $body ?: ['party_reference' => 'synthetic-customer', 'party_kind' => 'customer', 'label' => 'Synthetic customer', 'predecessor_party_version_id' => null]];
    }

    protected function party(array $envelope = [], string $key = 'party', int $expected = 0): array
    {
        return app(BaFixtureService::class)->registerParty($this->actor(), $this->p, $envelope ?: $this->baEnvelope(), $key, $expected);
    }

    protected function baTask(int $index = 0, ?string $assignee = null): array
    {
        $task = app(TaskService::class)->create($this->actor(), $this->p, ['title' => 'Synthetic task', 'assignee_id' => $assignee ?? $this->employee, 'estimate_basis' => 'Synthetic only', 'remaining_effort' => null, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => [['criterion_id' => $this->criteria[$index]['id'], 'criterion_version_id' => $this->criteria[$index]['version_id']]]], $this->generation);
        $this->generation = $task['generation'];

        return $task;
    }

    protected function inputs(): void
    {
        $this->p = $this->lifecycle();
        foreach (['baseline.approve', 'acceptance.review', 'progress.read', 'evidence.review', 'ingestion.accept'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
        $this->readGrant = $this->grant('ingestion.read', $this->p);
        $data = $this->requirement();
        $data['criteria'][] = ['key' => 'C', 'text' => 'Synthetic C', 'required_test_keys' => ['test-c']];
        $this->draft = app(RequirementService::class)->createDraft($this->actor(), $this->p, $data);
        $v = app(RequirementService::class)->approve($this->actor(), $this->p, $this->draft['id'], 1, 0, 'Synthetic approval');
        $this->criteria = $v['criteria'];
        $this->policyId = app(DodPolicyService::class)->approve($this->actor(), $this->p, ['fictional' => true, 'mandatory_qa' => true, 'human_acceptance' => true], 1)['id'];
        $this->generation = 2;
        $this->connector = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () {
            $source = (string) Str::uuid();
            DB::table('source_instances')->insert(['id' => $source, 'tenant_id' => $this->tenant, 'kind' => 'manual', 'label' => 'Synthetic source']);
            DB::table('ingestion_connectors')->insert(['id' => $this->connector, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'source_instance_id' => $source, 'status' => 'active']);
        });
        $this->envelope = ['version' => 'ingestion-v1', 'source_object_id' => 'ticket-1', 'source_version' => 'v1', 'event_kind' => 'ticket.snapshot', 'occurred_at' => '2026-10-05T12:00:00Z', 'source_updated_at' => '2026-10-05T12:00:00Z', 'payload' => ['external_project_id' => 'project-1', 'external_ticket_id' => 'ticket-1', 'title' => 'Sensitive synthetic title', 'status' => 'done', 'external_actor_id' => null, 'due_at' => null]];
    }

    protected function scope(array $weights = [67, 20, 13], ?string $carry = null): array
    {
        return array_map(fn ($c, $w, $i) => ['criterion_version_id' => $c['version_id'], 'weight' => $w] + ($i === 0 && $carry ? ['carry_decision_id' => $carry] : []), $this->criteria, $weights, array_keys($weights));
    }

    protected function baseline(array $items, bool $reopen = false): array
    {
        $r = app(BaselineService::class)->approve($this->actor(), $this->p, $items, $this->policyId, $this->baselineId, $this->generation, 'Synthetic scope', $reopen);
        $this->generation = $r['generation'];
        $this->baselineId = $r['id'];

        return $r;
    }
}
