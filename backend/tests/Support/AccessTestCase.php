<?php

namespace Tests\Support;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class AccessTestCase extends PostgresTestCase
{
    protected string $tenant;

    protected string $user;

    protected string $employee;

    protected string $token;

    protected string $policy;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->tenant = (string) Str::uuid();
        $this->user = (string) Str::uuid();
        $this->employee = (string) Str::uuid();
        $this->policy = (string) Str::uuid();
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

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }
}
