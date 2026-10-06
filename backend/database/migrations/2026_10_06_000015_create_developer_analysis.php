<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE developer_snapshots (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL,
 source_instance_id varchar(200) NOT NULL, repository_id varchar(200) NOT NULL, mr_id varchar(200) NOT NULL,
 revision_ordinal integer NOT NULL CHECK(revision_ordinal>0), base_sha varchar(40) NOT NULL CHECK(base_sha ~ '^[0-9a-f]{40}$'), head_sha varchar(40) NOT NULL CHECK(head_sha ~ '^[0-9a-f]{40}$'),
 source_status varchar(20) NOT NULL CHECK(source_status IN ('open','merged','released')), safe_diff text NOT NULL CHECK(octet_length(safe_diff)<=524288),
 content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'), paths jsonb NOT NULL CHECK(jsonb_typeof(paths)='array' AND jsonb_array_length(paths)<=100),
 redaction jsonb NOT NULL CHECK(jsonb_typeof(redaction)='object'), actor_id uuid NOT NULL,
 occurred_at timestamptz(6) NOT NULL, received_at timestamptz(6) NOT NULL, source_updated_at timestamptz(6) NOT NULL, registered_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 CHECK(base_sha<>head_sha), UNIQUE(tenant_id,project_id,id),
 UNIQUE(tenant_id,project_id,source_instance_id,repository_id,mr_id,revision_ordinal),
 UNIQUE(tenant_id,project_id,source_instance_id,repository_id,mr_id,revision_ordinal,id),
 FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
CREATE TABLE developer_source_heads (
 tenant_id uuid NOT NULL, project_id uuid NOT NULL, source_instance_id varchar(200) NOT NULL, repository_id varchar(200) NOT NULL, mr_id varchar(200) NOT NULL,
 revision integer NOT NULL CHECK(revision>0), revision_ordinal integer NOT NULL, snapshot_id uuid NOT NULL,
 PRIMARY KEY(tenant_id,project_id,source_instance_id,repository_id,mr_id),
 FOREIGN KEY(tenant_id,project_id,source_instance_id,repository_id,mr_id,revision_ordinal,snapshot_id) REFERENCES developer_snapshots(tenant_id,project_id,source_instance_id,repository_id,mr_id,revision_ordinal,id)
);
CREATE TABLE developer_source_events (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, snapshot_id uuid,
 source_instance_id varchar(200) NOT NULL, repository_id varchar(200) NOT NULL, mr_id varchar(200) NOT NULL, revision_ordinal integer NOT NULL CHECK(revision_ordinal>0),
 payload_hash varchar(64) NOT NULL CHECK(payload_hash ~ '^[0-9a-f]{64}$'), reason_code varchar(40) NOT NULL CHECK(reason_code IN ('approved','secret_blocked','invalid_diff')),
 actor_id uuid NOT NULL, occurred_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 CHECK((reason_code='approved')=(snapshot_id IS NOT NULL)), UNIQUE(tenant_id,project_id,id),
 UNIQUE(tenant_id,project_id,source_instance_id,repository_id,mr_id,revision_ordinal,payload_hash),
 FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id),
 FOREIGN KEY(tenant_id,project_id,source_instance_id,repository_id,mr_id,revision_ordinal,snapshot_id) REFERENCES developer_snapshots(tenant_id,project_id,source_instance_id,repository_id,mr_id,revision_ordinal,id)
);
CREATE TABLE developer_runs (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, request_id uuid NOT NULL, snapshot_id uuid NOT NULL, requester_id uuid NOT NULL,
 operation varchar(40) NOT NULL DEFAULT 'analysis.request' CHECK(operation='analysis.request'), idempotency_key varchar(128) NOT NULL CHECK(idempotency_key ~ '^[!-~]{1,128}$'),
 payload_hash varchar(64) NOT NULL CHECK(payload_hash ~ '^[0-9a-f]{64}$'), input_hash varchar(64) NOT NULL CHECK(input_hash ~ '^[0-9a-f]{64}$'),
 input jsonb NOT NULL CHECK(jsonb_typeof(input)='object' AND octet_length(input::text)<=1048576), pins jsonb NOT NULL CHECK(jsonb_typeof(pins)='object'),
 authority jsonb NOT NULL CHECK(jsonb_typeof(authority)='object'), authority_hash varchar(64) NOT NULL CHECK(authority_hash ~ '^[0-9a-f]{64}$'),
 analyzer_version varchar(80) NOT NULL, rule_version varchar(80) NOT NULL, redaction_version varchar(80) NOT NULL, contract_version varchar(80) NOT NULL DEFAULT 'developer-analysis-input-v1',
 captured_at timestamptz(6) NOT NULL, created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 status varchar(20) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','succeeded','partial','error','cancelled')),
 applicability varchar(20) NOT NULL DEFAULT 'current' CHECK(applicability IN ('current','stale','unknown')),
 attempt_count integer NOT NULL DEFAULT 0 CHECK(attempt_count BETWEEN 0 AND 5), lease_token uuid, lease_until timestamptz(6), completed_at timestamptz(6), reason_code varchar(40),
 CHECK((status='running')=(lease_token IS NOT NULL AND lease_until IS NOT NULL)),
 CHECK((status IN ('succeeded','partial','error','cancelled'))=(completed_at IS NOT NULL)),
 CHECK(reason_code IS NULL OR reason_code IN ('source_changed','pins_changed','access_revoked','invalid_output','parser_failed','runner_unavailable','attempts_exhausted','project_closed')),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,snapshot_id,id), UNIQUE(tenant_id,requester_id,operation,idempotency_key), UNIQUE(request_id),
 FOREIGN KEY(tenant_id,project_id,snapshot_id) REFERENCES developer_snapshots(tenant_id,project_id,id), FOREIGN KEY(tenant_id,requester_id) REFERENCES users(tenant_id,id)
);
CREATE TABLE developer_results (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, snapshot_id uuid NOT NULL, run_id uuid NOT NULL,
 input_hash varchar(64) NOT NULL CHECK(input_hash ~ '^[0-9a-f]{64}$'), output_hash varchar(64) NOT NULL CHECK(output_hash ~ '^[0-9a-f]{64}$'), output jsonb NOT NULL CHECK(jsonb_typeof(output)='object' AND octet_length(output::text)<=262144),
 processing_authority jsonb NOT NULL CHECK(jsonb_typeof(processing_authority)='object'), processing_authority_hash varchar(64) NOT NULL CHECK(processing_authority_hash ~ '^[0-9a-f]{64}$'),
 published_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), UNIQUE(tenant_id,project_id,id), UNIQUE(run_id), UNIQUE(tenant_id,project_id,snapshot_id,run_id,id),
 FOREIGN KEY(tenant_id,project_id,snapshot_id,run_id) REFERENCES developer_runs(tenant_id,project_id,snapshot_id,id)
);
CREATE TABLE developer_findings (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, snapshot_id uuid NOT NULL, run_id uuid NOT NULL, result_id uuid NOT NULL,
 kind varchar(40) NOT NULL CHECK(kind IN ('composition_fact','coverage_notice','provenance_notice')), facts jsonb NOT NULL CHECK(jsonb_typeof(facts)='object'),
 summary varchar(1000) NOT NULL, created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 review_revision integer NOT NULL DEFAULT 0 CHECK(review_revision>=0), disposition varchar(20) NOT NULL DEFAULT 'pending' CHECK(disposition IN ('pending','verified','rejected')), current_review_id uuid,
 CHECK((review_revision=0)=(current_review_id IS NULL)), UNIQUE(tenant_id,project_id,id), UNIQUE(run_id,kind),
 FOREIGN KEY(tenant_id,project_id,snapshot_id,run_id,result_id) REFERENCES developer_results(tenant_id,project_id,snapshot_id,run_id,id)
);
CREATE TABLE developer_reviews (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, finding_id uuid NOT NULL, revision integer NOT NULL CHECK(revision>0),
 decision varchar(20) NOT NULL CHECK(decision IN ('verified','rejected','context-added')), disposition varchar(20) NOT NULL CHECK(disposition IN ('pending','verified','rejected')),
 actor_id uuid NOT NULL, reason varchar(2000) NOT NULL CHECK(length(btrim(reason))>0), effective_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 CHECK(decision='context-added' OR decision=disposition), UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,finding_id,revision), UNIQUE(tenant_id,project_id,finding_id,revision,id),
 FOREIGN KEY(tenant_id,project_id,finding_id) REFERENCES developer_findings(tenant_id,project_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
ALTER TABLE developer_findings ADD CONSTRAINT developer_finding_review_fk FOREIGN KEY(tenant_id,project_id,id,review_revision,current_review_id) REFERENCES developer_reviews(tenant_id,project_id,finding_id,revision,id) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE developer_invalidations (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, run_id uuid NOT NULL, reason_code varchar(40) NOT NULL CHECK(reason_code IN ('source_changed','pins_changed','access_revoked','project_closed')),
 observed_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), actor_id uuid NOT NULL,
 UNIQUE(tenant_id,project_id,id), UNIQUE(run_id), FOREIGN KEY(tenant_id,project_id,run_id) REFERENCES developer_runs(tenant_id,project_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
CREATE TABLE developer_outbox (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, source_event_id uuid, run_id uuid, invalidation_id uuid,
 status varchar(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','retry','completed','dead_letter','blocked')),
 attempt_count integer NOT NULL DEFAULT 0 CHECK(attempt_count BETWEEN 0 AND 5), available_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 lease_token uuid, lease_until timestamptz(6), completed_at timestamptz(6), reason_code varchar(40),
 CHECK(num_nonnulls(source_event_id,run_id,invalidation_id)=1), CHECK((status='processing')=(lease_token IS NOT NULL AND lease_until IS NOT NULL)), CHECK((status='completed')=(completed_at IS NOT NULL)),
 CHECK(reason_code IS NULL OR reason_code IN ('source_changed','pins_changed','access_revoked','invalid_output','parser_failed','runner_unavailable','attempts_exhausted','project_closed','secret_blocked','invalid_diff')),
 UNIQUE(tenant_id,project_id,id), UNIQUE(source_event_id), UNIQUE(run_id), UNIQUE(invalidation_id),
 FOREIGN KEY(tenant_id,project_id,source_event_id) REFERENCES developer_source_events(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,run_id) REFERENCES developer_runs(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id,invalidation_id) REFERENCES developer_invalidations(tenant_id,project_id,id)
);
CREATE FUNCTION guard_developer_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r developer_reviews;
BEGIN
 IF TG_TABLE_NAME='developer_source_heads' THEN
  IF (to_jsonb(NEW)-'revision'-'revision_ordinal'-'snapshot_id') IS DISTINCT FROM (to_jsonb(OLD)-'revision'-'revision_ordinal'-'snapshot_id') OR NEW.revision<>OLD.revision+1 OR NEW.revision_ordinal<=OLD.revision_ordinal THEN RAISE EXCEPTION 'Source head must advance' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='developer_runs' THEN
  IF (to_jsonb(NEW)-'status'-'applicability'-'attempt_count'-'lease_token'-'lease_until'-'completed_at'-'reason_code') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'applicability'-'attempt_count'-'lease_token'-'lease_until'-'completed_at'-'reason_code') THEN RAISE EXCEPTION 'Run input is immutable' USING ERRCODE='42501'; END IF;
  IF OLD.applicability='stale' AND NEW.applicability<>'stale' THEN RAISE EXCEPTION 'Observed invalidation is terminal' USING ERRCODE='23514'; END IF;
  IF OLD.status IN ('succeeded','partial','error','cancelled') AND (NEW.status,NEW.attempt_count,NEW.lease_token,NEW.lease_until,NEW.completed_at) IS DISTINCT FROM (OLD.status,OLD.attempt_count,OLD.lease_token,OLD.lease_until,OLD.completed_at) THEN RAISE EXCEPTION 'Terminal run cannot execute' USING ERRCODE='23514'; END IF;
  IF NEW.status<>OLD.status AND NOT ((OLD.status='queued' AND NEW.status IN ('running','error','cancelled')) OR (OLD.status='running' AND NEW.status IN ('queued','succeeded','partial','error','cancelled'))) THEN RAISE EXCEPTION 'Invalid run transition' USING ERRCODE='23514'; END IF;
  IF NEW.attempt_count<OLD.attempt_count OR NEW.attempt_count>OLD.attempt_count+1 OR (NEW.attempt_count<>OLD.attempt_count AND NEW.status<>'running') THEN RAISE EXCEPTION 'Invalid run attempt' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='developer_findings' THEN
  IF (to_jsonb(NEW)-'review_revision'-'disposition'-'current_review_id') IS DISTINCT FROM (to_jsonb(OLD)-'review_revision'-'disposition'-'current_review_id') OR NEW.review_revision<>OLD.review_revision+1 THEN RAISE EXCEPTION 'Finding facts are immutable' USING ERRCODE='23514'; END IF;
  SELECT * INTO r FROM developer_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND finding_id=NEW.id AND revision=NEW.review_revision AND id=NEW.current_review_id;
  IF NOT FOUND OR r.disposition<>NEW.disposition OR (r.decision='context-added' AND NEW.disposition<>OLD.disposition) THEN RAISE EXCEPTION 'Review disposition must match history' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='developer_outbox' THEN
  IF (NEW.id,NEW.tenant_id,NEW.project_id,NEW.source_event_id,NEW.run_id,NEW.invalidation_id) IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.project_id,OLD.source_event_id,OLD.run_id,OLD.invalidation_id) THEN RAISE EXCEPTION 'Outbox binding is immutable' USING ERRCODE='42501'; END IF;
  IF OLD.status IN ('completed','dead_letter','blocked') AND to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD) THEN RAISE EXCEPTION 'Terminal outbox is immutable' USING ERRCODE='23514'; END IF;
  IF NEW.attempt_count<OLD.attempt_count OR NEW.attempt_count>OLD.attempt_count+1 OR (NEW.attempt_count<>OLD.attempt_count AND NEW.status<>'processing') THEN RAISE EXCEPTION 'Invalid outbox attempt' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE FUNCTION guard_developer_run_pins() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE pin text;
BEGIN
 IF jsonb_typeof(NEW.pins->'criterion_version_ids') IS DISTINCT FROM 'array' OR jsonb_typeof(NEW.pins->'task_version_ids') IS DISTINCT FROM 'array' THEN RAISE EXCEPTION 'Invalid analysis pins' USING ERRCODE='23514'; END IF;
 FOR pin IN SELECT jsonb_array_elements_text(NEW.pins->'criterion_version_ids') LOOP
  IF NOT EXISTS(SELECT 1 FROM criterion_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=pin::uuid) THEN RAISE EXCEPTION 'Cross-scope criterion pin' USING ERRCODE='23503'; END IF;
 END LOOP;
 FOR pin IN SELECT jsonb_array_elements_text(NEW.pins->'task_version_ids') LOOP
  IF NOT EXISTS(SELECT 1 FROM task_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=pin::uuid) THEN RAISE EXCEPTION 'Cross-scope task pin' USING ERRCODE='23503'; END IF;
 END LOOP;
 RETURN NEW;
END $$;
CREATE TRIGGER developer_run_pins BEFORE INSERT ON developer_runs FOR EACH ROW EXECUTE FUNCTION guard_developer_run_pins();
SQL);
        foreach (['developer_snapshots', 'developer_source_heads', 'developer_source_events', 'developer_runs', 'developer_results', 'developer_findings', 'developer_reviews', 'developer_invalidations', 'developer_outbox'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
            DB::statement("REVOKE DELETE,TRUNCATE ON $table FROM monitoring_ai_app");
            if (in_array($table, ['developer_source_heads', 'developer_runs', 'developer_findings', 'developer_outbox'], true)) {
                DB::statement("CREATE TRIGGER developer_mutation_guard BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION guard_developer_mutation()");
            } else {
                DB::statement("REVOKE UPDATE ON $table FROM monitoring_ai_app");
                DB::statement("CREATE TRIGGER developer_history_immutable BEFORE UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Immutable developer evidence history must be preserved.');
    }
};
