<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE qa_defects (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, receipt_id uuid NOT NULL, receipt_kind varchar(30) NOT NULL DEFAULT 'defect' CHECK(receipt_kind='defect'), logical_key varchar(100) NOT NULL,
 criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL, definition_id uuid NOT NULL, build_id uuid NOT NULL, environment_id uuid NOT NULL, execution_id uuid,
 severity varchar(10) NOT NULL CHECK(severity IN ('critical','high','medium','low')), reproducibility varchar(20) NOT NULL CHECK(reproducibility IN ('reproduced','not_reproduced','unknown')), reproduction varchar(4000) NOT NULL CHECK(octet_length(reproduction)<=4000),
 revision bigint NOT NULL DEFAULT 0 CHECK(revision>=0), current_event_id uuid, state varchar(30) NOT NULL DEFAULT 'open' CHECK(state IN ('open','triaged','fixed_awaiting_retest','verified_closed','reopened')),
 disposition varchar(10) NOT NULL DEFAULT 'active' CHECK(disposition IN ('active','duplicate')), canonical_defect_id uuid,
 CHECK((revision=0)=(current_event_id IS NULL)), CHECK((disposition='duplicate')=(canonical_defect_id IS NOT NULL)), CHECK(canonical_defect_id IS NULL OR canonical_defect_id<>id),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,logical_key), UNIQUE(receipt_id),
 FOREIGN KEY(tenant_id,project_id,receipt_id,receipt_kind) REFERENCES qa_receipts(tenant_id,project_id,id,kind), FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id,definition_id) REFERENCES qa_definition_versions(tenant_id,project_id,criterion_id,criterion_version_id,id),
 FOREIGN KEY(tenant_id,project_id,build_id) REFERENCES qa_build_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,environment_id) REFERENCES qa_environment_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,execution_id) REFERENCES qa_execution_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,canonical_defect_id) REFERENCES qa_defects(tenant_id,project_id,id)
);
CREATE TABLE qa_defect_events (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, defect_id uuid NOT NULL, revision bigint NOT NULL CHECK(revision>0),
 event_type varchar(30) NOT NULL CHECK(event_type IN ('opened','triaged','fix_requested','verified_closed','reopened','scope_revised','duplicate','context_added')),
 state varchar(30) NOT NULL CHECK(state IN ('open','triaged','fixed_awaiting_retest','verified_closed','reopened')), disposition varchar(10) NOT NULL DEFAULT 'active' CHECK(disposition IN ('active','duplicate')), canonical_defect_id uuid,
 criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL, definition_id uuid NOT NULL, build_id uuid NOT NULL, environment_id uuid NOT NULL,
 task_version_id uuid, selection_id uuid, execution_id uuid, fix_snapshot_id uuid, fix_request_event_id uuid,
 cause varchar(30) CHECK(cause IN ('incomplete_fix','new_scope','environment','regression','insufficient_evidence')), reason varchar(2000) NOT NULL CHECK(octet_length(reason) BETWEEN 1 AND 2000 AND length(btrim(reason))>0),
 actor_id uuid NOT NULL, authority_proof jsonb NOT NULL CHECK(authority_proof->>'version'='qa-authority-v1'), effective_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,defect_id,revision), UNIQUE(tenant_id,project_id,defect_id,revision,id),
 FOREIGN KEY(tenant_id,project_id,defect_id) REFERENCES qa_defects(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,canonical_defect_id) REFERENCES qa_defects(tenant_id,project_id,id),
 FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id,definition_id) REFERENCES qa_definition_versions(tenant_id,project_id,criterion_id,criterion_version_id,id),
 FOREIGN KEY(tenant_id,project_id,build_id) REFERENCES qa_build_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,environment_id) REFERENCES qa_environment_versions(tenant_id,project_id,id),
 FOREIGN KEY(tenant_id,project_id,task_version_id) REFERENCES task_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,selection_id) REFERENCES qa_selection_events(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,execution_id) REFERENCES qa_execution_versions(tenant_id,project_id,id),
 FOREIGN KEY(tenant_id,project_id,fix_snapshot_id) REFERENCES developer_snapshots(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,fix_request_event_id) REFERENCES qa_defect_events(tenant_id,project_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
ALTER TABLE qa_defects ADD FOREIGN KEY(tenant_id,project_id,id,revision,current_event_id) REFERENCES qa_defect_events(tenant_id,project_id,defect_id,revision,id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE qa_execution_attempts ADD FOREIGN KEY(tenant_id,project_id,fix_request_event_id) REFERENCES qa_defect_events(tenant_id,project_id,id);
CREATE TABLE qa_handoffs (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, receipt_id uuid NOT NULL, receipt_kind varchar(30) NOT NULL DEFAULT 'handoff' CHECK(receipt_kind='handoff'),
 task_version_id uuid NOT NULL, criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL, execution_id uuid, defect_id uuid, linked_handoff_id uuid,
 handoff_type varchar(30) NOT NULL CHECK(handoff_type IN ('verification_blocked','fix_requested','retest_requested','support_requested')), responsibility jsonb NOT NULL CHECK(jsonb_typeof(responsibility)='object'),
 opened_at timestamptz(6) NOT NULL, revision bigint NOT NULL DEFAULT 0 CHECK(revision>=0), current_event_id uuid, state varchar(20) NOT NULL DEFAULT 'open' CHECK(state IN ('open','acknowledged','resolved')), owner_id uuid NOT NULL,
 CHECK((revision=0)=(current_event_id IS NULL)), UNIQUE(tenant_id,project_id,id), UNIQUE(receipt_id),
 FOREIGN KEY(tenant_id,project_id,receipt_id,receipt_kind) REFERENCES qa_receipts(tenant_id,project_id,id,kind), FOREIGN KEY(tenant_id,project_id,task_version_id) REFERENCES task_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id) REFERENCES criterion_versions(tenant_id,project_id,criterion_id,id),
 FOREIGN KEY(tenant_id,project_id,execution_id) REFERENCES qa_execution_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,defect_id) REFERENCES qa_defects(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,linked_handoff_id) REFERENCES qa_handoffs(tenant_id,project_id,id), FOREIGN KEY(tenant_id,owner_id) REFERENCES employees(tenant_id,id)
);
CREATE TABLE qa_handoff_events (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, handoff_id uuid NOT NULL, revision bigint NOT NULL CHECK(revision>0),
 event_type varchar(20) NOT NULL CHECK(event_type IN ('opened','acknowledged','resolved','context_added','owner_changed')), state varchar(20) NOT NULL CHECK(state IN ('open','acknowledged','resolved')), owner_id uuid NOT NULL,
 execution_id uuid, defect_event_id uuid, reason varchar(2000) NOT NULL CHECK(octet_length(reason) BETWEEN 1 AND 2000 AND length(btrim(reason))>0), actor_id uuid NOT NULL,
 authority_proof jsonb NOT NULL CHECK(authority_proof->>'version'='qa-authority-v1'), effective_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,handoff_id,revision), UNIQUE(tenant_id,project_id,handoff_id,revision,id),
 FOREIGN KEY(tenant_id,project_id,handoff_id) REFERENCES qa_handoffs(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,execution_id) REFERENCES qa_execution_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,defect_event_id) REFERENCES qa_defect_events(tenant_id,project_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id), FOREIGN KEY(tenant_id,owner_id) REFERENCES employees(tenant_id,id)
);
ALTER TABLE qa_handoffs ADD FOREIGN KEY(tenant_id,project_id,id,revision,current_event_id) REFERENCES qa_handoff_events(tenant_id,project_id,handoff_id,revision,id) DEFERRABLE INITIALLY DEFERRED;
SQL);
        foreach (['qa_defects', 'qa_defect_events', 'qa_handoffs', 'qa_handoff_events'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
            DB::statement("REVOKE DELETE,TRUNCATE ON $table FROM monitoring_ai_app");
            DB::statement("CREATE TRIGGER qa_no_delete BEFORE DELETE ON $table FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            DB::statement("CREATE TRIGGER qa_no_truncate BEFORE TRUNCATE ON $table FOR EACH STATEMENT EXECUTE FUNCTION reject_audit_mutation()");
            if (in_array($table, ['qa_defects', 'qa_handoffs'], true)) {
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
