<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE qa_changes (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, change_seq bigint GENERATED ALWAYS AS IDENTITY UNIQUE, kind varchar(40) NOT NULL,
 receipt_id uuid, definition_id uuid, entry_id uuid, plan_id uuid, selection_id uuid, execution_id uuid, review_id uuid, defect_event_id uuid, handoff_event_id uuid,
 criterion_version_id uuid, task_version_id uuid, baseline_id uuid, lifecycle_change_id uuid,
 input_hash varchar(64) NOT NULL CHECK(input_hash ~ '^[0-9a-f]{64}$'), actor_id uuid NOT NULL, authority_proof jsonb, origin varchar(20) NOT NULL DEFAULT 'qa' CHECK(origin IN ('qa','lifecycle')),
 created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 CHECK(num_nonnulls(receipt_id,definition_id,entry_id,plan_id,selection_id,execution_id,review_id,defect_event_id,handoff_event_id,criterion_version_id,task_version_id,baseline_id,lifecycle_change_id)=1),
 CHECK((origin='qa' AND authority_proof->>'version'='qa-authority-v1' AND num_nonnulls(criterion_version_id,task_version_id,baseline_id,lifecycle_change_id)=0) OR (origin='lifecycle' AND authority_proof IS NULL AND lifecycle_change_id IS NOT NULL)),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,change_seq,id), FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id),
 FOREIGN KEY(tenant_id,project_id,receipt_id) REFERENCES qa_receipts(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,definition_id) REFERENCES qa_definition_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,entry_id) REFERENCES qa_plan_entry_snapshots(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,plan_id) REFERENCES qa_plan_versions(tenant_id,project_id,id),
 FOREIGN KEY(tenant_id,project_id,selection_id) REFERENCES qa_selection_events(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,execution_id) REFERENCES qa_execution_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,review_id) REFERENCES qa_reviews(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,defect_event_id) REFERENCES qa_defect_events(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,handoff_event_id) REFERENCES qa_handoff_events(tenant_id,project_id,id),
 FOREIGN KEY(tenant_id,project_id,criterion_version_id) REFERENCES criterion_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,task_version_id) REFERENCES task_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,baseline_id) REFERENCES baselines(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,lifecycle_change_id) REFERENCES lifecycle_changes(tenant_id,project_id,id)
);
CREATE TABLE qa_applicability (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, change_id uuid NOT NULL, change_seq bigint NOT NULL,
 status varchar(10) NOT NULL DEFAULT 'current' CHECK(status IN ('current','stale','revoked','unknown')), reason_code varchar(40), observed_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(tenant_id,project_id,id), UNIQUE(change_id), FOREIGN KEY(tenant_id,project_id,change_id) REFERENCES qa_changes(tenant_id,project_id,id)
);
CREATE TABLE qa_invalidations (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, change_id uuid NOT NULL, input_hash varchar(64) NOT NULL CHECK(input_hash ~ '^[0-9a-f]{64}$'), reason_code varchar(40) NOT NULL,
 actor_id uuid NOT NULL, observed_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), UNIQUE(tenant_id,project_id,id), UNIQUE(change_id,input_hash,reason_code),
 FOREIGN KEY(tenant_id,project_id,change_id) REFERENCES qa_changes(tenant_id,project_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
CREATE TABLE qa_outbox (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, change_id uuid, invalidation_id uuid, is_catchup boolean NOT NULL DEFAULT false,
 status varchar(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','retry','completed','dead_letter')), attempt_count integer NOT NULL DEFAULT 0 CHECK(attempt_count BETWEEN 0 AND 5),
 watermark bigint NOT NULL DEFAULT 0 CHECK(watermark>=0), available_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), lease_token uuid, lease_until timestamptz(6), completed_at timestamptz(6), reason_code varchar(40),
 processing_authority jsonb, processing_authority_hash varchar(64) CHECK(processing_authority_hash IS NULL OR processing_authority_hash ~ '^[0-9a-f]{64}$'),
 CHECK((processing_authority IS NULL)=(processing_authority_hash IS NULL)), CHECK(num_nonnulls(change_id,invalidation_id)+(CASE WHEN is_catchup THEN 1 ELSE 0 END)=1),
 CHECK((status='processing')=(lease_token IS NOT NULL AND lease_until IS NOT NULL)), CHECK((status='completed')=(completed_at IS NOT NULL)),
 UNIQUE(tenant_id,project_id,id), UNIQUE(change_id), UNIQUE(invalidation_id), FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id), FOREIGN KEY(tenant_id,project_id,change_id) REFERENCES qa_changes(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,invalidation_id) REFERENCES qa_invalidations(tenant_id,project_id,id)
);
CREATE UNIQUE INDEX qa_one_pending_catchup ON qa_outbox(tenant_id,project_id) WHERE is_catchup AND status IN ('pending','processing','retry');
ALTER TABLE qa_request_keys ADD FOREIGN KEY(tenant_id,project_id,entry_id) REFERENCES qa_plan_entry_snapshots(tenant_id,project_id,id);
ALTER TABLE qa_request_keys ADD FOREIGN KEY(tenant_id,project_id,plan_id) REFERENCES qa_plan_versions(tenant_id,project_id,id);
ALTER TABLE qa_request_keys ADD FOREIGN KEY(tenant_id,project_id,defect_id) REFERENCES qa_defects(tenant_id,project_id,id);
ALTER TABLE qa_request_keys ADD FOREIGN KEY(tenant_id,project_id,handoff_id) REFERENCES qa_handoffs(tenant_id,project_id,id);
SQL);
        foreach (['qa_changes', 'qa_applicability', 'qa_invalidations', 'qa_outbox'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
            DB::statement("REVOKE DELETE,TRUNCATE ON $table FROM monitoring_ai_app");
            DB::statement("CREATE TRIGGER qa_no_delete BEFORE DELETE ON $table FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            DB::statement("CREATE TRIGGER qa_no_truncate BEFORE TRUNCATE ON $table FOR EACH STATEMENT EXECUTE FUNCTION reject_audit_mutation()");
            if (in_array($table, ['qa_applicability', 'qa_outbox'], true)) {
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
