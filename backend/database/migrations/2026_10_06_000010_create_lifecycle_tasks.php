<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE lifecycle_tasks (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL,
 revision integer NOT NULL CHECK(revision>0), current_version_id uuid NOT NULL,
 created_at timestamptz(6) NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id)
);
CREATE TABLE task_versions (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, task_id uuid NOT NULL,
 revision integer NOT NULL CHECK(revision>0), title varchar(500) NOT NULL CHECK(length(btrim(title))>0),
 assignee_id uuid NOT NULL, estimate_basis varchar(4000) NOT NULL CHECK(length(btrim(estimate_basis))>0),
 remaining_effort bigint CHECK(remaining_effort BETWEEN 0 AND 9007199254740991),
 expected_test_keys jsonb NOT NULL CHECK(jsonb_typeof(expected_test_keys)='array' AND jsonb_array_length(expected_test_keys) BETWEEN 1 AND 20),
 status varchar(40) NOT NULL CHECK(status IN ('unstarted','in_progress','blocked','implemented_awaiting_verification','done')),
 content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'), actor_id uuid NOT NULL,
 effective_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 source_connector_id uuid, source_instance_id uuid, source_object_type varchar(30), source_object_id varchar(200), source_receipt_id uuid,
 CHECK(num_nonnulls(source_connector_id,source_instance_id,source_object_type,source_object_id,source_receipt_id)=0 OR (num_nonnulls(source_connector_id,source_instance_id,source_object_type,source_object_id,source_receipt_id)=5 AND source_object_type='ticket')),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,task_id,id), UNIQUE(tenant_id,project_id,task_id,revision,id), UNIQUE(tenant_id,project_id,task_id,revision),
 FOREIGN KEY(tenant_id,project_id,task_id) REFERENCES lifecycle_tasks(tenant_id,project_id,id),
 FOREIGN KEY(tenant_id,assignee_id) REFERENCES employees(tenant_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id),
 FOREIGN KEY(tenant_id,project_id,source_connector_id,source_instance_id,source_object_type,source_object_id,source_receipt_id)
 REFERENCES ingestion_receipts(tenant_id,project_id,connector_id,source_instance_id,source_object_type,source_object_id,id)
);
ALTER TABLE lifecycle_tasks ADD CONSTRAINT task_current_version_fk FOREIGN KEY(tenant_id,project_id,id,revision,current_version_id) REFERENCES task_versions(tenant_id,project_id,task_id,revision,id) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE task_criterion_links (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, task_id uuid NOT NULL, task_version_id uuid NOT NULL,
 criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL,
 UNIQUE(tenant_id,project_id,task_version_id,criterion_id),
 FOREIGN KEY(tenant_id,project_id,task_id,task_version_id) REFERENCES task_versions(tenant_id,project_id,task_id,id),
 FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id) REFERENCES criterion_versions(tenant_id,project_id,criterion_id,id)
);
CREATE TABLE task_dependency_events (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, task_id uuid NOT NULL, prerequisite_id uuid NOT NULL,
 actor_id uuid NOT NULL, effective_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 CHECK(task_id<>prerequisite_id), UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,task_id,prerequisite_id,id),
 FOREIGN KEY(tenant_id,project_id,task_id) REFERENCES lifecycle_tasks(tenant_id,project_id,id),
 FOREIGN KEY(tenant_id,project_id,prerequisite_id) REFERENCES lifecycle_tasks(tenant_id,project_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
CREATE TABLE task_dependencies (
 tenant_id uuid NOT NULL, project_id uuid NOT NULL, task_id uuid NOT NULL, prerequisite_id uuid NOT NULL, event_id uuid NOT NULL,
 CHECK(task_id<>prerequisite_id), PRIMARY KEY(tenant_id,project_id,task_id,prerequisite_id),
 FOREIGN KEY(tenant_id,project_id,task_id,prerequisite_id,event_id) REFERENCES task_dependency_events(tenant_id,project_id,task_id,prerequisite_id,id)
);
CREATE FUNCTION guard_task_projection() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.id,NEW.tenant_id,NEW.project_id,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.project_id,OLD.created_at) THEN
  RAISE EXCEPTION 'Task identity is immutable' USING ERRCODE='42501';
 END IF;
 IF NEW.revision<>OLD.revision+1 OR NEW.current_version_id=OLD.current_version_id THEN
  RAISE EXCEPTION 'Task revision must advance' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER task_projection_guard BEFORE UPDATE ON lifecycle_tasks FOR EACH ROW EXECUTE FUNCTION guard_task_projection();
SQL);
        foreach (['lifecycle_tasks', 'task_versions', 'task_criterion_links', 'task_dependency_events', 'task_dependencies'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
            DB::statement("REVOKE DELETE,TRUNCATE ON $table FROM monitoring_ai_app");
            if ($table !== 'lifecycle_tasks') {
                DB::statement("REVOKE UPDATE ON $table FROM monitoring_ai_app");
                DB::statement("CREATE TRIGGER task_snapshot_immutable BEFORE UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            }
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE lifecycle_tasks DROP CONSTRAINT task_current_version_fk');
        foreach (['task_dependencies', 'task_dependency_events', 'task_criterion_links', 'task_versions', 'lifecycle_tasks'] as $table) {
            DB::statement("DROP TABLE $table");
        }
        DB::statement('DROP FUNCTION guard_task_projection()');
    }
};
