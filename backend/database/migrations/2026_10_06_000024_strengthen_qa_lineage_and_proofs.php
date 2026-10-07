<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_qa_proof() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE proof jsonb; grant_row jsonb;
BEGIN
 proof=NEW.authority_proof;
 IF TG_TABLE_NAME='qa_changes' AND NEW.origin='lifecycle' THEN
  IF NOT EXISTS(SELECT 1 FROM lifecycle_changes l WHERE l.tenant_id=NEW.tenant_id AND l.project_id=NEW.project_id AND l.id=NEW.lifecycle_change_id AND l.actor_id=NEW.actor_id AND l.authorizing_purpose IN ('requirements.approve','baseline.approve','policy.approve','evidence.review','acceptance.review','lifecycle.process','requirements.manage')) THEN RAISE EXCEPTION 'Lifecycle change must bind trusted mutation' USING ERRCODE='23503'; END IF;
  RETURN NEW;
 END IF;
 IF jsonb_typeof(proof) IS DISTINCT FROM 'object' OR proof->>'version' IS DISTINCT FROM 'qa-authority-v1' OR proof->>'tenant_id' IS DISTINCT FROM NEW.tenant_id::text OR proof->>'project_id' IS DISTINCT FROM NEW.project_id::text OR proof->>'actor_id' IS DISTINCT FROM NEW.actor_id::text
  OR jsonb_typeof(proof->'membership') IS DISTINCT FROM 'object' OR jsonb_typeof(proof->'grants') IS DISTINCT FROM 'array' OR jsonb_array_length(proof->'grants') NOT BETWEEN 1 AND 9 OR jsonb_typeof(proof->'purposes') IS DISTINCT FROM 'array'
 THEN RAISE EXCEPTION 'Invalid original QA proof' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM users u JOIN project_memberships m ON m.tenant_id=u.tenant_id AND m.employee_id=u.employee_id AND m.project_id=NEW.project_id WHERE u.tenant_id=NEW.tenant_id AND u.id=NEW.actor_id AND u.status='active' AND m.id::text=proof->'membership'->>'id' AND m.revision::text=proof->'membership'->>'revision' AND m.valid_from<=clock_timestamp() AND (m.valid_to IS NULL OR m.valid_to>clock_timestamp())) THEN RAISE EXCEPTION 'Invalid original membership' USING ERRCODE='23503'; END IF;
 FOR grant_row IN SELECT value FROM jsonb_array_elements(proof->'grants') LOOP
  IF NOT EXISTS(SELECT 1 FROM access_grants g JOIN policy_versions p ON p.tenant_id=g.tenant_id AND p.id=g.policy_version_id WHERE g.tenant_id=NEW.tenant_id AND g.user_id=NEW.actor_id AND g.id::text=grant_row->>'id' AND g.revision::text=grant_row->>'revision' AND g.purpose=grant_row->>'purpose' AND g.policy_version_id::text=grant_row->>'policy_version_id' AND p.version::text=grant_row->>'policy_version' AND p.status='active' AND g.revoked_at IS NULL AND g.valid_from<=clock_timestamp() AND (g.valid_to IS NULL OR g.valid_to>clock_timestamp()) AND ((g.scope_type='project' AND g.project_id=NEW.project_id AND g.purpose IN ('qa.fixture.manage','qa.plan.manage','qa.execution.record','qa.defect.review','qa.handoff.manage','qa.read','qa.source.read','qa.process')) OR (g.scope_type='department' AND g.department_id::text=proof->>'department_id' AND g.purpose='qa.department.read'))) THEN RAISE EXCEPTION 'Invalid original grant' USING ERRCODE='23503'; END IF;
 END LOOP;
 RETURN NEW;
END $$;
CREATE FUNCTION guard_qa_receipt_binding() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE receipt qa_receipts; body jsonb; stable_key text; field text; fields text[]; row_json jsonb;
BEGIN
 SELECT * INTO receipt FROM qa_receipts WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.receipt_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'Missing source receipt' USING ERRCODE='23503'; END IF;
 body=receipt.envelope->'body'; row_json=to_jsonb(NEW);
 IF jsonb_typeof(body) IS DISTINCT FROM 'object' THEN RAISE EXCEPTION 'Typed receipt body required' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME IN ('qa_build_versions','qa_environment_versions') THEN
  fields=ARRAY['logical_key','revision','title','content_hash'];
  IF TG_TABLE_NAME='qa_build_versions' THEN fields=fields||ARRAY['mr_snapshot_id','base_sha','head_sha']; END IF;
 ELSIF TG_TABLE_NAME='qa_definition_versions' THEN
  fields=ARRAY['revision','criterion_version_id','title','steps','expected_result','test_type','release_critical'];
  SELECT logical_key INTO stable_key FROM qa_definition_identities WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.test_identity_id;
  IF stable_key IS DISTINCT FROM body->>'logical_key' THEN RAISE EXCEPTION 'Definition identity must match receipt' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_execution_versions' THEN
  fields=ARRAY['outcome'];
  IF (body->>'definition_version_id',body->>'build_id',body->>'environment_version_id') IS DISTINCT FROM (NEW.definition_id::text,NEW.build_id::text,NEW.environment_id::text) OR NEW.source_revision<>receipt.source_revision THEN RAISE EXCEPTION 'Execution must match source pins' USING ERRCODE='23514'; END IF;
  IF NOT EXISTS(SELECT 1 FROM qa_execution_series s JOIN qa_execution_attempts a ON a.tenant_id=s.tenant_id AND a.project_id=s.project_id AND a.series_id=s.id WHERE s.tenant_id=NEW.tenant_id AND s.project_id=NEW.project_id AND s.id=NEW.series_id AND s.source_instance_id=receipt.source_instance_id AND a.id=NEW.attempt_id AND a.execution_key=receipt.object_id AND a.ordinal=(body->>'attempt_ordinal')::bigint) THEN RAISE EXCEPTION 'Execution must match series and attempt' USING ERRCODE='23503'; END IF;
 ELSIF TG_TABLE_NAME='qa_defects' THEN
  fields=ARRAY['logical_key','criterion_version_id','severity','reproducibility','reproduction'];
  IF (body->>'definition_version_id',body->>'build_id',body->>'environment_version_id') IS DISTINCT FROM (NEW.definition_id::text,NEW.build_id::text,NEW.environment_id::text) THEN RAISE EXCEPTION 'Defect must match source pins' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_handoffs' THEN fields=ARRAY['task_version_id','criterion_version_id','handoff_type'];
 END IF;
 FOREACH field IN ARRAY fields LOOP
  IF nullif(row_json->field,'null'::jsonb) IS DISTINCT FROM nullif(body->field,'null'::jsonb) THEN RAISE EXCEPTION 'Typed fact must match receipt' USING ERRCODE='23514'; END IF;
 END LOOP;
 RETURN NEW;
END $$;
CREATE FUNCTION guard_qa_semantic_lineage() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent record; prior record; series record; current_criterion uuid;
BEGIN
 IF TG_TABLE_NAME='qa_plan_entry_definitions' THEN
  SELECT * INTO parent FROM qa_plan_entry_snapshots WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.entry_id;
  IF NOT parent.included OR (SELECT count(*) FROM qa_plan_entry_definitions WHERE entry_id=NEW.entry_id)>=20 THEN RAISE EXCEPTION 'Entry definition bound exceeded' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_plan_entries' THEN
  IF (SELECT count(*) FROM qa_plan_entries WHERE plan_id=NEW.plan_id)>=100 THEN RAISE EXCEPTION 'Plan criterion bound exceeded' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_reviews' THEN
  IF NEW.definition_id IS NOT NULL THEN SELECT content_hash INTO parent FROM qa_definition_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.definition_id;
  ELSE SELECT content_hash INTO parent FROM qa_plan_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.plan_id; END IF;
  IF parent.content_hash IS DISTINCT FROM NEW.subject_hash THEN RAISE EXCEPTION 'Review must bind subject hash' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_selection_events' THEN
  IF NOT EXISTS(SELECT 1 FROM qa_plan_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.plan_id AND review_state='approved') THEN RAISE EXCEPTION 'Selection needs approved plan' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_execution_attempts' THEN
  IF NEW.predecessor_id IS NOT NULL THEN
   SELECT * INTO prior FROM qa_execution_attempts WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND series_id=NEW.series_id AND id=NEW.predecessor_id;
   IF NOT FOUND OR prior.ordinal<>NEW.ordinal-1 THEN RAISE EXCEPTION 'Predecessor must be previous same-series attempt' USING ERRCODE='23503'; END IF;
  END IF;
  IF NEW.ordinal>1 AND NEW.predecessor_id IS NULL THEN RAISE EXCEPTION 'Retest requires predecessor' USING ERRCODE='23514'; END IF;
  IF NEW.fix_request_event_id IS NOT NULL THEN
   SELECT * INTO parent FROM qa_defect_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.fix_request_event_id AND event_type='fix_requested';
   SELECT * INTO series FROM qa_execution_series WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.series_id;
   IF parent.id IS NULL OR (parent.criterion_version_id,parent.definition_id,parent.build_id,parent.environment_id) IS DISTINCT FROM (series.criterion_version_id,series.definition_id,series.build_id,series.environment_id) THEN RAISE EXCEPTION 'Retest must match fix request' USING ERRCODE='23503'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='qa_defect_events' THEN
  SELECT * INTO parent FROM qa_defects WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.defect_id;
  SELECT test_identity_id INTO prior FROM qa_definition_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=parent.definition_id;
  IF NEW.criterion_id IS DISTINCT FROM parent.criterion_id OR NOT EXISTS(SELECT 1 FROM qa_definition_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.definition_id AND test_identity_id=prior.test_identity_id) THEN RAISE EXCEPTION 'Defect scope must preserve stable criterion and test' USING ERRCODE='23503'; END IF;
  IF NEW.task_version_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM task_criterion_links WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND task_version_id=NEW.task_version_id AND criterion_version_id=NEW.criterion_version_id) THEN RAISE EXCEPTION 'Defect task must match criterion' USING ERRCODE='23503'; END IF;
  IF NEW.execution_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM qa_execution_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.execution_id AND criterion_version_id=NEW.criterion_version_id AND definition_id=NEW.definition_id AND build_id=NEW.build_id AND environment_id=NEW.environment_id) THEN RAISE EXCEPTION 'Defect execution pins must match' USING ERRCODE='23503'; END IF;
  IF NEW.selection_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM qa_selection_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.selection_id AND build_id=NEW.build_id AND environment_id=NEW.environment_id) THEN RAISE EXCEPTION 'Defect selection pins must match' USING ERRCODE='23503'; END IF;
  IF NEW.fix_request_event_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM qa_defect_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.fix_request_event_id AND defect_id=NEW.defect_id AND event_type='fix_requested' AND criterion_version_id=NEW.criterion_version_id AND definition_id=NEW.definition_id) THEN RAISE EXCEPTION 'Defect fix request must match' USING ERRCODE='23503'; END IF;
 ELSIF TG_TABLE_NAME='qa_handoffs' THEN
  IF NOT EXISTS(SELECT 1 FROM task_criterion_links WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND task_version_id=NEW.task_version_id AND criterion_id=NEW.criterion_id AND criterion_version_id=NEW.criterion_version_id) THEN RAISE EXCEPTION 'Handoff must bind task criterion' USING ERRCODE='23503'; END IF;
  IF NEW.execution_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM qa_execution_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.execution_id AND criterion_id=NEW.criterion_id AND criterion_version_id=NEW.criterion_version_id) THEN RAISE EXCEPTION 'Handoff execution must bind criterion' USING ERRCODE='23503'; END IF;
  IF NEW.defect_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM qa_defects WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.defect_id AND criterion_id=NEW.criterion_id) THEN RAISE EXCEPTION 'Handoff defect must bind criterion' USING ERRCODE='23503'; END IF;
 ELSIF TG_TABLE_NAME='qa_handoff_events' THEN
  SELECT * INTO parent FROM qa_handoffs WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.handoff_id;
  IF NEW.execution_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM qa_execution_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.execution_id AND criterion_id=parent.criterion_id) THEN RAISE EXCEPTION 'Handoff event execution must bind criterion' USING ERRCODE='23503'; END IF;
  IF NEW.defect_event_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM qa_defect_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.defect_event_id AND defect_id=parent.defect_id) THEN RAISE EXCEPTION 'Handoff event defect must bind handoff defect' USING ERRCODE='23503'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE FUNCTION guard_qa_head_transitions() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE event_row record;
BEGIN
 IF TG_TABLE_NAME='qa_defects' THEN
  SELECT * INTO event_row FROM qa_defect_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.current_event_id AND defect_id=NEW.id AND revision=NEW.revision;
  IF NOT FOUND OR (NEW.disposition,NEW.canonical_defect_id) IS DISTINCT FROM (event_row.disposition,event_row.canonical_defect_id) THEN RAISE EXCEPTION 'Defect head must bind event' USING ERRCODE='23503'; END IF;
  IF event_row.event_type IN ('context_added','duplicate','scope_revised') THEN
   IF NEW.state<>OLD.state THEN RAISE EXCEPTION 'Context must preserve state' USING ERRCODE='23514'; END IF;
  ELSIF NOT ((OLD.revision=0 AND event_row.event_type='opened' AND NEW.state='open') OR (OLD.state IN ('open','reopened') AND event_row.event_type='triaged' AND NEW.state='triaged') OR (OLD.state IN ('triaged','fixed_awaiting_retest') AND event_row.event_type='fix_requested' AND NEW.state='fixed_awaiting_retest') OR (OLD.state='fixed_awaiting_retest' AND event_row.event_type='verified_closed' AND NEW.state='verified_closed') OR (OLD.state='verified_closed' AND event_row.event_type='reopened' AND NEW.state='reopened' AND event_row.cause IS NOT NULL)) THEN RAISE EXCEPTION 'Invalid defect state transition' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_handoffs' THEN
  SELECT * INTO event_row FROM qa_handoff_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.current_event_id AND handoff_id=NEW.id AND revision=NEW.revision;
  IF NOT FOUND OR NEW.owner_id IS DISTINCT FROM event_row.owner_id THEN RAISE EXCEPTION 'Handoff head must bind event' USING ERRCODE='23503'; END IF;
  IF event_row.event_type='context_added' THEN
   IF NEW.state<>OLD.state OR NEW.owner_id<>OLD.owner_id THEN RAISE EXCEPTION 'Context must preserve head' USING ERRCODE='23514'; END IF;
  ELSIF OLD.state='resolved' THEN RAISE EXCEPTION 'Resolved handoff is terminal' USING ERRCODE='23514';
  ELSIF event_row.event_type='owner_changed' THEN
   IF NEW.state<>OLD.state THEN RAISE EXCEPTION 'Owner change must preserve state' USING ERRCODE='23514'; END IF;
  ELSIF NOT ((OLD.revision=0 AND event_row.event_type='opened' AND NEW.state='open') OR (OLD.state='open' AND event_row.event_type='acknowledged' AND NEW.state='acknowledged') OR (OLD.state IN ('open','acknowledged') AND event_row.event_type='resolved' AND NEW.state='resolved')) THEN RAISE EXCEPTION 'Invalid handoff transition' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='qa_outbox' THEN
  IF NEW.status<>OLD.status AND NOT ((OLD.status IN ('pending','retry') AND NEW.status='processing') OR (OLD.status='processing' AND NEW.status IN ('retry','completed','dead_letter')) OR (OLD.status IN ('pending','retry') AND NEW.status='dead_letter')) THEN RAISE EXCEPTION 'Invalid maintenance transition' USING ERRCODE='23514'; END IF;
  IF NEW.watermark<OLD.watermark THEN RAISE EXCEPTION 'Watermark cannot regress' USING ERRCODE='23514'; END IF;
  IF NEW.status='processing' AND NEW.lease_token IS DISTINCT FROM OLD.lease_token AND (NEW.attempt_count<>OLD.attempt_count+1 OR NEW.processing_authority IS NULL OR NEW.processing_authority_hash IS NULL) THEN RAISE EXCEPTION 'Claim requires fenced proof' USING ERRCODE='23514'; END IF;
  IF (NEW.processing_authority,NEW.processing_authority_hash) IS DISTINCT FROM (OLD.processing_authority,OLD.processing_authority_hash) AND NOT (NEW.status='processing' AND NEW.lease_token IS DISTINCT FROM OLD.lease_token AND NEW.attempt_count=OLD.attempt_count+1) THEN RAISE EXCEPTION 'Proof is immutable within lease' USING ERRCODE='42501'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['qa_receipts', 'qa_plan_entry_snapshots', 'qa_plan_versions', 'qa_reviews', 'qa_selection_events', 'qa_defect_events', 'qa_handoff_events', 'qa_changes'] as $table) {
            DB::statement("CREATE TRIGGER qa_original_proof BEFORE INSERT ON $table FOR EACH ROW EXECUTE FUNCTION guard_qa_proof()");
        }
        foreach (['qa_build_versions', 'qa_environment_versions', 'qa_definition_versions', 'qa_execution_versions', 'qa_defects', 'qa_handoffs'] as $table) {
            DB::statement("CREATE TRIGGER zz_qa_receipt_binding BEFORE INSERT ON $table FOR EACH ROW EXECUTE FUNCTION guard_qa_receipt_binding()");
        }
        foreach (['qa_plan_entry_definitions', 'qa_plan_entries', 'qa_reviews', 'qa_selection_events', 'qa_execution_attempts', 'qa_defect_events', 'qa_handoffs', 'qa_handoff_events'] as $table) {
            DB::statement("CREATE TRIGGER qa_semantic_lineage BEFORE INSERT ON $table FOR EACH ROW EXECUTE FUNCTION guard_qa_semantic_lineage()");
        }
        foreach (['qa_defects', 'qa_handoffs', 'qa_outbox'] as $table) {
            DB::statement("CREATE TRIGGER qa_head_transitions BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION guard_qa_head_transitions()");
        }
    }

    public function down(): void
    {
        throw new LogicException('Immutable QA proofs and lineage must be preserved.');
    }
};
