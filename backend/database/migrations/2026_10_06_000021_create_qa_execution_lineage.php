<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE qa_execution_series (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, source_instance_id uuid NOT NULL, criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL, definition_id uuid NOT NULL, build_id uuid NOT NULL, environment_id uuid NOT NULL,
 revision bigint NOT NULL DEFAULT 0 CHECK(revision>=0), current_ordinal bigint NOT NULL DEFAULT 0 CHECK(current_ordinal>=0), current_attempt_id uuid,
 CHECK((revision=0)=(current_attempt_id IS NULL)), UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,source_instance_id,definition_id,build_id,environment_id),
 UNIQUE(tenant_id,project_id,criterion_id,criterion_version_id,definition_id,build_id,environment_id,id),
 FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id,definition_id) REFERENCES qa_definition_versions(tenant_id,project_id,criterion_id,criterion_version_id,id),
 FOREIGN KEY(tenant_id,project_id,build_id) REFERENCES qa_build_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,environment_id) REFERENCES qa_environment_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,source_instance_id) REFERENCES source_instances(tenant_id,id)
);
CREATE TABLE qa_execution_attempts (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, series_id uuid NOT NULL, ordinal bigint NOT NULL CHECK(ordinal>0), execution_key varchar(100) NOT NULL, predecessor_id uuid, retests_execution_id uuid, fix_request_event_id uuid,
 revision bigint NOT NULL DEFAULT 0 CHECK(revision>=0), source_revision bigint NOT NULL DEFAULT 0 CHECK(source_revision>=0), current_version_id uuid,
 CHECK((revision=0)=(current_version_id IS NULL)), CHECK(predecessor_id IS NULL OR predecessor_id<>id), UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,series_id,id), UNIQUE(tenant_id,project_id,series_id,ordinal,id), UNIQUE(series_id,ordinal), UNIQUE(series_id,execution_key),
 FOREIGN KEY(tenant_id,project_id,series_id) REFERENCES qa_execution_series(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,series_id,predecessor_id) REFERENCES qa_execution_attempts(tenant_id,project_id,series_id,id), FOREIGN KEY(tenant_id,project_id,retests_execution_id) REFERENCES qa_execution_attempts(tenant_id,project_id,id)
);
CREATE TABLE qa_execution_versions (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, series_id uuid NOT NULL, attempt_id uuid NOT NULL,
 criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL, definition_id uuid NOT NULL, build_id uuid NOT NULL, environment_id uuid NOT NULL,
 source_revision bigint NOT NULL CHECK(source_revision>0), receipt_id uuid NOT NULL, receipt_kind varchar(30) NOT NULL DEFAULT 'execution' CHECK(receipt_kind='execution'),
 outcome varchar(10) NOT NULL CHECK(outcome IN ('passed','failed','blocked')), content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'), responsibility jsonb NOT NULL CHECK(jsonb_typeof(responsibility)='object'), occurred_at timestamptz(6) NOT NULL,
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,attempt_id,source_revision,id), UNIQUE(attempt_id,source_revision), UNIQUE(receipt_id),
 FOREIGN KEY(tenant_id,project_id,series_id,attempt_id) REFERENCES qa_execution_attempts(tenant_id,project_id,series_id,id),
 FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id,definition_id,build_id,environment_id,series_id) REFERENCES qa_execution_series(tenant_id,project_id,criterion_id,criterion_version_id,definition_id,build_id,environment_id,id),
 FOREIGN KEY(tenant_id,project_id,receipt_id,receipt_kind) REFERENCES qa_receipts(tenant_id,project_id,id,kind)
);
ALTER TABLE qa_execution_series ADD FOREIGN KEY(tenant_id,project_id,id,current_ordinal,current_attempt_id) REFERENCES qa_execution_attempts(tenant_id,project_id,series_id,ordinal,id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE qa_execution_attempts ADD FOREIGN KEY(tenant_id,project_id,id,source_revision,current_version_id) REFERENCES qa_execution_versions(tenant_id,project_id,attempt_id,source_revision,id) DEFERRABLE INITIALLY DEFERRED;
SQL);
        foreach (['qa_execution_series', 'qa_execution_attempts', 'qa_execution_versions'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
            DB::statement("REVOKE DELETE,TRUNCATE ON $table FROM monitoring_ai_app");
            DB::statement("CREATE TRIGGER qa_no_delete BEFORE DELETE ON $table FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            DB::statement("CREATE TRIGGER qa_no_truncate BEFORE TRUNCATE ON $table FOR EACH STATEMENT EXECUTE FUNCTION reject_audit_mutation()");
            if ($table !== 'qa_execution_versions') {
                DB::statement("CREATE TRIGGER qa_mutation_guard BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION guard_qa_mutation()");
            } else {
                DB::statement("REVOKE UPDATE ON $table FROM monitoring_ai_app");
                DB::statement("CREATE TRIGGER qa_no_update BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Immutable QA history must be preserved.');
    }
};
