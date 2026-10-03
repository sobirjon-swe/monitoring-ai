<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditWriter;
use App\Domain\Organization\OrganizationService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PostgresTestCase;

class AuditTest extends PostgresTestCase
{
    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->tenant = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('tenants')->insert(['id' => $this->tenant, 'name' => 'Synthetic audit']));
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_mutation_and_audit_rollback_together(): void
    {
        app(TenantContext::class)->run($this->tenant, function (): void {
            try {
                app(OrganizationService::class)->createEmployee($this->tenant, (string) Str::uuid(), 'Rollback', 'secret_payload');
                $this->fail('Invalid audit reason accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertSame(0, DB::table('employees')->count());
                $this->assertSame(0, DB::table('audit_events')->count());
            }
        });
    }

    public function test_audit_cannot_be_overwritten(): void
    {
        app(TenantContext::class)->run($this->tenant, function (): void {
            app(OrganizationService::class)->createEmployee($this->tenant, (string) Str::uuid(), 'Immutable', 'local_setup');
            foreach (['update', 'delete'] as $operation) {
                try {
                    DB::transaction(fn () => $operation === 'update' ? DB::table('audit_events')->update(['reason_code' => 'approved_change']) : DB::table('audit_events')->delete());
                    $this->fail('Audit mutation accepted.');
                } catch (QueryException $exception) {
                    $this->assertSame('42501', $exception->errorInfo[0]);
                    $this->assertSame(1, DB::table('audit_events')->count());
                }
            }
        });
    }

    public function test_arbitrary_sensitive_metadata_is_rejected(): void
    {
        app(TenantContext::class)->run($this->tenant, function (): void {
            try {
                app(AuditWriter::class)->record((string) Str::uuid(), ['tenant_id' => $this->tenant], 'private_salary', ['type' => 'secret_token', 'id' => (string) Str::uuid()], 1, 'local_setup');
                $this->fail('Arbitrary metadata accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertSame(0, DB::table('audit_events')->count());
            }
        });
    }
}
