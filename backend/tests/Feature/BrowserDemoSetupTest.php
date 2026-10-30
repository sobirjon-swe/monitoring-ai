<?php

namespace Tests\Feature;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\Ba\BaReadService;
use App\Domain\Browser\Demo\DemoCredentialOutput;
use App\Domain\Browser\Demo\DemoManifest;
use App\Domain\Browser\Demo\DemoWorkspaceService;
use App\Domain\Identity\LocalTokenAuthenticator;
use App\Domain\Ingestion\IngestionService;
use App\Domain\Lifecycle\ProgressCalculator;
use App\Domain\Management\ManagementReadService;
use App\Domain\Qa\QaReadService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresTestCase;

class BrowserDemoSetupTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_setup_produces_real_facts_least_privilege_and_no_producer_credentials(): void
    {
        $this->assertTrue(class_exists(DemoWorkspaceService::class), 'Native guarded fixture producer is not implemented.');
        $manifest = DemoManifest::fromFile(base_path('tests/Fixtures/browser-demo-v1.json'));
        $path = $this->protectedDirectory().'\\workspace.json';
        $result = app(DemoWorkspaceService::class)->prepare($manifest, $path);
        $this->assertSame('published', $result['status']);
        $this->assertCount(6, $result['personas']);
        $journal = json_decode(file_get_contents($path), true);
        $this->assertNotEmpty($journal['snapshot']);
        foreach ($journal['credentials'] as $credential) {
            $this->assertFalse(str_contains(json_encode($result), $credential['token']), 'Service return must never contain a credential.');
            $this->assertTrue(app(LocalTokenAuthenticator::class)->authenticate($credential['token']) !== null);
        }
        $again = app(DemoWorkspaceService::class)->prepare($manifest, $path);
        $this->assertSame('replayed', $again['status']);
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            $this->assertSame(8, DB::table('users')->count());
            $this->assertSame(6, DB::table('auth_subjects')->count());
            $this->assertSame(6, DB::table('auth_tokens')->count());
            $this->assertSame(1, DB::table('requirement_versions')->count());
            $this->assertSame(1, DB::table('baselines')->count());
            $this->assertSame(1, DB::table('lifecycle_tasks')->count());
            $this->assertSame('open', DB::table('qa_handoffs')->value('state'));
            $this->assertSame('open', DB::table('ba_clarifications')->value('state'));
            $this->assertSame('completed', DB::table('management_support_versions')->value('outcome'));
            $this->assertSame(0, DB::table('human_acceptance_decisions')->count());
            $this->assertSame(4, DB::table('access_grants')->whereNotNull('revoked_at')->count());
            foreach ($manifest->data['personas'] as $persona) {
                $grants = DB::table('access_grants')->where('user_id', $persona['user_id'])->whereNull('revoked_at')->get();
                $this->assertTrue($grants->contains('purpose', 'project.read'));
                $this->assertTrue($grants->every(fn ($grant) => str_ends_with($grant->purpose, '.read')));
                $this->assertFalse($grants->contains('scope_type', 'tenant'));
                app(AccessGateway::class)->authorize(new TrustedActor($persona['user_id'], $manifest->data['tenant_id']), 'project.read', ['tenant_id' => $manifest->data['tenant_id'], 'project_id' => $manifest->data['project_id']]);
            }
        });
        $actor = new TrustedActor($manifest->data['personas'][0]['user_id'], $manifest->data['tenant_id']);
        $project = $manifest->data['project_id'];
        $qa = app(QaReadService::class)->queue($actor, $project);
        $this->assertCount(1, $qa['items']);
        $this->assertSame('complete', $qa['coverage_status']);
        $this->assertSame('current', $qa['items'][0]['applicability']);
        $ba = app(BaReadService::class)->queue($actor, $project);
        $this->assertCount(1, $ba['items']);
        $this->assertSame('customer', $ba['items'][0]['waiting_on']);
        $management = app(ManagementReadService::class)->queue($actor, $project);
        $this->assertCount(1, $management['items']);
        $this->assertTrue(is_array($management['items'][0]['underlying_dependency_status']));
        $verification = app(QaReadService::class)->verification($actor, $project);
        $this->assertSame('unknown', $verification['coverage_status']);
        $this->assertContains('plan_missing', $verification['reason_codes']);
        $this->assertNull($verification['qa_verification']['ratio_basis_points']);
        app(TenantContext::class)->run($manifest->data['tenant_id'], function (): void {
            $this->assertSame(0, DB::table('human_acceptance_decisions')->count());
            $this->assertSame('open', DB::table('qa_handoffs')->value('state'));
            $this->assertSame('open', DB::table('ba_clarifications')->value('state'));
            DB::table('access_grants')->where('user_id', DemoWorkspaceService::PRODUCER)->where('purpose', 'qa.handoff.manage')->update(['revoked_at' => now(), 'revision' => 2]);
        });
        $revoked = app(QaReadService::class)->queue($actor, $project);
        $this->assertSame('stale', $revoked['items'][0]['applicability']);
        $this->assertContains('access_revoked', $revoked['items'][0]['reason_codes']);
    }

    public function test_all_six_personas_read_meaningful_partial_progress_with_scoped_source_access(): void
    {
        $manifest = DemoManifest::fromFile(base_path('tests/Fixtures/browser-demo-v1.json'));
        app(DemoWorkspaceService::class)->prepare($manifest, $this->protectedDirectory().'\\progress.json');
        foreach ($manifest->data['personas'] as $persona) {
            $progress = app(ProgressCalculator::class)->calculate(new TrustedActor($persona['user_id'], $manifest->data['tenant_id']), $manifest->data['project_id']);
            $this->assertSame('partial', $progress['status']);
            $this->assertSame(0, $progress['accepted_weight']);
            $this->assertSame(100, $progress['total_weight']);
            $this->assertSame(0, $progress['percentage_basis_points']);
            $this->assertSame(['human_acceptance_missing'], $progress['coverage_reason_codes']);
            app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($persona, $manifest): void {
                $grants = DB::table('access_grants')->where('user_id', $persona['user_id'])->where('purpose', 'ingestion.read')->get();
                $this->assertCount(1, $grants);
                $this->assertSame('project', $grants[0]->scope_type);
                $this->assertSame($manifest->data['project_id'], $grants[0]->project_id);
                $this->assertNull($grants[0]->department_id);
                $this->assertNull($grants[0]->revoked_at);
            });
        }
        app(TenantContext::class)->run($manifest->data['tenant_id'], function (): void {
            $this->assertSame(0, DB::table('access_grants')->whereIn('user_id', [DemoWorkspaceService::PRODUCER, DemoWorkspaceService::ASSIGNER])->where('purpose', 'ingestion.read')->count());
            $this->assertSame(0, DB::table('human_acceptance_decisions')->count());
        });
    }

    public function test_setup_recovers_protected_pending_output_without_duplicate_facts(): void
    {
        $manifest = DemoManifest::fromFile(base_path('tests/Fixtures/browser-demo-v1.json'));
        foreach (['before_commit', 'after_commit', 'after_publication'] as $boundary) {
            // Each scenario has its own savepoint so the exact reviewed IDs remain isolated.
            DB::beginTransaction();
            $path = $this->protectedDirectory().'\\recovery.json';
            $output = new class($boundary) extends DemoCredentialOutput
            {
                public function __construct(private string $boundary) {}

                public function write(array $data): void
                {
                    parent::write($data);
                    if ($this->boundary === 'before_commit' && $data['snapshot'] !== null) {
                        throw new \LogicException('Simulated TEST interruption before database commit.');
                    }
                }

                public function publish(): void
                {
                    if ($this->boundary === 'after_publication') {
                        parent::publish();
                    }
                    throw new \LogicException('Simulated TEST interruption at publication boundary.');
                }
            };
            try {
                (new DemoWorkspaceService(app(TenantContext::class), $output))->prepare($manifest, $path);
                $this->fail('Interruption did not occur.');
            } catch (\LogicException) {
                $this->assertTrue(is_file($path.'.pending'));
            }
            $before = json_decode(file_get_contents($path.'.pending'), true);
            $result = app(DemoWorkspaceService::class)->prepare($manifest, $path);
            $this->assertSame($boundary === 'before_commit' ? 'published' : 'replayed', $result['status']);
            $after = json_decode(file_get_contents($path), true);
            $this->assertTrue($before['credentials'] === $after['credentials'], 'Recovery must never rotate credentials.');
            app(TenantContext::class)->run($manifest->data['tenant_id'], function (): void {
                $this->assertSame(6, DB::table('auth_tokens')->count());
                $this->assertSame(1, DB::table('qa_handoffs')->count());
                $this->assertSame(1, DB::table('ba_clarifications')->count());
                $this->assertSame(1, DB::table('management_support_versions')->count());
                $this->assertSame(4, DB::table('access_grants')->whereNotNull('revoked_at')->count());
            });
            DB::rollBack();
        }
    }

    public static function replayExpiryCases(): array
    {
        return [
            'committed pending before expiry' => [false, false],
            'committed pending exact expiry' => [false, true],
            'published replay before expiry' => [true, false],
            'published replay exact expiry' => [true, true],
        ];
    }

    #[DataProvider('replayExpiryCases')]
    public function test_committed_replay_respects_exact_credential_expiry(bool $published, bool $expired): void
    {
        $manifest = DemoManifest::fromFile(base_path('tests/Fixtures/browser-demo-v1.json'));
        $path = $this->protectedDirectory().'\\expiry.json';
        if ($published) {
            app(DemoWorkspaceService::class)->prepare($manifest, $path);
        } else {
            $output = new class extends DemoCredentialOutput
            {
                public function publish(): void
                {
                    throw new \LogicException('Simulated TEST interruption after canonical commit.');
                }
            };
            try {
                (new DemoWorkspaceService(app(TenantContext::class), $output))->prepare($manifest, $path);
                $this->fail('Expected TEST publication interruption.');
            } catch (\LogicException) {
                $this->assertFalse(file_exists($path));
            }
        }
        $pendingBefore = file_get_contents($path.'.pending');
        $journal = json_decode($pendingBefore, true, 16, JSON_THROW_ON_ERROR);
        $this->assertTrue(is_array($journal['snapshot']));
        $records = fn () => app(TenantContext::class)->run($manifest->data['tenant_id'], fn () => json_encode([
            'tokens' => DB::table('auth_tokens')->orderBy('id')->get()->all(),
            'subjects' => DB::table('auth_subjects')->orderBy('id')->get()->all(),
            'users' => DB::table('users')->orderBy('id')->get()->all(),
            'grants' => DB::table('access_grants')->orderBy('id')->get()->all(),
        ], JSON_THROW_ON_ERROR));
        $recordsBefore = $records();
        $this->assertSame(6, app(TenantContext::class)->run($manifest->data['tenant_id'], fn () => DB::table('auth_tokens')->count()));
        $clock = CarbonImmutable::parse($journal['expires_at']);
        CarbonImmutable::setTestNow($expired ? $clock : $clock->subMicrosecond());
        try {
            $refused = false;
            try {
                $result = app(DemoWorkspaceService::class)->prepare($manifest, $path);
                $this->assertSame('replayed', $result['status']);
            } catch (\LogicException) {
                $refused = true;
            }
            $this->assertSame($expired, $refused, 'Replay must refuse at exact expiry and succeed immediately before it.');
            $this->assertSame($published || ! $expired, file_exists($path), 'Expired pending credentials must not be newly published.');
            $this->assertTrue(hash_equals(hash('sha256', $pendingBefore), hash_file('sha256', $path.'.pending')), 'Pending credential bytes must remain unchanged.');
            if (file_exists($path)) {
                $this->assertTrue(hash_equals(hash('sha256', $pendingBefore), hash_file('sha256', $path)), 'Published credential bytes must remain unchanged.');
            }
            $this->assertTrue(hash_equals(hash('sha256', $recordsBefore), hash('sha256', $records())), 'Replay must not renew tokens or alter identity/grant records.');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_runtime_misconfiguration_refuses_before_file_or_identity_setup(): void
    {
        $manifest = DemoManifest::fromFile(base_path('tests/Fixtures/browser-demo-v1.json'));
        $original = config('database.connections.pgsql');
        foreach (['host' => 'example.invalid', 'database' => 'monitoring_ai_dev', 'username' => 'monitoring_ai_owner', 'url' => 'postgres://invalid', 'search_path' => 'public,private', 'port' => 5432] as $key => $value) {
            config(['database.connections.pgsql' => array_replace($original, [$key => $value])]);
            $path = $this->protectedDirectory().'\\refused.json';
            try {
                app(DemoWorkspaceService::class)->prepare($manifest, $path);
                $this->fail('Unsafe runtime admitted.');
            } catch (\LogicException) {
                $this->assertFalse(file_exists($path.'.pending'));
                $this->assertFalse(file_exists($path));
            } finally {
                config(['database.connections.pgsql' => $original]);
            }
        }
        config(['qa.database_marker' => 'unreviewed-marker']);
        $path = $this->protectedDirectory().'\\wrong-marker.json';
        try {
            app(DemoWorkspaceService::class)->prepare($manifest, $path);
            $this->fail('Wrong marker configuration admitted.');
        } catch (\LogicException) {
            $this->assertFalse(file_exists($path.'.pending'));
        }
    }

    public function test_unsafe_acl_and_malformed_pending_refuse_before_any_identity_or_grant(): void
    {
        $manifest = DemoManifest::fromFile(base_path('tests/Fixtures/browser-demo-v1.json'));
        $directory = base_path('../.local/demo-unsafe-service-'.bin2hex(random_bytes(8)));
        mkdir($directory);
        $path = realpath($directory).'\\credentials.json';
        try {
            app(DemoWorkspaceService::class)->prepare($manifest, $path);
            $this->fail('Unsafe ACL admitted for provisioning.');
        } catch (\LogicException) {
            $this->assertFalse(file_exists($path.'.pending'));
        }
        $safe = $this->protectedDirectory().'\\malformed.json';
        $output = new DemoCredentialOutput;
        $output->open($safe);
        $output->write(['version' => 'unreviewed', 'credentials' => []]);
        $output->close();
        try {
            app(DemoWorkspaceService::class)->prepare($manifest, $safe);
            $this->fail('Malformed pending journal admitted.');
        } catch (\LogicException) {
            $this->assertFalse(file_exists($safe));
        }
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            $this->assertFalse(DB::table('tenants')->where('id', $manifest->data['tenant_id'])->exists());
            $this->assertSame(0, DB::table('users')->count());
            $this->assertSame(0, DB::table('access_grants')->count());
        });
    }

    public function test_foreign_user_id_collision_rolls_back_without_merging_identity(): void
    {
        $manifest = DemoManifest::fromFile(base_path('tests/Fixtures/browser-demo-v1.json'));
        $tenant = (string) Str::uuid();
        $user = $manifest->data['personas'][0]['user_id'];
        app(TenantContext::class)->run($tenant, function () use ($tenant, $user): void {
            DB::table('tenants')->insert(['id' => $tenant, 'name' => 'Existing unrelated identity']);
            DB::table('users')->insert(['id' => $user, 'tenant_id' => $tenant, 'display_name' => 'Existing unrelated user']);
        });
        try {
            app(DemoWorkspaceService::class)->prepare($manifest, $this->protectedDirectory().'\\collision.json');
            $this->fail('Foreign user ID was merged.');
        } catch (QueryException) {
            $this->assertSame('Existing unrelated user', app(TenantContext::class)->run($tenant, fn () => DB::table('users')->where('id', $user)->value('display_name')));
        }
        app(TenantContext::class)->run($manifest->data['tenant_id'], function () use ($manifest): void {
            $this->assertFalse(DB::table('tenants')->where('id', $manifest->data['tenant_id'])->exists());
            $this->assertSame(0, DB::table('users')->count());
            $this->assertSame(0, DB::table('access_grants')->count());
        });
    }

    public function test_journal_refuses_nonarray_snapshot_and_more_than_eight_hour_validity(): void
    {
        $manifest = DemoManifest::fromFile(base_path('tests/Fixtures/browser-demo-v1.json'));
        $directory = $this->protectedDirectory();
        foreach (['snapshot' => 'invalid', 'expires_at' => now()->addDay()->utc()->format('Y-m-d\TH:i:s.u\Z')] as $field => $value) {
            DB::beginTransaction();
            $path = $directory.'\\'.$field.'.json';
            $journal = ['version' => 'browser-demo-output-v1', 'manifest_hash' => $manifest->hash, 'tenant_id' => $manifest->data['tenant_id'], 'project_id' => $manifest->data['project_id'], 'expires_at' => now()->addHours(8)->utc()->format('Y-m-d\TH:i:s.u\Z'), 'credentials' => [], 'snapshot' => null];
            foreach ($manifest->data['personas'] as $persona) {
                $journal['credentials'][] = ['key' => $persona['key'], 'user_id' => $persona['user_id'], 'token_id' => $persona['token_id'], 'token' => $manifest->data['tenant_id'].'.'.$persona['token_id'].'.'.str_repeat('1', 64)];
            }
            $journal[$field] = $value;
            $output = new DemoCredentialOutput;
            $output->open($path);
            $output->write($journal);
            $output->close();
            try {
                app(DemoWorkspaceService::class)->prepare($manifest, $path);
                $this->fail('Incompatible journal admitted.');
            } catch (\LogicException) {
                $this->assertFalse(file_exists($path));
                $this->assertFalse(app(TenantContext::class)->run($manifest->data['tenant_id'], fn () => DB::table('tenants')->where('id', $manifest->data['tenant_id'])->exists()));
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_existing_foreign_workspace_is_refused_and_unrelated_nonempty_sources_are_preserved(): void
    {
        $manifest = DemoManifest::fromFile(base_path('tests/Fixtures/browser-demo-v1.json'));
        $foreign = (string) Str::uuid();
        $source = (string) Str::uuid();
        app(TenantContext::class)->run($foreign, function () use ($foreign, $source): void {
            DB::table('tenants')->insert(['id' => $foreign, 'name' => 'Unrelated synthetic tenant']);
            DB::table('source_instances')->insert(['id' => $source, 'tenant_id' => $foreign, 'kind' => 'manual', 'label' => 'Nonempty unrelated synthetic source']);
            $user = (string) Str::uuid();
            $employee = (string) Str::uuid();
            $project = (string) Str::uuid();
            $policy = (string) Str::uuid();
            $connector = (string) Str::uuid();
            DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $foreign, 'display_name' => 'Unrelated synthetic producer']);
            DB::table('users')->insert(['id' => $user, 'tenant_id' => $foreign, 'employee_id' => $employee, 'display_name' => 'Unrelated synthetic producer']);
            DB::table('projects')->insert(['id' => $project, 'tenant_id' => $foreign, 'name' => 'Unrelated synthetic project']);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $foreign, 'project_id' => $project, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
            DB::table('policy_versions')->insert(['id' => $policy, 'tenant_id' => $foreign, 'version' => 1]);
            DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $foreign, 'user_id' => $user, 'policy_version_id' => $policy, 'scope_type' => 'project', 'project_id' => $project, 'purpose' => 'ingestion.accept', 'valid_from' => now()->subDay()]);
            DB::table('ingestion_connectors')->insert(['id' => $connector, 'tenant_id' => $foreign, 'project_id' => $project, 'source_instance_id' => $source, 'status' => 'active']);
            app(IngestionService::class)->accept(new TrustedActor($user, $foreign), $connector, ['version' => 'ingestion-v1', 'source_object_id' => 'unrelated-ticket', 'source_version' => 'v1', 'event_kind' => 'ticket.snapshot', 'occurred_at' => '2026-10-24T01:00:00Z', 'source_updated_at' => '2026-10-24T01:00:00Z', 'payload' => ['external_project_id' => 'unrelated-project', 'external_ticket_id' => 'unrelated-ticket', 'title' => 'Unrelated nonempty synthetic snapshot', 'status' => 'open', 'external_actor_id' => null, 'due_at' => null]]);
        });
        $before = app(TenantContext::class)->run($foreign, fn () => DB::table('ingestion_receipts')->get()->toJson());
        $path = $this->protectedDirectory().'\\preserved.json';
        app(DemoWorkspaceService::class)->prepare($manifest, $path);
        $after = app(TenantContext::class)->run($foreign, fn () => DB::table('ingestion_receipts')->get()->toJson());
        $this->assertSame($before, $after);
        $this->assertSame(1, app(TenantContext::class)->run($foreign, fn () => DB::table('source_instances')->count()));
        $this->assertSame(1, app(TenantContext::class)->run($foreign, fn () => DB::table('ingestion_receipts')->count()));
        $this->assertSame(0, DB::table('source_instances')->count(), 'Missing tenant context must not see sources.');
        $this->assertSame(1, app(TenantContext::class)->run($foreign, fn () => DB::table('users')->count()));
        $this->assertSame(0, app(TenantContext::class)->run($foreign, fn () => DB::table('users')->whereIn('id', array_column($manifest->data['personas'], 'user_id'))->count()));
        // A new locator cannot adopt any existing tenant, even the exact synthetic IDs.
        $this->expectException(\LogicException::class);
        app(DemoWorkspaceService::class)->prepare($manifest, $this->protectedDirectory().'\\foreign.json');
    }

    public function test_canonical_drift_and_credential_journal_mismatch_refuse_replay(): void
    {
        $manifest = DemoManifest::fromFile(base_path('tests/Fixtures/browser-demo-v1.json'));
        $path = $this->protectedDirectory().'\\drift.json';
        app(DemoWorkspaceService::class)->prepare($manifest, $path);
        app(TenantContext::class)->run($manifest->data['tenant_id'], fn () => DB::table('access_grants')->where('purpose', 'project.read')->update(['revoked_at' => now(), 'revision' => 2]));
        try {
            app(DemoWorkspaceService::class)->prepare($manifest, $path);
            $this->fail('Changed canonical state was silently accepted.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        app(TenantContext::class)->run($manifest->data['tenant_id'], fn () => DB::table('access_grants')->where('purpose', 'project.read')->update(['revoked_at' => null, 'revision' => 1]));
        $journal = json_decode(file_get_contents($path), true);
        $journal['credentials'][0]['token'] = $manifest->data['tenant_id'].'.'.$manifest->data['personas'][0]['token_id'].'.'.str_repeat('0', 64);
        file_put_contents($path, json_encode($journal));
        $this->expectException(\LogicException::class);
        app(DemoWorkspaceService::class)->prepare($manifest, $path);
    }

    public function test_reviewed_manifest_has_exact_six_linked_fictional_personas(): void
    {
        $this->assertTrue(class_exists(DemoManifest::class), 'The reviewed bounded demo manifest is not implemented.');
        $manifest = DemoManifest::fromFile(base_path('tests/Fixtures/browser-demo-v1.json'));
        $this->assertSame(['John', 'Andrew', 'Steve', 'Ivan', 'Adam', 'Victoria'], array_column($manifest->data['personas'], 'name'));
        $this->assertSame(64, strlen($manifest->hash));
    }

    public function test_protected_output_refuses_url_unc_and_device_paths_before_creating_files(): void
    {
        $this->assertTrue(class_exists(DemoCredentialOutput::class), 'Protected credential output is not implemented.');
        foreach (['https://invalid/secret', '\\\\server\\secret', 'C:\\NUL.json', 'C:\\safe\\CON .json', 'C:\\safe\\COM¹.log', 'C:\\safe\\file:stream', 'C:\\safe\\..\\secret'] as $path) {
            try {
                DemoCredentialOutput::assertLocalPath($path);
                $this->fail('Unsafe credential path was admitted.');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_manifest_refuses_changed_hash_unknown_fields_and_nonlocal_inputs(): void
    {
        $path = base_path('../.local/demo-manifest-rejected-'.bin2hex(random_bytes(8)).'.json');
        file_put_contents($path, '{"version":"browser-demo-v1","synthetic":true,"grant":"organization.manage"}');
        $path = realpath($path);
        foreach ([$path, 'https://invalid/fixture', '\\\\server\\fixture', 'C:\\NUL.json'] as $input) {
            try {
                DemoManifest::fromFile($input);
                $this->fail('Unreviewed manifest admitted.');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_output_is_protected_before_secrets_and_recovers_without_overwriting_user_file(): void
    {
        $directory = $this->protectedDirectory();
        $path = $directory.'\\credentials.json';
        $secret = bin2hex(random_bytes(32));
        $output = new DemoCredentialOutput;
        $output->open($path);
        $this->assertNull($output->read());
        $output->write(['secret' => $secret, 'state' => 'prepared']);
        $output->close();
        $resumed = new DemoCredentialOutput;
        $resumed->open($path);
        $this->assertTrue(hash_equals($secret, $resumed->read()['secret']));
        $resumed->publish();
        $resumed->close();
        $this->assertTrue(is_file($path));
        $this->assertTrue(hash_equals(hash_file('sha256', $path), hash_file('sha256', $path.'.pending')));
        $published = new DemoCredentialOutput;
        $published->open($path);
        $published->publish();
        try {
            $published->write(['secret' => 'replacement']);
            $this->fail('Published output overwritten.');
        } catch (\LogicException) {
            $this->assertTrue(hash_equals($secret, json_decode(file_get_contents($path), true)['secret']));
        } finally {
            $published->close();
        }
        $existing = $directory.'\\existing.json';
        file_put_contents($existing, 'operator-owned content');
        $this->expectException(\LogicException::class);
        (new DemoCredentialOutput)->open($existing);
    }

    public function test_shared_parent_is_rejected_without_creating_pending_or_final_output(): void
    {
        $directory = base_path('../.local/demo-shared-'.bin2hex(random_bytes(8)));
        mkdir($directory);
        $directory = realpath($directory);
        $path = $directory.'\\credentials.json';
        try {
            (new DemoCredentialOutput)->open($path);
            $this->fail('Shared inherited parent admitted.');
        } catch (\LogicException) {
            $this->assertFalse(file_exists($path));
            $this->assertFalse(file_exists($path.'.pending'));
        }
    }

    private function protectedDirectory(): string
    {
        $directory = base_path('../.local/demo-output-test-'.bin2hex(random_bytes(8)));
        mkdir($directory);
        $script = <<<'PS'
$ErrorActionPreference='Stop'
$p=$env:DEMO_TEST_DIRECTORY
$s=[System.Security.Principal.WindowsIdentity]::GetCurrent().User
$a=Get-Acl -LiteralPath $p
$a.SetOwner($s)
$a.SetAccessRuleProtection($true,$false)
foreach($r in @($a.Access)){[void]$a.RemoveAccessRuleSpecific($r)}
$a.AddAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new($s,'FullControl','ContainerInherit,ObjectInherit','None','Allow'))
Set-Acl -LiteralPath $p -AclObject $a
PS;
        $process = new Process(['C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script], null, ['DEMO_TEST_DIRECTORY' => $directory, 'PSModulePath' => 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\Modules']);
        $exit = $process->run();
        $this->assertSame(0, $exit, 'TEST directory ACL: '.$process->getErrorOutput());

        return realpath($directory);
    }

    public function test_actual_cli_refuses_testing_without_provisioning(): void
    {
        $this->assertTrue(class_exists(DemoWorkspaceService::class), 'Guarded workspace service is not implemented.');
        $this->artisan('app:setup-local-browser-demo', ['--manifest' => base_path('tests/Fixtures/browser-demo-v1.json'), '--credentials-file' => 'C:\\NUL'])
            ->expectsOutput('Synthetic browser setup refused; inspect the protected local setup journal.')->assertExitCode(1);
    }
}
