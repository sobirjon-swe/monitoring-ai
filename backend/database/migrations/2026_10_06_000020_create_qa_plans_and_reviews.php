<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE qa_plan_entry_snapshots (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, baseline_id uuid NOT NULL, policy_id uuid NOT NULL, baseline_item_id uuid NOT NULL, criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL,
 included boolean NOT NULL, exclusion_reason varchar(2000), content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'), actor_id uuid NOT NULL, authority_proof jsonb NOT NULL CHECK(authority_proof->>'version'='qa-authority-v1'), created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 CHECK((included AND exclusion_reason IS NULL) OR (NOT included AND octet_length(exclusion_reason) BETWEEN 1 AND 2000)), UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,baseline_id,criterion_version_id,id),
 FOREIGN KEY(tenant_id,project_id,baseline_id,policy_id,criterion_version_id,baseline_item_id) REFERENCES baseline_items(tenant_id,project_id,baseline_id,policy_id,criterion_version_id,id),
 FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id) REFERENCES criterion_versions(tenant_id,project_id,criterion_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
CREATE TABLE qa_plan_entry_definitions (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, entry_id uuid NOT NULL, criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL, definition_id uuid NOT NULL, source_instance_id uuid NOT NULL, required boolean NOT NULL,
 UNIQUE(tenant_id,project_id,id), UNIQUE(entry_id,definition_id),
 FOREIGN KEY(tenant_id,project_id,entry_id) REFERENCES qa_plan_entry_snapshots(tenant_id,project_id,id),
 FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id,definition_id) REFERENCES qa_definition_versions(tenant_id,project_id,criterion_id,criterion_version_id,id), FOREIGN KEY(tenant_id,source_instance_id) REFERENCES source_instances(tenant_id,id)
);
ALTER TABLE qa_plan_entry_snapshots ADD UNIQUE(tenant_id,project_id,criterion_id,criterion_version_id,id);
ALTER TABLE qa_plan_entry_definitions ADD FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id,entry_id) REFERENCES qa_plan_entry_snapshots(tenant_id,project_id,criterion_id,criterion_version_id,id);
CREATE TABLE qa_plan_versions (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, baseline_id uuid NOT NULL, policy_id uuid NOT NULL, revision bigint NOT NULL CHECK(revision>0), logical_key varchar(100) NOT NULL,
 content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'), actor_id uuid NOT NULL, authority_proof jsonb NOT NULL CHECK(authority_proof->>'version'='qa-authority-v1'), created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 review_revision integer NOT NULL DEFAULT 0 CHECK(review_revision>=0), current_review_id uuid, review_state varchar(20) NOT NULL DEFAULT 'draft' CHECK(review_state IN ('draft','approved','rejected','revoked')), CHECK((review_revision=0)=(current_review_id IS NULL)),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,baseline_id,id), UNIQUE(tenant_id,project_id,logical_key,revision),
 FOREIGN KEY(tenant_id,project_id,policy_id,baseline_id) REFERENCES baselines(tenant_id,project_id,policy_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
CREATE TABLE qa_plan_entries (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, plan_id uuid NOT NULL, baseline_id uuid NOT NULL, criterion_version_id uuid NOT NULL, entry_id uuid NOT NULL,
 UNIQUE(tenant_id,project_id,id), UNIQUE(plan_id,criterion_version_id),
 FOREIGN KEY(tenant_id,project_id,baseline_id,plan_id) REFERENCES qa_plan_versions(tenant_id,project_id,baseline_id,id), FOREIGN KEY(tenant_id,project_id,baseline_id,criterion_version_id,entry_id) REFERENCES qa_plan_entry_snapshots(tenant_id,project_id,baseline_id,criterion_version_id,id)
);
CREATE TABLE qa_reviews (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, definition_id uuid, plan_id uuid, revision integer NOT NULL CHECK(revision>0),
 decision varchar(20) NOT NULL CHECK(decision IN ('reviewed','approved','rejected','revoked')), subject_hash varchar(64) NOT NULL CHECK(subject_hash ~ '^[0-9a-f]{64}$'), actor_id uuid NOT NULL,
 authority_proof jsonb NOT NULL CHECK(authority_proof->>'version'='qa-authority-v1'), reason varchar(2000) NOT NULL CHECK(octet_length(reason) BETWEEN 1 AND 2000 AND length(btrim(reason))>0), effective_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 CHECK(num_nonnulls(definition_id,plan_id)=1), CHECK((definition_id IS NULL OR decision<>'approved') AND (plan_id IS NULL OR decision<>'reviewed')),
 UNIQUE(tenant_id,project_id,id), UNIQUE(definition_id,revision), UNIQUE(plan_id,revision), UNIQUE(tenant_id,project_id,definition_id,revision,id), UNIQUE(tenant_id,project_id,plan_id,revision,id),
 FOREIGN KEY(tenant_id,project_id,definition_id) REFERENCES qa_definition_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,plan_id) REFERENCES qa_plan_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
ALTER TABLE qa_definition_versions ADD FOREIGN KEY(tenant_id,project_id,id,review_revision,current_review_id) REFERENCES qa_reviews(tenant_id,project_id,definition_id,revision,id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE qa_plan_versions ADD FOREIGN KEY(tenant_id,project_id,id,review_revision,current_review_id) REFERENCES qa_reviews(tenant_id,project_id,plan_id,revision,id) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE qa_selection_events (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, revision bigint NOT NULL CHECK(revision>0), plan_id uuid NOT NULL, build_id uuid NOT NULL, environment_id uuid NOT NULL,
 actor_id uuid NOT NULL, authority_proof jsonb NOT NULL CHECK(authority_proof->>'version'='qa-authority-v1'), reason varchar(2000) NOT NULL CHECK(octet_length(reason) BETWEEN 1 AND 2000), selected_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,revision), UNIQUE(tenant_id,project_id,revision,id),
 FOREIGN KEY(tenant_id,project_id,plan_id) REFERENCES qa_plan_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,build_id) REFERENCES qa_build_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,environment_id) REFERENCES qa_environment_versions(tenant_id,project_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
ALTER TABLE qa_project_state ADD FOREIGN KEY(tenant_id,project_id,selection_revision,current_selection_id) REFERENCES qa_selection_events(tenant_id,project_id,revision,id) DEFERRABLE INITIALLY DEFERRED;
SQL);
        foreach (['qa_plan_entry_snapshots', 'qa_plan_entry_definitions', 'qa_plan_versions', 'qa_plan_entries', 'qa_reviews', 'qa_selection_events'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
            DB::statement("REVOKE DELETE,TRUNCATE ON $table FROM monitoring_ai_app");
            DB::statement("CREATE TRIGGER qa_no_delete BEFORE DELETE ON $table FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            DB::statement("CREATE TRIGGER qa_no_truncate BEFORE TRUNCATE ON $table FOR EACH STATEMENT EXECUTE FUNCTION reject_audit_mutation()");
            if ($table === 'qa_plan_versions') {
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
