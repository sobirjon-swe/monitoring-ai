<?php

namespace Tests\Feature;

use App\Domain\Identity\IdentityMappingService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccessTestCase;

class IdentityMappingTest extends AccessTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->grant('identity.manage');
        $this->grant('identity.read');
        $this->withToken($this->token);
    }

    public function test_same_email_does_not_merge_employees(): void
    {
        $other = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('employees')->insert(['id' => $other, 'tenant_id' => $this->tenant, 'display_name' => 'Another employee']));
        $source = $this->source();
        $first = $this->identity($source, '101');
        $second = $this->identity($source, '102');
        foreach ([[$first, $this->employee], [$second, $other]] as [$identity, $employee]) {
            $mapping = $this->mapping($identity, $employee);
            $this->review($mapping, ['employee_id' => $employee])->assertOk();
            $this->getJson('/api/v1/identities/'.$identity.'/attribution?at=2026-01-15T00:00:00Z')->assertOk()->assertJsonPath('data.employee_id', $employee);
        }
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(2, DB::table('employees')->count()));
    }

    public function test_same_external_id_on_different_source_is_distinct(): void
    {
        $source = $this->source();
        $first = $this->identity($source, '42');
        $second = $this->identity($this->source(), '42');
        $this->assertNotSame($first, $second);
        $this->postJson('/api/v1/source-instances/'.$source.'/identities', ['external_user_id' => '42'])->assertConflict();
    }

    public function test_unapproved_mapping_has_no_attribution(): void
    {
        $identity = $this->identity($this->source());
        $mapping = $this->postJson('/api/v1/identities/'.$identity.'/mappings', ['employee_id' => $this->employee, 'valid_from' => '2026-01-01T00:00:00Z', 'reason' => 'Synthetic candidate', 'status' => 'approved', 'reviewer_id' => $this->user])->assertCreated();
        $mapping->assertJsonPath('data.status', 'candidate')->assertJsonPath('data.reviewer_id', null);
        $this->getJson('/api/v1/identities/'.$identity.'/attribution?at=2026-01-15T00:00:00Z')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_stale_mapping_update_returns_409(): void
    {
        $mapping = $this->mapping($this->identity($this->source()));
        $this->review($mapping)->assertOk()->assertJsonPath('data.revision', 2);
        $this->review($mapping)->assertConflict();
        $this->getJson('/api/v1/identity-mappings/'.$mapping.'/history')->assertOk()->assertJsonCount(2, 'data');
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(2, DB::table('audit_events')->where('resource_id', $mapping)->count()));
    }

    public function test_review_uses_authenticated_reviewer_and_preserves_revision_history(): void
    {
        $identity = $this->identity($this->source());
        $mapping = $this->mapping($identity);
        $this->review($mapping, ['reviewer_id' => (string) Str::uuid(), 'reason' => 'Restricted synthetic review reason'])->assertOk()->assertJsonPath('data.reviewer_id', $this->user);
        $history = $this->getJson('/api/v1/identity-mappings/'.$mapping.'/history')->assertOk()->assertJsonCount(2, 'data');
        $history->assertJsonPath('data.0.status', 'candidate')->assertJsonPath('data.1.status', 'approved')->assertJsonPath('data.1.changed_by', $this->user);
        app(TenantContext::class)->run($this->tenant, function () use ($mapping): void {
            $audit = DB::table('audit_events')->where('resource_id', $mapping)->orderBy('revision')->get();
            $this->assertSame('identity_review', $audit[1]->reason_code);
            $this->assertStringNotContainsString('Restricted synthetic review reason', json_encode($audit));
        });
    }

    public function test_approved_periods_do_not_overlap_and_have_half_open_boundaries(): void
    {
        $identity = $this->identity($this->source());
        $first = $this->mapping($identity);
        $second = $this->mapping($identity);
        $this->review($first, ['valid_to' => '2026-02-01T00:00:00Z'])->assertOk();
        $this->review($second)->assertConflict();
        $this->getJson('/api/v1/identity-mappings/'.$second.'/history')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/identities/'.$identity.'/attribution?at=2026-02-01T00:00:00Z')->assertOk()->assertExactJson(['data' => null]);
        $this->review($second, ['valid_from' => '2026-02-01T00:00:00Z'])->assertOk();
        $this->getJson('/api/v1/identities/'.$identity.'/attribution?at=2026-02-01T00:00:00Z')->assertOk()->assertJsonPath('data.mapping_id', $second);
    }

    public function test_identity_read_and_manage_are_independent_and_revocation_is_current(): void
    {
        $identity = $this->identity($this->source());
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'identity.read')->update(['revoked_at' => now()]));
        $this->getJson('/api/v1/identities/'.$identity.'/attribution?at=2026-01-15T00:00:00Z')->assertForbidden();
        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->getJson('/api/v1/projects/'.$this->project())->assertForbidden();
        $this->mapping($identity);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'identity.manage')->update(['revoked_at' => now()]));
        $this->postJson('/api/v1/source-instances', ['kind' => 'gitlab', 'label' => 'Unauthorized'])->assertForbidden();
    }

    public function test_database_rejects_identity_rebinding_and_history_mutation(): void
    {
        $source = $this->source();
        $identity = $this->identity($source);
        $otherSource = $this->source();
        $mapping = $this->mapping($identity);
        app(TenantContext::class)->run($this->tenant, function () use ($identity, $otherSource, $mapping): void {
            foreach ([['external_user_id' => 'changed'], ['source_instance_id' => $otherSource]] as $change) {
                $this->rejectSql(fn () => DB::table('identities')->where('id', $identity)->update($change), '42501');
            }
            $this->rejectSql(fn () => DB::table('identity_mapping_histories')->where('mapping_id', $mapping)->update(['reason' => 'Tampered']), '42501');
            $this->rejectSql(fn () => DB::table('identity_mapping_histories')->where('mapping_id', $mapping)->delete(), '42501');
            $this->assertSame('42', DB::table('identities')->where('id', $identity)->value('external_user_id'));
        });
    }

    public function test_rls_missing_context_cross_tenant_reads_and_mapping_fk_are_denied(): void
    {
        $source = $this->source();
        $identity = $this->identity($source);
        $mapping = $this->mapping($identity);
        foreach (['source_instances', 'identities', 'identity_mappings', 'identity_mapping_histories'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
            $flags = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where oid = ?::regclass', [$table]);
            $this->assertTrue($flags->relrowsecurity && $flags->relforcerowsecurity);
        }
        $otherTenant = (string) Str::uuid();
        $otherEmployee = (string) Str::uuid();
        app(TenantContext::class)->run($otherTenant, function () use ($otherTenant, $otherEmployee): void {
            DB::table('tenants')->insert(['id' => $otherTenant, 'name' => 'Other synthetic tenant']);
            DB::table('employees')->insert(['id' => $otherEmployee, 'tenant_id' => $otherTenant, 'display_name' => 'Private employee']);
            $this->assertSame(0, DB::table('identity_mappings')->count());
        });
        $this->postJson('/api/v1/identities/'.$identity.'/mappings', ['employee_id' => $otherEmployee, 'valid_from' => '2026-01-01T00:00:00Z', 'reason' => 'Synthetic candidate'])->assertConflict();
        app(TenantContext::class)->run($this->tenant, fn () => $this->rejectSql(fn () => DB::table('identity_mappings')->where('id', $mapping)->update(['employee_id' => $otherEmployee, 'revision' => 2]), '23503'));
    }

    public function test_invalid_review_period_and_missing_reason_are_rejected(): void
    {
        $mapping = $this->mapping($this->identity($this->source()));
        $this->review($mapping, ['valid_to' => '2025-01-01T00:00:00Z'])->assertUnprocessable();
        $this->review($mapping, ['reason' => ''])->assertUnprocessable();
        $this->getJson('/api/v1/identity-mappings/'.$mapping.'/history')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_rejected_mapping_removes_attribution_without_rewriting_history(): void
    {
        $identity = $this->identity($this->source());
        $mapping = $this->mapping($identity);
        $this->review($mapping)->assertOk();
        $this->review($mapping, ['status' => 'rejected', 'expected_revision' => 2, 'reason' => 'Synthetic correction'])->assertOk();
        $this->getJson('/api/v1/identities/'.$identity.'/attribution?at=2026-01-15T00:00:00Z')->assertOk()->assertExactJson(['data' => null]);
        $this->getJson('/api/v1/identity-mappings/'.$mapping.'/history')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.1.status', 'approved')->assertJsonPath('data.2.status', 'rejected');
    }

    public function test_history_is_bounded_and_requires_current_identity_read(): void
    {
        $mapping = $this->mapping($this->identity($this->source()));
        app(TenantContext::class)->run($this->tenant, function () use ($mapping): void {
            for ($revision = 2; $revision <= 102; $revision++) {
                DB::table('identity_mappings')->where('id', $mapping)->update(['revision' => $revision]);
            }
        });
        $this->getJson('/api/v1/identity-mappings/'.$mapping.'/history')->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('next_after_revision', 100);
        $this->getJson('/api/v1/identity-mappings/'.$mapping.'/history?after_revision=100')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.revision', 101)->assertJsonPath('next_after_revision', null);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'identity.read')->update(['revoked_at' => now()]));
        $this->getJson('/api/v1/identity-mappings/'.$mapping.'/history')->assertForbidden();
    }

    public function test_foreign_identity_source_and_mapping_are_hidden_by_http_and_database(): void
    {
        $foreignTenant = (string) Str::uuid();
        $foreignSource = (string) Str::uuid();
        $foreignIdentity = (string) Str::uuid();
        app(TenantContext::class)->run($foreignTenant, function () use ($foreignTenant, $foreignSource, $foreignIdentity): void {
            DB::table('tenants')->insert(['id' => $foreignTenant, 'name' => 'Private tenant']);
            DB::table('source_instances')->insert(['id' => $foreignSource, 'tenant_id' => $foreignTenant, 'kind' => 'gitlab', 'label' => 'Private source']);
            DB::table('identities')->insert(['id' => $foreignIdentity, 'tenant_id' => $foreignTenant, 'source_instance_id' => $foreignSource, 'external_user_id' => 'private-42']);
        });
        $this->getJson('/api/v1/identities/'.$foreignIdentity.'/attribution?at=2026-01-15T00:00:00Z')->assertNotFound()->assertDontSee('private-42');
        $this->postJson('/api/v1/source-instances/'.$foreignSource.'/identities', ['external_user_id' => '42'])->assertNotFound();
        $this->getJson('/api/v1/identity-mappings/'.Str::uuid().'/history')->assertNotFound();
        app(TenantContext::class)->run($this->tenant, fn () => $this->rejectSql(fn () => DB::table('identities')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'source_instance_id' => $foreignSource, 'external_user_id' => '42']), '23503'));
        $this->rejectSql(fn () => DB::table('source_instances')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'kind' => 'gitlab', 'label' => 'No context']), '42501');
    }

    public function test_database_requires_reviewer_and_next_revision_and_source_audit_is_atomic(): void
    {
        $mapping = $this->mapping($this->identity($this->source()));
        app(TenantContext::class)->run($this->tenant, function () use ($mapping): void {
            $this->rejectSql(fn () => DB::table('identity_mappings')->where('id', $mapping)->update(['status' => 'approved', 'revision' => 2]), '23514');
            $this->rejectSql(fn () => DB::table('identity_mappings')->where('id', $mapping)->update(['reason' => 'Stale write']), '23514');
            $before = DB::table('source_instances')->count();
            try {
                app(IdentityMappingService::class)->createSource($this->tenant, 'invalid-audit-actor', ['kind' => 'gitlab', 'label' => 'Rollback source']);
                $this->fail('Invalid audit actor accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertSame($before, DB::table('source_instances')->count());
            }
            $this->assertSame(1, DB::table('identity_mapping_histories')->where('mapping_id', $mapping)->count());
        });
    }

    public function test_runtime_cannot_fabricate_history_but_review_trigger_remains_atomic(): void
    {
        $identity = $this->identity($this->source());
        $mapping = $this->mapping($identity);
        app(TenantContext::class)->run($this->tenant, function () use ($identity, $mapping): void {
            $this->rejectSql(fn () => DB::table('identity_mapping_histories')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'mapping_id' => $mapping,
                'identity_id' => $identity, 'employee_id' => $this->employee, 'status' => 'approved',
                'reviewer_id' => $this->user, 'changed_by' => $this->user, 'reason' => 'Fabricated approval',
                'reviewed_at' => now(), 'valid_from' => '2026-01-01T00:00:00Z', 'revision' => 2, 'recorded_at' => now(),
            ]), '42501');
            $this->assertSame(1, DB::table('identity_mapping_histories')->where('mapping_id', $mapping)->count());
        });
        $this->review($mapping)->assertOk()->assertJsonPath('data.revision', 2);
        $this->review($mapping)->assertConflict();
        $this->getJson('/api/v1/identity-mappings/'.$mapping.'/history')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'candidate')->assertJsonPath('data.1.reason', 'Synthetic verified source ID');
    }

    private function source(): string
    {
        return $this->postJson('/api/v1/source-instances', ['kind' => 'gitlab', 'label' => 'Synthetic source'])->assertCreated()->json('data.id');
    }

    private function identity(string $source, string $external = '42'): string
    {
        return $this->postJson('/api/v1/source-instances/'.$source.'/identities', ['external_user_id' => $external, 'candidate_name' => 'Same candidate', 'candidate_email' => 'same@example.invalid'])->assertCreated()->json('data.id');
    }

    private function mapping(string $identity, ?string $employee = null): string
    {
        return $this->postJson('/api/v1/identities/'.$identity.'/mappings', ['employee_id' => $employee ?? $this->employee, 'valid_from' => '2026-01-01T00:00:00Z', 'reason' => 'Synthetic candidate'])->assertCreated()->json('data.id');
    }

    private function review(string $mapping, array $overrides = []): TestResponse
    {
        return $this->patchJson('/api/v1/identity-mappings/'.$mapping, array_merge(['status' => 'approved', 'employee_id' => $this->employee, 'valid_from' => '2026-01-01T00:00:00Z', 'reason' => 'Synthetic verified source ID', 'expected_revision' => 1], $overrides));
    }

    private function rejectSql(\Closure $write, string $state): void
    {
        try {
            DB::transaction($write);
            $this->fail('Forbidden database mutation accepted.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}
