<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE qa_receipts (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, source_instance_id uuid NOT NULL,
 object_id varchar(100) NOT NULL, source_revision bigint NOT NULL CHECK(source_revision>0), kind varchar(30) NOT NULL CHECK(kind IN ('build','environment','definition','execution','defect','handoff')),
 payload_hash varchar(64) NOT NULL CHECK(payload_hash ~ '^[0-9a-f]{64}$'), envelope jsonb NOT NULL CHECK(jsonb_typeof(envelope)='object' AND octet_length(envelope::text)<=131072),
 actor_id uuid NOT NULL, authority_proof jsonb NOT NULL CHECK(authority_proof->>'version'='qa-authority-v1'), occurred_at timestamptz(6) NOT NULL, source_updated_at timestamptz(6) NOT NULL,
 received_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,id,kind),
 UNIQUE(tenant_id,project_id,source_instance_id,object_id,source_revision), UNIQUE(tenant_id,project_id,source_instance_id,object_id,source_revision,id),
 FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id), FOREIGN KEY(tenant_id,source_instance_id) REFERENCES source_instances(tenant_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
CREATE TABLE qa_request_keys (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, requester_id uuid NOT NULL, operation varchar(40) NOT NULL,
 key varchar(100) NOT NULL CHECK(key ~ '^[A-Za-z0-9._:-]{1,100}$'), payload_hash varchar(64) NOT NULL CHECK(payload_hash ~ '^[0-9a-f]{64}$'),
 receipt_id uuid, entry_id uuid, plan_id uuid, defect_id uuid, handoff_id uuid, created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 CHECK(num_nonnulls(receipt_id,entry_id,plan_id,defect_id,handoff_id)=1), UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,requester_id,operation,key),
 FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id), FOREIGN KEY(tenant_id,requester_id) REFERENCES users(tenant_id,id), FOREIGN KEY(tenant_id,project_id,receipt_id) REFERENCES qa_receipts(tenant_id,project_id,id)
);
CREATE TABLE qa_source_heads (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, source_instance_id uuid NOT NULL, object_id varchar(100) NOT NULL,
 source_revision bigint NOT NULL CHECK(source_revision>0), revision bigint NOT NULL CHECK(revision>0), receipt_id uuid NOT NULL,
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,source_instance_id,object_id),
 FOREIGN KEY(tenant_id,project_id,source_instance_id,object_id,source_revision,receipt_id) REFERENCES qa_receipts(tenant_id,project_id,source_instance_id,object_id,source_revision,id)
);
CREATE TABLE qa_build_versions (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, receipt_id uuid NOT NULL, receipt_kind varchar(30) NOT NULL DEFAULT 'build' CHECK(receipt_kind='build'),
 logical_key varchar(100) NOT NULL, revision bigint NOT NULL CHECK(revision>0), content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'),
 title varchar(500) NOT NULL CHECK(octet_length(title)<=500), mr_snapshot_id uuid, base_sha varchar(40), head_sha varchar(40),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,logical_key,revision), UNIQUE(receipt_id),
 FOREIGN KEY(tenant_id,project_id,receipt_id,receipt_kind) REFERENCES qa_receipts(tenant_id,project_id,id,kind), FOREIGN KEY(tenant_id,project_id,mr_snapshot_id) REFERENCES developer_snapshots(tenant_id,project_id,id),
 CHECK((base_sha IS NULL)=(head_sha IS NULL)), CHECK(base_sha IS NULL OR (base_sha ~ '^[0-9a-f]{40}$' AND head_sha ~ '^[0-9a-f]{40}$' AND base_sha<>head_sha))
);
CREATE TABLE qa_environment_versions (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, receipt_id uuid NOT NULL, receipt_kind varchar(30) NOT NULL DEFAULT 'environment' CHECK(receipt_kind='environment'),
 logical_key varchar(100) NOT NULL, revision bigint NOT NULL CHECK(revision>0), content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'), title varchar(500) NOT NULL CHECK(octet_length(title)<=500),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,logical_key,revision), UNIQUE(receipt_id), FOREIGN KEY(tenant_id,project_id,receipt_id,receipt_kind) REFERENCES qa_receipts(tenant_id,project_id,id,kind)
);
CREATE TABLE qa_definition_identities (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, criterion_id uuid NOT NULL, logical_key varchar(100) NOT NULL,
 revision bigint NOT NULL DEFAULT 0 CHECK(revision>=0), current_definition_id uuid,
 CHECK((revision=0)=(current_definition_id IS NULL)), UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,criterion_id,id), UNIQUE(tenant_id,project_id,logical_key),
 FOREIGN KEY(tenant_id,project_id,criterion_id) REFERENCES criteria(tenant_id,project_id,id)
);
CREATE TABLE qa_definition_versions (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, test_identity_id uuid NOT NULL, criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL,
 revision bigint NOT NULL CHECK(revision>0), receipt_id uuid NOT NULL, receipt_kind varchar(30) NOT NULL DEFAULT 'definition' CHECK(receipt_kind='definition'),
 content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'), title varchar(500) NOT NULL CHECK(octet_length(title)<=500), steps varchar(4000) NOT NULL CHECK(octet_length(steps)<=4000), expected_result varchar(4000) NOT NULL CHECK(octet_length(expected_result)<=4000),
 test_type varchar(20) NOT NULL CHECK(test_type IN ('functional','regression','security','integration','unit')), release_critical boolean NOT NULL,
 review_revision integer NOT NULL DEFAULT 0 CHECK(review_revision>=0), current_review_id uuid, review_state varchar(20) NOT NULL DEFAULT 'designed' CHECK(review_state IN ('designed','reviewed','rejected','revoked')),
 CHECK((review_revision=0)=(current_review_id IS NULL)), UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,test_identity_id,revision,id), UNIQUE(tenant_id,project_id,test_identity_id,revision), UNIQUE(tenant_id,project_id,criterion_id,criterion_version_id,id), UNIQUE(receipt_id),
 FOREIGN KEY(tenant_id,project_id,criterion_id,test_identity_id) REFERENCES qa_definition_identities(tenant_id,project_id,criterion_id,id),
 FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id) REFERENCES criterion_versions(tenant_id,project_id,criterion_id,id), FOREIGN KEY(tenant_id,project_id,receipt_id,receipt_kind) REFERENCES qa_receipts(tenant_id,project_id,id,kind)
);
ALTER TABLE qa_definition_identities ADD CONSTRAINT qa_definition_head_fk FOREIGN KEY(tenant_id,project_id,id,revision,current_definition_id) REFERENCES qa_definition_versions(tenant_id,project_id,test_identity_id,revision,id) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE qa_project_state (
 tenant_id uuid NOT NULL, project_id uuid NOT NULL, selection_revision bigint NOT NULL DEFAULT 0 CHECK(selection_revision>=0), current_selection_id uuid,
 maintenance_watermark bigint NOT NULL DEFAULT 0 CHECK(maintenance_watermark>=0), CHECK((selection_revision=0)=(current_selection_id IS NULL)), PRIMARY KEY(tenant_id,project_id), FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id)
);
CREATE FUNCTION guard_qa_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE mutable text[]; review record; event_row record;
BEGIN
 IF TG_TABLE_NAME='qa_source_heads' THEN
  mutable=ARRAY['revision','source_revision','receipt_id'];
  IF NEW.revision<>OLD.revision+1 OR NEW.source_revision<=OLD.source_revision THEN RAISE EXCEPTION 'Source must advance' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_definition_identities' THEN
  mutable=ARRAY['revision','current_definition_id'];
  IF NEW.revision<>OLD.revision+1 THEN RAISE EXCEPTION 'Definition must advance' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME IN ('qa_definition_versions','qa_plan_versions') THEN
  mutable=ARRAY['review_revision','current_review_id','review_state'];
  IF NEW.review_revision<>OLD.review_revision+1 THEN RAISE EXCEPTION 'Review must advance' USING ERRCODE='23514'; END IF;
  SELECT * INTO review FROM qa_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.current_review_id AND revision=NEW.review_revision;
  IF NOT FOUND OR review.decision<>NEW.review_state OR (TG_TABLE_NAME='qa_definition_versions' AND review.definition_id IS DISTINCT FROM NEW.id) OR (TG_TABLE_NAME='qa_plan_versions' AND review.plan_id IS DISTINCT FROM NEW.id) THEN RAISE EXCEPTION 'Review must match subject' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_project_state' THEN
  mutable=ARRAY['selection_revision','current_selection_id','maintenance_watermark'];
  IF NEW.maintenance_watermark<OLD.maintenance_watermark OR ((NEW.selection_revision,NEW.current_selection_id) IS DISTINCT FROM (OLD.selection_revision,OLD.current_selection_id) AND NEW.selection_revision<>OLD.selection_revision+1) THEN RAISE EXCEPTION 'QA state must advance' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_execution_series' THEN
  mutable=ARRAY['revision','current_attempt_id','current_ordinal'];
  IF NEW.revision<>OLD.revision+1 OR NEW.current_ordinal<>OLD.current_ordinal+1 THEN RAISE EXCEPTION 'Attempt must advance' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_execution_attempts' THEN
  mutable=ARRAY['revision','current_version_id','source_revision'];
  IF NEW.revision<>OLD.revision+1 OR NEW.source_revision<=OLD.source_revision THEN RAISE EXCEPTION 'Correction must advance' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME IN ('qa_defects','qa_handoffs') THEN
  mutable=ARRAY['revision','current_event_id','state','disposition','canonical_defect_id','owner_id'];
  IF NEW.revision<>OLD.revision+1 THEN RAISE EXCEPTION 'Event must advance' USING ERRCODE='23514'; END IF;
  EXECUTE format('SELECT * FROM %I WHERE tenant_id=$1 AND project_id=$2 AND id=$3 AND revision=$4', CASE WHEN TG_TABLE_NAME='qa_defects' THEN 'qa_defect_events' ELSE 'qa_handoff_events' END) INTO event_row USING NEW.tenant_id,NEW.project_id,NEW.current_event_id,NEW.revision;
  IF event_row.state IS DISTINCT FROM NEW.state THEN RAISE EXCEPTION 'Head state must match event' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_applicability' THEN
  mutable=ARRAY['status','reason_code','change_seq','observed_at'];
  IF NEW.change_seq<OLD.change_seq OR (OLD.status<>'current' AND NEW.status='current') THEN RAISE EXCEPTION 'Applicability cannot be repaired' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_outbox' THEN
  mutable=ARRAY['status','attempt_count','available_at','lease_token','lease_until','completed_at','reason_code','watermark','processing_authority','processing_authority_hash'];
  IF OLD.status IN ('completed','dead_letter') AND to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD) THEN RAISE EXCEPTION 'Terminal job is immutable' USING ERRCODE='23514'; END IF;
  IF NEW.attempt_count<OLD.attempt_count OR NEW.attempt_count>OLD.attempt_count+1 OR (NEW.attempt_count<>OLD.attempt_count AND NEW.status<>'processing') THEN RAISE EXCEPTION 'Invalid claim' USING ERRCODE='23514'; END IF;
 ELSE RAISE EXCEPTION 'Unsupported QA head' USING ERRCODE='42501';
 END IF;
 IF (to_jsonb(NEW)-mutable) IS DISTINCT FROM (to_jsonb(OLD)-mutable) THEN RAISE EXCEPTION 'QA facts are immutable' USING ERRCODE='42501'; END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['qa_receipts', 'qa_request_keys', 'qa_source_heads', 'qa_build_versions', 'qa_environment_versions', 'qa_definition_identities', 'qa_definition_versions', 'qa_project_state'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
            DB::statement("REVOKE DELETE,TRUNCATE ON $table FROM monitoring_ai_app");
            DB::statement("CREATE TRIGGER qa_no_delete BEFORE DELETE ON $table FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            DB::statement("CREATE TRIGGER qa_no_truncate BEFORE TRUNCATE ON $table FOR EACH STATEMENT EXECUTE FUNCTION reject_audit_mutation()");
            if (in_array($table, ['qa_source_heads', 'qa_definition_identities', 'qa_definition_versions', 'qa_project_state'], true)) {
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
