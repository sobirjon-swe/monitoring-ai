<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_instances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('kind', 30);
            $table->string('label', 200);
            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
        Schema::create('identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('source_instance_id');
            $table->string('external_user_id', 200);
            $table->string('candidate_name', 200)->nullable();
            $table->string('candidate_email', 254)->nullable();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'source_instance_id', 'external_user_id']);
            $table->foreign(['tenant_id', 'source_instance_id'])->references(['tenant_id', 'id'])->on('source_instances');
        });
        foreach (['identity_mappings', 'identity_mapping_histories'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('identity_id');
                $table->uuid('employee_id');
                $table->string('status', 20)->default('candidate');
                $table->uuid('reviewer_id')->nullable();
                $table->uuid('changed_by');
                $table->text('reason');
                $table->timestampTz('reviewed_at', 6)->nullable();
                $table->timestampTz('valid_from', 6);
                $table->timestampTz('valid_to', 6)->nullable();
                $table->unsignedInteger('revision')->default(1);
                if ($name === 'identity_mappings') {
                    $table->unique(['tenant_id', 'id']);
                } else {
                    $table->uuid('mapping_id');
                    $table->timestampTz('recorded_at', 6);
                    $table->unique(['tenant_id', 'mapping_id', 'revision']);
                    $table->foreign(['tenant_id', 'mapping_id'])->references(['tenant_id', 'id'])->on('identity_mappings');
                }
                foreach (['identity_id' => 'identities', 'employee_id' => 'employees', 'reviewer_id' => 'users', 'changed_by' => 'users'] as $column => $parent) {
                    $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($parent);
                }
            });
            DB::statement("ALTER TABLE $name ADD CONSTRAINT {$name}_state_check CHECK ((status = 'candidate' AND reviewer_id IS NULL AND reviewed_at IS NULL) OR (status IN ('approved','rejected') AND reviewer_id IS NOT NULL AND reviewed_at IS NOT NULL))");
            DB::statement("ALTER TABLE $name ADD CONSTRAINT {$name}_period_check CHECK (valid_to IS NULL OR valid_to > valid_from)");
            DB::statement("ALTER TABLE $name ADD CONSTRAINT {$name}_reason_check CHECK (length(btrim(reason)) BETWEEN 1 AND 1000 AND revision > 0)");
        }
        DB::statement("ALTER TABLE source_instances ADD CONSTRAINT source_kind_check CHECK (kind IN ('gitlab','task_tracker','attendance','qa','manual'))");
        DB::statement('ALTER TABLE identities ADD CONSTRAINT external_id_nonempty CHECK (length(btrim(external_user_id)) > 0)');
        // One source identity has at most one approved employee at any event instant.
        DB::statement("ALTER TABLE identity_mappings ADD CONSTRAINT identity_approved_no_overlap EXCLUDE USING gist (tenant_id WITH =, identity_id WITH =, tstzrange(valid_from, valid_to, '[)') WITH &&) WHERE (status = 'approved')");
        foreach (['source_instances', 'identities', 'identity_mappings', 'identity_mapping_histories'] as $name) {
            DB::statement("ALTER TABLE $name ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $name FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $name USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.guard_external_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.id <> OLD.id OR NEW.tenant_id <> OLD.tenant_id OR NEW.source_instance_id <> OLD.source_instance_id OR NEW.external_user_id <> OLD.external_user_id THEN
        RAISE EXCEPTION 'External identity keys are immutable' USING ERRCODE = '42501';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER identity_keys_immutable BEFORE UPDATE ON public.identities FOR EACH ROW EXECUTE FUNCTION public.guard_external_identity();
CREATE FUNCTION public.guard_identity_mapping_revision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.id <> OLD.id OR NEW.tenant_id <> OLD.tenant_id OR NEW.identity_id <> OLD.identity_id THEN
        RAISE EXCEPTION 'Mapping identity is immutable' USING ERRCODE = '42501';
    END IF;
    IF NEW.revision <> OLD.revision + 1 THEN
        RAISE EXCEPTION 'Mapping revision must advance' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER mapping_revision_guard BEFORE UPDATE ON public.identity_mappings FOR EACH ROW EXECUTE FUNCTION public.guard_identity_mapping_revision();
CREATE FUNCTION public.snapshot_identity_mapping() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO public.identity_mapping_histories (id, tenant_id, mapping_id, identity_id, employee_id, status, reviewer_id, changed_by, reason, reviewed_at, valid_from, valid_to, revision, recorded_at)
    VALUES (gen_random_uuid(), NEW.tenant_id, NEW.id, NEW.identity_id, NEW.employee_id, NEW.status, NEW.reviewer_id, NEW.changed_by, NEW.reason, NEW.reviewed_at, NEW.valid_from, NEW.valid_to, NEW.revision, clock_timestamp());
    RETURN NEW;
END $$;
CREATE TRIGGER mapping_history_snapshot AFTER INSERT OR UPDATE ON public.identity_mappings FOR EACH ROW EXECUTE FUNCTION public.snapshot_identity_mapping();
CREATE TRIGGER mapping_history_immutable BEFORE UPDATE OR DELETE ON public.identity_mapping_histories FOR EACH ROW EXECUTE FUNCTION public.reject_audit_mutation();
SQL);
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.identity_mapping_histories FROM monitoring_ai_app');
        DB::statement('REVOKE DELETE, TRUNCATE ON public.identities, public.identity_mappings FROM monitoring_ai_app');
    }

    public function down(): void
    {
        foreach (['identity_mapping_histories', 'identity_mappings', 'identities', 'source_instances'] as $name) {
            Schema::dropIfExists($name);
        }
        foreach (['snapshot_identity_mapping', 'guard_identity_mapping_revision', 'guard_external_identity'] as $name) {
            DB::statement("DROP FUNCTION IF EXISTS public.$name()");
        }
    }
};
