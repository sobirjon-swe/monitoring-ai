<?php

namespace Tests\Feature;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Browser\BaDemo\BaDemoFixtureService;
use App\Domain\Browser\BaDemo\BaDemoManifest;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessTestCase;

final class BaDemoFixtureServiceTest extends AccessTestCase
{
    public function test_fixture_provisions_isolated_proof_with_scoped_writer_and_tokenless_producer(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        $m = $manifest->data;
        $result = app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
        $event = app(BaClarificationService::class)->event(new TrustedActor($m['writer']['user_id'], $m['tenant_id']), $m['project_id'], $result['clarification_id'], ['clarification_id' => $result['clarification_id'], 'expected_revision' => 1, 'kind' => 'context.add', 'context' => 'SYNTHETIC context'], 'synthetic-f02-context');
        $this->assertNotEmpty($event['event_id']);
        app(TenantContext::class)->run($m['tenant_id'], function () use ($m): void {
            $this->assertSame(1, DB::table('ba_clarifications')->where('project_id', $m['project_id'])->count());
            $expected = $m['writer']['grants'];
            sort($expected);
            $this->assertSame($expected, DB::table('access_grants')->where('user_id', $m['writer']['user_id'])->whereNull('revoked_at')->orderBy('purpose')->pluck('purpose')->all());
            $producerGrants = $m['producer']['grants'];
            sort($producerGrants);
            $this->assertSame($producerGrants, DB::table('access_grants')->where('user_id', $m['producer']['user_id'])->whereNull('revoked_at')->orderBy('purpose')->pluck('purpose')->all());
            $this->assertSame(['ba'], DB::table('role_assignments')->where('employee_id', $m['producer']['employee_id'])->pluck('role')->all());
            $this->assertSame(str_repeat('a', 64), DB::table('auth_tokens')->where('id', $m['writer']['token_id'])->value('secret_hash'));
            $this->assertSame(0, DB::table('auth_subjects')->where('user_id', $m['producer']['user_id'])->count());
            $this->assertSame(1, DB::table('auth_tokens')->where('id', $m['writer']['token_id'])->count());
            $this->assertSame(0, DB::table('access_grants')->where('user_id', $m['producer']['user_id'])->whereIn('purpose', BaDemoFixtureService::TEMPORARY)->whereNull('revoked_at')->count());
        });
    }

    public function test_rejects_existing_workspace_and_wrong_database_configuration(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
        try {
            app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
            $this->fail('Existing workspace was accepted');
        } catch (\LogicException $e) {
            $this->assertSame('Synthetic BA workspace already exists.', $e->getMessage());
        }
        config(['database.connections.pgsql.database' => 'monitoring_ai_dev']);
        $this->expectException(\LogicException::class);
        app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
    }

    public function test_late_failure_rolls_back_fixture_even_when_caller_catches_it_inside_tenant_context(): void
    {
        $manifest = BaDemoManifest::fromFile(base_path('tests/Fixtures/ba-context-demo-v1.json'));
        $m = $manifest->data;
        app()->instance(AuditWriter::class, new class extends AuditWriter
        {
            public function record(string $actor, array $scope, string $action, array $resource, int $revision, string $reason): void
            {
                if ($action === 'access.setup' && $reason === 'access_change') {
                    throw new \RuntimeException('Synthetic late failure');
                }
                parent::record($actor, $scope, $action, $resource, $revision, $reason);
            }
        });
        app(TenantContext::class)->run($m['tenant_id'], function () use ($manifest, $m): void {
            try {
                app(BaDemoFixtureService::class)->provision($manifest, str_repeat('a', 64), now()->addHour());
                $this->fail('Late failure was not raised');
            } catch (\RuntimeException $e) {
                $this->assertSame('Synthetic late failure', $e->getMessage());
            }
            foreach (['tenants', 'projects', 'access_grants', 'auth_tokens', 'ba_clarifications'] as $table) {
                $query = DB::table($table);
                $query->where($table === 'tenants' ? 'id' : 'tenant_id', $m['tenant_id']);
                $this->assertSame(0, $query->count(), $table);
            }
        });
    }
}
