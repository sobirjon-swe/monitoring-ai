<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE evidence_link_proposals (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL,
 criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL, receipt_id uuid NOT NULL,
 connector_id uuid NOT NULL, source_instance_id uuid NOT NULL, source_object_type varchar(30) NOT NULL, source_object_id varchar(200) NOT NULL,
 canonical_hash varchar(64) NOT NULL CHECK(canonical_hash ~ '^[0-9a-f]{64}$'),
 locator varchar(40) NOT NULL CHECK(locator IN ('payload.title','payload.status','payload.external_ticket_id')),
 actor_id uuid NOT NULL, effective_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,criterion_id,criterion_version_id,id),
 FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id) REFERENCES criterion_versions(tenant_id,project_id,criterion_id,id),
 FOREIGN KEY(tenant_id,project_id,connector_id,source_instance_id,source_object_type,source_object_id,receipt_id) REFERENCES ingestion_receipts(tenant_id,project_id,connector_id,source_instance_id,source_object_type,source_object_id,id),
 FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
CREATE TABLE evidence_reviews (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, link_id uuid NOT NULL,
 revision integer NOT NULL CHECK(revision>0), decision varchar(20) NOT NULL CHECK(decision IN ('reviewed','rejected','revoked')),
 actor_id uuid NOT NULL, reason varchar(500) NOT NULL CHECK(length(btrim(reason))>0), effective_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,link_id,revision), UNIQUE(tenant_id,project_id,link_id,revision,id),
 FOREIGN KEY(tenant_id,project_id,link_id) REFERENCES evidence_link_proposals(tenant_id,project_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
CREATE TABLE evidence_review_heads (
 tenant_id uuid NOT NULL, project_id uuid NOT NULL, link_id uuid NOT NULL, revision integer NOT NULL, current_review_id uuid NOT NULL,
 PRIMARY KEY(tenant_id,project_id,link_id),
 FOREIGN KEY(tenant_id,project_id,link_id,revision,current_review_id) REFERENCES evidence_reviews(tenant_id,project_id,link_id,revision,id)
);
CREATE TABLE qa_test_identities (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, criterion_id uuid NOT NULL,
 test_key varchar(100) NOT NULL CHECK(length(btrim(test_key))>0),
 UNIQUE(tenant_id,project_id,criterion_id,test_key), UNIQUE(tenant_id,project_id,criterion_id,test_key,id),
 FOREIGN KEY(tenant_id,project_id,criterion_id) REFERENCES criteria(tenant_id,project_id,id)
);
CREATE TABLE qa_observations (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL,
 test_identity_id uuid NOT NULL, test_key varchar(100) NOT NULL, link_id uuid NOT NULL,
 result varchar(20) NOT NULL CHECK(result IN ('passed','failed','missing')), revision integer NOT NULL CHECK(revision>0),
 actor_id uuid NOT NULL, effective_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,test_identity_id,revision), UNIQUE(tenant_id,project_id,test_identity_id,revision,id),
 FOREIGN KEY(tenant_id,project_id,criterion_id,test_key,test_identity_id) REFERENCES qa_test_identities(tenant_id,project_id,criterion_id,test_key,id),
 FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id,link_id) REFERENCES evidence_link_proposals(tenant_id,project_id,criterion_id,criterion_version_id,id),
 FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
CREATE TABLE qa_observation_heads (
 tenant_id uuid NOT NULL, project_id uuid NOT NULL, test_identity_id uuid NOT NULL, revision integer NOT NULL, current_observation_id uuid NOT NULL,
 PRIMARY KEY(tenant_id,project_id,test_identity_id),
 FOREIGN KEY(tenant_id,project_id,test_identity_id,revision,current_observation_id) REFERENCES qa_observations(tenant_id,project_id,test_identity_id,revision,id)
);
CREATE FUNCTION guard_evidence_head() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (to_jsonb(NEW)-'revision'-'current_review_id'-'current_observation_id') IS DISTINCT FROM (to_jsonb(OLD)-'revision'-'current_review_id'-'current_observation_id') OR NEW.revision<>OLD.revision+1 THEN
  RAISE EXCEPTION 'Evidence head must advance' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['evidence_link_proposals', 'evidence_reviews', 'evidence_review_heads', 'qa_test_identities', 'qa_observations', 'qa_observation_heads'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
            DB::statement("REVOKE DELETE,TRUNCATE ON $table FROM monitoring_ai_app");
            if (str_ends_with($table, '_heads')) {
                DB::statement("CREATE TRIGGER evidence_head_guard BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION guard_evidence_head()");
            } else {
                DB::statement("REVOKE UPDATE ON $table FROM monitoring_ai_app");
                DB::statement("CREATE TRIGGER evidence_snapshot_immutable BEFORE UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            }
        }
    }

    public function down(): void
    {
        foreach (['qa_observation_heads', 'qa_observations', 'qa_test_identities', 'evidence_review_heads', 'evidence_reviews', 'evidence_link_proposals'] as $table) {
            DB::statement("DROP TABLE $table");
        }
        DB::statement('DROP FUNCTION guard_evidence_head()');
    }
};
