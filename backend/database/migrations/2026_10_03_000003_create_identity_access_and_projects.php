<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employee_id')->nullable();
            $table->string('display_name', 200);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('employees');
        });
        Schema::create('auth_subjects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('issuer', 100);
            $table->string('subject', 200);
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'issuer', 'subject']);
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
        });
        Schema::create('auth_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('auth_subject_id');
            $table->char('secret_hash', 64);
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->foreign(['tenant_id', 'auth_subject_id'])->references(['tenant_id', 'id'])->on('auth_subjects');
        });
        Schema::create('policy_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('active');
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'version']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 200);
            $table->string('timezone', 50)->default('Asia/Tashkent');
            $table->unsignedInteger('revision')->default(1);
            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
        foreach (['project_memberships', 'role_assignments'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('project_id');
                $table->uuid('employee_id');
                if ($name === 'role_assignments') {
                    $table->string('role', 50);
                }
                $table->timestampTz('valid_from', 6);
                $table->timestampTz('valid_to', 6)->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('projects');
                $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('employees');
            });
            DB::statement("ALTER TABLE $name ADD CONSTRAINT {$name}_period_check CHECK (valid_to IS NULL OR valid_to > valid_from)");
        }
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT roles_allowlist CHECK (role IN ('developer','qa','ba','pm','department_head','reviewer'))");
        // PostgreSQL exclusion constraints serialize conflicts, including concurrent writes.
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_no_overlap EXCLUDE USING gist (tenant_id WITH =, project_id WITH =, employee_id WITH =, role WITH =, tstzrange(valid_from, valid_to, '[)') WITH &&)");
        Schema::create('access_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('policy_version_id');
            $table->string('purpose', 80);
            $table->string('scope_type', 20);
            $table->uuid('project_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->timestampTz('valid_from', 6);
            $table->timestampTz('valid_to', 6)->nullable();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->unsignedInteger('revision')->default(1);
            foreach (['user_id' => 'users', 'policy_version_id' => 'policy_versions', 'project_id' => 'projects', 'department_id' => 'departments'] as $column => $parent) {
                $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($parent);
            }
        });
        DB::statement("ALTER TABLE access_grants ADD CONSTRAINT grants_scope_check CHECK ((scope_type='tenant' AND project_id IS NULL AND department_id IS NULL) OR (scope_type='project' AND project_id IS NOT NULL AND department_id IS NULL) OR (scope_type='department' AND department_id IS NOT NULL AND project_id IS NULL))");
        DB::statement('ALTER TABLE access_grants ADD CONSTRAINT grants_period_check CHECK (valid_to IS NULL OR valid_to > valid_from)');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','inactive'))");
        DB::statement("ALTER TABLE policy_versions ADD CONSTRAINT policies_status_check CHECK (status IN ('active','retired'))");
        DB::statement('ALTER TABLE audit_events ADD CONSTRAINT audit_project_tenant_fk FOREIGN KEY (tenant_id, project_id) REFERENCES projects (tenant_id, id)');
        foreach (['users', 'auth_subjects', 'auth_tokens', 'policy_versions', 'projects', 'project_memberships', 'role_assignments', 'access_grants'] as $name) {
            DB::statement("ALTER TABLE $name ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $name FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $name USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_project_tenant_fk');
        foreach (['access_grants', 'role_assignments', 'project_memberships', 'projects', 'policy_versions', 'auth_tokens', 'auth_subjects', 'users'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
