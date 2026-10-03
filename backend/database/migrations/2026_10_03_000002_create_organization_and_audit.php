<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['employees', 'departments'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string($name === 'employees' ? 'display_name' : 'name', 200);
                $table->unsignedInteger('revision')->default(1);
                $table->timestampsTz();
                $table->unique(['tenant_id', 'id']);
                $table->foreign('tenant_id')->references('id')->on('tenants');
            });
        }
        Schema::create('department_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employee_id');
            $table->uuid('department_id');
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('employees');
            $table->foreign(['tenant_id', 'department_id'])->references(['tenant_id', 'id'])->on('departments');
        });
        Schema::create('reporting_relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employee_id');
            $table->uuid('manager_id');
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to')->nullable();
            $table->unsignedInteger('revision')->default(1);
            foreach (['employee_id', 'manager_id'] as $column) {
                $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('employees');
            }
        });
        foreach (['department_memberships', 'reporting_relationships'] as $table) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_period_check CHECK (valid_to IS NULL OR valid_to > valid_from)");
        }
        DB::statement('ALTER TABLE reporting_relationships ADD CONSTRAINT reporting_not_self CHECK (employee_id <> manager_id)');
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('actor_id');
            $table->uuid('project_id')->nullable();
            $table->string('action', 80);
            $table->string('resource_type', 80);
            $table->uuid('resource_id');
            $table->unsignedInteger('revision');
            $table->string('reason_code', 80);
            $table->uuid('correlation_id');
            $table->timestampTz('occurred_at');
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
        foreach (['employees', 'departments', 'department_memberships', 'reporting_relationships', 'audit_events'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
        }
        DB::unprepared("CREATE FUNCTION public.reject_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Audit history is immutable' USING ERRCODE = '42501'; END $$");
        DB::statement('CREATE TRIGGER audit_immutable BEFORE UPDATE OR DELETE ON public.audit_events FOR EACH ROW EXECUTE FUNCTION public.reject_audit_mutation()');
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.audit_events FROM monitoring_ai_app');
    }

    public function down(): void
    {
        foreach (['audit_events', 'reporting_relationships', 'department_memberships', 'departments', 'employees'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS public.reject_audit_mutation()');
    }
};
