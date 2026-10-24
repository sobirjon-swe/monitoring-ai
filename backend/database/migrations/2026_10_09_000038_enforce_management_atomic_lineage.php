<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // New facts allocate cursor generations; existing immutable rows retain NULL.
        DB::unprepared(<<<'SQL'
ALTER TABLE management_events ADD COLUMN project_generation bigint CHECK(project_generation BETWEEN 1 AND 9007199254740991);
ALTER TABLE management_terminal_applicability ADD COLUMN project_generation bigint CHECK(project_generation BETWEEN 1 AND 9007199254740991);
ALTER TABLE management_project_state ADD COLUMN current_event_id uuid, ADD COLUMN current_terminal_id uuid;
ALTER TABLE management_events ADD UNIQUE(tenant_id,project_id,project_generation), ADD UNIQUE(tenant_id,project_id,project_generation,id);
ALTER TABLE management_terminal_applicability ADD UNIQUE(tenant_id,project_id,project_generation), ADD UNIQUE(tenant_id,project_id,project_generation,id);
ALTER TABLE management_project_state ADD CHECK(num_nonnulls(current_event_id,current_terminal_id)<=1);
ALTER TABLE management_project_state ADD FOREIGN KEY(tenant_id,project_id,generation,current_event_id) REFERENCES management_events(tenant_id,project_id,project_generation,id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE management_project_state ADD FOREIGN KEY(tenant_id,project_id,generation,current_terminal_id) REFERENCES management_terminal_applicability(tenant_id,project_id,project_generation,id) DEFERRABLE INITIALLY DEFERRED;
CREATE OR REPLACE FUNCTION guard_management_projection() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE mutable text[]; r record; p record; expected_complete boolean;
BEGIN
 IF nullif(current_setting('app.management_authority_closed',true),'') IS DISTINCT FROM '1' THEN RAISE EXCEPTION 'Management coordination required' USING ERRCODE='42501'; END IF;
 IF TG_TABLE_NAME='management_project_state' THEN
  mutable=ARRAY['generation','current_event_id','current_terminal_id'];
  IF NEW.generation<>OLD.generation+1 THEN RAISE EXCEPTION 'Management generation advance' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='management_actions' THEN
  mutable=ARRAY['revision','episode','state','support_status','review_status','current_outcome','current_event_id','current_action_version_id','current_assignment_id','current_support_id','current_review_id','episode_opened_at'];
  IF NEW.revision<>OLD.revision+1 OR NEW.episode<OLD.episode OR NEW.episode>OLD.episode+1 THEN RAISE EXCEPTION 'Management revision advance' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='management_source_heads' THEN
  mutable=ARRAY['revision','source_revision','receipt_id','complete'];
  IF NEW.revision<>OLD.revision+1 OR NEW.source_revision<=OLD.source_revision THEN RAISE EXCEPTION 'Management source advance' USING ERRCODE='23514'; END IF;
 ELSE RAISE EXCEPTION 'Unsupported management head' USING ERRCODE='42501'; END IF;
 IF (to_jsonb(NEW)-mutable) IS DISTINCT FROM (to_jsonb(OLD)-mutable) THEN RAISE EXCEPTION 'Management head identity immutable' USING ERRCODE='42501'; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION guard_management_commit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE j jsonb=to_jsonb(NEW); r record; e record; a record; s record; v record; rs record; h record; previous record; receipt_row record; prior_source record; is_late boolean=false; body jsonb; expected_id uuid; expected_complete boolean; cnt integer; fields text[]; k text;
BEGIN
 IF TG_TABLE_NAME='management_receipts' THEN
  SELECT * INTO e FROM management_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.event_id;
  IF NOT FOUND OR (e.receipt_id,e.action_id,e.operation) IS DISTINCT FROM (NEW.id,NEW.action_id,NEW.operation) THEN RAISE EXCEPTION 'Management receipt output event' USING ERRCODE='23503'; END IF;
  IF NEW.operation IN ('action.open','action.revise','action.reopen') THEN
   IF NEW.action_version_id IS NULL OR num_nonnulls(NEW.assignment_id,NEW.support_id,NEW.review_id)<>0 OR NOT EXISTS(SELECT 1 FROM management_action_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.action_version_id AND receipt_id=NEW.id AND id=e.action_version_id) THEN RAISE EXCEPTION 'Management action output' USING ERRCODE='23503'; END IF;
  ELSIF NEW.operation='assignment.set' THEN
   IF NEW.assignment_id IS NULL OR num_nonnulls(NEW.action_version_id,NEW.support_id,NEW.review_id)<>0 OR NOT EXISTS(SELECT 1 FROM management_assignments WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.assignment_id AND receipt_id=NEW.id AND id=e.assignment_id) THEN RAISE EXCEPTION 'Management assignment output' USING ERRCODE='23503'; END IF;
  ELSIF NEW.operation LIKE 'support.%' THEN
   IF NEW.support_id IS NULL OR num_nonnulls(NEW.action_version_id,NEW.assignment_id,NEW.review_id)<>0 OR NOT EXISTS(SELECT 1 FROM management_support_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.support_id AND receipt_id=NEW.id AND id=e.subject_support_id) THEN RAISE EXCEPTION 'Management support output' USING ERRCODE='23503'; END IF;
  ELSIF NEW.operation LIKE 'outcome.%' THEN
   IF NEW.review_id IS NULL OR num_nonnulls(NEW.action_version_id,NEW.assignment_id,NEW.support_id)<>0 OR NOT EXISTS(SELECT 1 FROM management_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.review_id AND receipt_id=NEW.id AND id=e.review_id AND operation=e.operation) THEN RAISE EXCEPTION 'Management review output' USING ERRCODE='23503'; END IF;
  ELSIF num_nonnulls(NEW.action_version_id,NEW.assignment_id,NEW.support_id,NEW.review_id)<>0 THEN RAISE EXCEPTION 'Management context output' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME IN ('management_actions','management_events') THEN
  IF TG_TABLE_NAME='management_actions' THEN
   SELECT * INTO h FROM management_actions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.id;
   SELECT * INTO e FROM management_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND action_id=NEW.id AND id=h.current_event_id;
   IF NOT FOUND OR (h.revision,h.episode,h.state,h.support_status,h.review_status,h.current_outcome,h.current_action_version_id,h.current_assignment_id,h.current_support_id,h.current_review_id) IS DISTINCT FROM (e.revision,e.episode,e.state,e.support_status,e.review_status,e.current_outcome,e.action_version_id,e.assignment_id,e.support_id,e.review_id) THEN RAISE EXCEPTION 'Management root projection mismatch' USING ERRCODE='23514'; END IF;
  ELSE e=NEW; END IF;
  SELECT * INTO previous FROM management_events WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND action_id=e.action_id AND revision=e.revision-1;
  SELECT * INTO receipt_row FROM management_receipts WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND id=e.receipt_id;
  IF e.operation LIKE 'support.%' THEN
   SELECT x.* INTO prior_source FROM management_receipts x JOIN management_events y ON y.tenant_id=x.tenant_id AND y.project_id=x.project_id AND y.id=x.event_id AND y.receipt_id=x.id WHERE x.tenant_id=e.tenant_id AND x.project_id=e.project_id AND x.action_id=e.action_id AND x.variant='source' AND x.family='support' AND x.source_instance_id=receipt_row.source_instance_id AND x.object_id=receipt_row.object_id AND y.revision<e.revision ORDER BY x.source_revision DESC LIMIT 1;
   is_late=prior_source.id IS NOT NULL AND receipt_row.source_revision<prior_source.source_revision;
   IF e.subject_support_id IS DISTINCT FROM receipt_row.support_id THEN RAISE EXCEPTION 'Management exact source subject' USING ERRCODE='23503'; END IF;
  END IF;
  IF e.operation='context.add' OR is_late THEN
   IF previous.id IS NULL OR (e.episode,e.state,e.support_status,e.review_status,e.current_outcome,e.action_version_id,e.assignment_id,e.support_id,e.review_id) IS DISTINCT FROM (previous.episode,previous.state,previous.support_status,previous.review_status,previous.current_outcome,previous.action_version_id,previous.assignment_id,previous.support_id,previous.review_id) THEN RAISE EXCEPTION 'Management retained projection must match predecessor' USING ERRCODE='23514'; END IF;
  END IF;
  SELECT * INTO a FROM management_action_versions WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND id=e.action_version_id;
  IF e.episode IS DISTINCT FROM a.episode THEN RAISE EXCEPTION 'Management episode mismatch' USING ERRCODE='23514'; END IF;
  IF e.operation IN ('action.open','action.revise','action.reopen') AND (e.state<>'open' OR num_nonnulls(e.assignment_id,e.support_id,e.review_id,e.subject_support_id)<>0 OR e.support_status<>'none' OR e.review_status<>'unverified' OR e.current_outcome IS NOT NULL) THEN RAISE EXCEPTION 'Management open projection' USING ERRCODE='23514'; END IF;
  IF e.operation='assignment.set' AND (e.state<>'assigned' OR e.assignment_id IS NULL OR num_nonnulls(e.support_id,e.review_id)<>0 OR e.current_outcome IS NOT NULL OR e.support_status<>'none' OR e.review_status<>'unverified') THEN RAISE EXCEPTION 'Management assignment projection' USING ERRCODE='23514'; END IF;
  IF NOT is_late AND e.operation IN ('support.retract','support.decline','outcome.reject') AND (e.state<>'assigned' OR e.support_id IS NOT NULL OR e.current_outcome IS NOT NULL) THEN RAISE EXCEPTION 'Management cleared support projection' USING ERRCODE='23514'; END IF;
  IF NOT is_late AND e.operation IN ('support.record','support.correct') AND (e.state<>'awaiting_review' OR e.current_outcome IS NOT NULL OR e.review_id IS NOT NULL OR e.support_id IS DISTINCT FROM e.subject_support_id OR e.support_status<>'recorded' OR e.review_status<>'unverified') THEN RAISE EXCEPTION 'Management unverified support projection' USING ERRCODE='23514'; END IF;
  IF e.operation='outcome.reject' AND (e.state<>'assigned' OR e.support_status<>'none' OR e.review_status<>'rejected' OR e.subject_support_id IS NULL OR e.support_id IS NOT NULL OR e.current_outcome IS NOT NULL OR NOT EXISTS(SELECT 1 FROM management_reviews WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND id=e.review_id AND support_id=e.subject_support_id AND operation='outcome.reject')) THEN RAISE EXCEPTION 'Management rejected subject projection' USING ERRCODE='23514'; END IF;
  IF e.operation='outcome.confirm' THEN
   SELECT * INTO v FROM management_reviews WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND id=e.review_id;
   SELECT * INTO s FROM management_support_versions WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND id=e.support_id;
   IF e.state<>'reviewed' OR e.review_status<>'confirmed' OR e.support_status<>'recorded' OR v.operation IS DISTINCT FROM 'outcome.confirm' OR e.current_outcome IS DISTINCT FROM s.outcome THEN RAISE EXCEPTION 'Management confirmed projection' USING ERRCODE='23514'; END IF;
  END IF;
  IF TG_TABLE_NAME='management_actions' AND e.current_outcome IS NOT NULL AND NOT EXISTS(SELECT 1 FROM management_support_versions x JOIN management_source_heads y ON y.tenant_id=x.tenant_id AND y.project_id=x.project_id AND y.source_instance_id=x.source_instance_id AND y.family='support' AND y.object_id=x.object_id AND y.source_revision=x.source_revision AND y.receipt_id=x.receipt_id AND y.complete WHERE x.tenant_id=e.tenant_id AND x.project_id=e.project_id AND x.id=e.support_id) THEN RAISE EXCEPTION 'Management current favorable support requires complete exact source head' USING ERRCODE='23514'; END IF;
  IF e.current_outcome IS NOT NULL THEN
   SELECT * INTO v FROM management_reviews WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND id=e.review_id;
   SELECT * INTO s FROM management_support_versions WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND id=e.support_id;
   IF e.state<>'reviewed' OR e.support_status<>'recorded' OR e.review_status<>'confirmed' OR v.operation IS DISTINCT FROM 'outcome.confirm' OR (v.action_id,v.action_version_id,v.assignment_id,v.support_id,v.action_hash,v.assignment_hash,v.support_hash,v.references_hash) IS DISTINCT FROM (e.action_id,e.action_version_id,e.assignment_id,e.support_id,s.action_hash,s.assignment_hash,s.support_hash,s.references_hash) OR e.current_outcome IS DISTINCT FROM s.outcome THEN RAISE EXCEPTION 'Management favorable output requires exact confirmation' USING ERRCODE='23514'; END IF;
  END IF;
  IF e.operation LIKE 'outcome.%' AND NOT EXISTS(SELECT 1 FROM management_reviews WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND id=e.review_id AND receipt_id=e.receipt_id AND operation=e.operation) THEN RAISE EXCEPTION 'Management exact review event' USING ERRCODE='23503'; END IF;
  IF e.operation='outcome.revoke' AND (e.state<>'awaiting_review' OR e.review_status<>'revoked' OR e.support_id IS NULL OR e.current_outcome IS NOT NULL) THEN RAISE EXCEPTION 'Management revoked projection' USING ERRCODE='23514'; END IF;
  IF e.operation='action.withdraw' AND (e.state<>'withdrawn' OR e.current_outcome IS NOT NULL) THEN RAISE EXCEPTION 'Management withdrawal projection' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME IN ('management_action_versions','management_support_versions','management_reference_sets') THEN
  IF TG_TABLE_NAME='management_reference_sets' THEN rs=NEW;
  ELSIF TG_TABLE_NAME='management_action_versions' THEN SELECT * INTO rs FROM management_reference_sets WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND action_version_id=NEW.id;
  ELSE SELECT * INTO rs FROM management_reference_sets WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND support_id=NEW.id; END IF;
  IF rs.id IS NULL THEN RAISE EXCEPTION 'Management exact reference set required' USING ERRCODE='23503'; END IF;
  SELECT count(*) INTO cnt FROM management_references WHERE tenant_id=rs.tenant_id AND project_id=rs.project_id AND reference_set_id=rs.id;
  IF cnt NOT BETWEEN 1 AND 20 OR (SELECT count(DISTINCT ordinal) FROM management_references WHERE tenant_id=rs.tenant_id AND project_id=rs.project_id AND reference_set_id=rs.id)<>cnt OR (SELECT max(ordinal) FROM management_references WHERE tenant_id=rs.tenant_id AND project_id=rs.project_id AND reference_set_id=rs.id)<>cnt THEN RAISE EXCEPTION 'Management reference cardinality' USING ERRCODE='23514'; END IF;
  IF rs.action_version_id IS NOT NULL THEN
   SELECT * INTO a FROM management_action_versions WHERE tenant_id=rs.tenant_id AND project_id=rs.project_id AND id=rs.action_version_id;
   IF rs.references_hash IS DISTINCT FROM a.references_hash THEN RAISE EXCEPTION 'Management action reference hash' USING ERRCODE='23514'; END IF;
  ELSE
   SELECT * INTO s FROM management_support_versions WHERE tenant_id=rs.tenant_id AND project_id=rs.project_id AND id=rs.support_id;
   IF rs.references_hash IS DISTINCT FROM s.references_hash THEN RAISE EXCEPTION 'Management support reference hash' USING ERRCODE='23514'; END IF;
   IF EXISTS(SELECT 1 FROM management_references x WHERE x.tenant_id=rs.tenant_id AND x.project_id=rs.project_id AND x.reference_set_id=rs.id AND NOT EXISTS(SELECT 1 FROM management_reference_sets ars JOIN management_references y ON y.tenant_id=ars.tenant_id AND y.project_id=ars.project_id AND y.reference_set_id=ars.id WHERE ars.tenant_id=rs.tenant_id AND ars.project_id=rs.project_id AND ars.action_version_id=s.action_version_id AND (y.task_version_id,y.criterion_id,y.criterion_version_id,y.relation)=(x.task_version_id,x.criterion_id,x.criterion_version_id,x.relation))) THEN RAISE EXCEPTION 'Management support action tuple overlap' USING ERRCODE='23503'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='management_source_heads' THEN
  SELECT * INTO r FROM management_receipts WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.receipt_id;
  expected_complete=NEW.source_revision=1;
  IF TG_OP='UPDATE' THEN
   expected_complete=OLD.complete AND NEW.source_revision=OLD.source_revision+1;
   IF NEW.family='support' THEN
    SELECT * INTO s FROM management_support_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=(r.envelope->'body'->>'predecessor_support_id')::uuid;
    expected_complete=expected_complete AND s.receipt_id IS NOT DISTINCT FROM OLD.receipt_id AND s.id IS NOT NULL;
   END IF;
  END IF;
  IF NEW.complete IS DISTINCT FROM expected_complete THEN RAISE EXCEPTION 'Management source completeness' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION guard_management_fact() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE j jsonb=to_jsonb(NEW); r record; a record; s record; v record; body jsonb; expected jsonb; subject uuid; op text; fields text[]; f text;
BEGIN
 IF nullif(current_setting('app.management_authority_closed',true),'') IS DISTINCT FROM '1' THEN RAISE EXCEPTION 'Management coordination required' USING ERRCODE='42501'; END IF;
 IF TG_TABLE_NAME='management_receipts' THEN
  IF NEW.authority_proof->>'tenant_id' IS DISTINCT FROM NEW.tenant_id::text OR NEW.authority_proof->>'project_id' IS DISTINCT FROM NEW.project_id::text OR NEW.authority_proof->>'actor_id' IS DISTINCT FROM NEW.actor_id::text OR NOT EXISTS(SELECT 1 FROM users WHERE tenant_id=NEW.tenant_id AND id=NEW.actor_id AND employee_id::text=NEW.authority_proof->>'employee_id') THEN RAISE EXCEPTION 'Management actor boundary' USING ERRCODE='23514'; END IF;
  IF NEW.variant='source' THEN
   IF (NEW.source_instance_id::text,NEW.object_id,NEW.source_revision::text,NEW.operation,NEW.occurred_at,NEW.source_updated_at) IS DISTINCT FROM (NEW.envelope->>'source_instance_id',NEW.envelope->>'object_id',NEW.envelope->>'source_revision',NEW.envelope->>'operation',(NEW.envelope->>'occurred_at')::timestamptz,(NEW.envelope->>'source_updated_at')::timestamptz) OR NEW.envelope->>'schema_version' IS DISTINCT FROM 'management-evidence-v1' THEN RAISE EXCEPTION 'Management source envelope mismatch' USING ERRCODE='23514'; END IF;
  ELSIF NEW.envelope->>'operation' IS DISTINCT FROM NEW.operation THEN RAISE EXCEPTION 'Management operation mismatch' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 IF j ? 'receipt_id' AND TG_TABLE_NAME<>'management_source_heads' THEN
  SELECT * INTO r FROM management_receipts WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=(j->>'receipt_id')::uuid;
  IF NOT FOUND THEN RAISE EXCEPTION 'Management receipt missing' USING ERRCODE='23503'; END IF;
  IF j ? 'actor_id' AND ((j->>'actor_id')::uuid IS DISTINCT FROM r.actor_id OR j->'authority_proof' IS DISTINCT FROM r.authority_proof) THEN RAISE EXCEPTION 'Management receipt proof mismatch' USING ERRCODE='23514'; END IF;
  body=CASE WHEN r.variant='source' THEN r.envelope->'body' ELSE r.envelope END;
 END IF;
 IF TG_TABLE_NAME='management_action_versions' THEN
  IF r.operation NOT IN ('action.open','action.revise','action.reopen') THEN RAISE EXCEPTION 'Management action receipt operation' USING ERRCODE='23514'; END IF;
  IF r.operation<>'action.open' THEN body=body->'action'; END IF;
  IF (NEW.owner_id::text,NEW.reason) IS DISTINCT FROM (body->>'owner_id',body->>'reason') OR NEW.requester_id::text IS DISTINCT FROM NEW.authority_proof->>'employee_id' AND r.operation='action.open' THEN RAISE EXCEPTION 'Management action content' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='management_assignments' THEN
  SELECT * INTO a FROM management_action_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.action_version_id;
  IF NEW.action_hash IS DISTINCT FROM a.action_hash OR (NEW.owner_id,NEW.action_id) IS DISTINCT FROM (a.owner_id,a.action_id) OR r.operation<>'assignment.set' OR (NEW.owner_id::text,NEW.helper_id::text,NEW.department_id::text,NEW.reason) IS DISTINCT FROM (body->>'owner_id',body->>'helper_id',body->>'department_id',body->>'reason') THEN RAISE EXCEPTION 'Management assignment boundary' USING ERRCODE='23503'; END IF;
 ELSIF TG_TABLE_NAME='management_support_versions' THEN
  SELECT * INTO a FROM management_assignments WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.assignment_id;
  IF NEW.operation IS DISTINCT FROM r.operation OR NEW.helper_id IS DISTINCT FROM a.helper_id OR NEW.recorder_id::text IS DISTINCT FROM NEW.authority_proof->>'employee_id' OR NEW.recorder_id NOT IN (a.owner_id,a.helper_id) THEN RAISE EXCEPTION 'Management recorder boundary' USING ERRCODE='23514'; END IF;
  IF (NEW.action_id::text,NEW.action_version_id::text,NEW.action_hash,NEW.assignment_id::text,NEW.assignment_hash,NEW.text,NEW.reason,NEW.outcome,NEW.predecessor_support_id::text) IS DISTINCT FROM (body->>'action_id',body->>'action_version_id',body->>'action_hash',body->>'assignment_id',body->>'assignment_hash',body->>'text',body->>'reason',body->>'outcome',body->>'predecessor_support_id') OR (NEW.source_instance_id,NEW.object_id,NEW.source_revision,NEW.occurred_at,NEW.source_updated_at) IS DISTINCT FROM (r.source_instance_id,r.object_id,r.source_revision,r.occurred_at,r.source_updated_at) THEN RAISE EXCEPTION 'Management support content' USING ERRCODE='23514'; END IF;
  IF NEW.predecessor_support_id IS NOT NULL THEN
   SELECT * INTO s FROM management_support_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.predecessor_support_id;
   IF s.source_revision>=NEW.source_revision OR (s.action_version_id,s.assignment_id,s.source_instance_id,s.object_id) IS DISTINCT FROM (NEW.action_version_id,NEW.assignment_id,NEW.source_instance_id,NEW.object_id) THEN RAISE EXCEPTION 'Management support predecessor' USING ERRCODE='23503'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='management_reviews' THEN
  SELECT * INTO s FROM management_support_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.support_id;
  IF NEW.reviewer_id::text IS DISTINCT FROM NEW.authority_proof->>'employee_id' OR NEW.reviewer_id IN (s.helper_id,s.recorder_id) THEN RAISE EXCEPTION 'Management independent employee required' USING ERRCODE='23514'; END IF;
  IF NEW.operation IS DISTINCT FROM r.operation OR (NEW.action_version_id::text,NEW.action_hash,NEW.assignment_id::text,NEW.assignment_hash,NEW.support_id::text,NEW.support_hash,NEW.references_hash,NEW.reason,NEW.predecessor_review_id::text) IS DISTINCT FROM (body->>'action_version_id',body->>'action_hash',body->>'assignment_id',body->>'assignment_hash',body->>'support_id',body->>'support_hash',body->>'references_hash',body->>'reason',body->>'predecessor_review_id') OR NEW.operation='outcome.confirm' AND s.operation NOT IN ('support.record','support.correct') THEN RAISE EXCEPTION 'Management review boundary' USING ERRCODE='23514'; END IF;
  IF NEW.operation='outcome.revoke' THEN
   SELECT * INTO v FROM management_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.predecessor_review_id;
   IF v.operation<>'outcome.confirm' OR (v.action_version_id,v.assignment_id,v.support_id) IS DISTINCT FROM (NEW.action_version_id,NEW.assignment_id,NEW.support_id) THEN RAISE EXCEPTION 'Management review predecessor' USING ERRCODE='23503'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='management_events' THEN
  IF NEW.operation IS DISTINCT FROM r.operation OR NEW.action_id IS DISTINCT FROM r.action_id OR NEW.id IS DISTINCT FROM r.event_id THEN RAISE EXCEPTION 'Management event receipt boundary' USING ERRCODE='23514'; END IF;
  IF NEW.context IS DISTINCT FROM body->>'context' OR (NEW.operation IN ('action.withdraw','assignment.set','support.retract','support.decline','outcome.confirm','outcome.reject','outcome.revoke') AND NEW.reason IS DISTINCT FROM body->>'reason') THEN RAISE EXCEPTION 'Management event text boundary' USING ERRCODE='23514'; END IF;
  IF NEW.assignment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM management_assignments WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.assignment_id AND action_version_id=NEW.action_version_id) THEN RAISE EXCEPTION 'Management event assignment' USING ERRCODE='23503'; END IF;
  IF NEW.support_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM management_support_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.support_id AND action_version_id=NEW.action_version_id AND assignment_id=NEW.assignment_id) THEN RAISE EXCEPTION 'Management event support' USING ERRCODE='23503'; END IF;
  IF NEW.review_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM management_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.review_id AND action_version_id=NEW.action_version_id AND assignment_id=NEW.assignment_id AND support_id=CASE WHEN NEW.operation='outcome.reject' THEN NEW.subject_support_id ELSE NEW.support_id END) THEN RAISE EXCEPTION 'Management event review' USING ERRCODE='23503'; END IF;
 ELSIF TG_TABLE_NAME='management_request_bindings' THEN
  IF NEW.input_hash IS DISTINCT FROM r.input_hash THEN RAISE EXCEPTION 'Management alias hash' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='management_references' THEN
  IF NOT EXISTS(SELECT 1 FROM requirement_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.requirement_version_id AND content_hash=NEW.requirement_hash) THEN RAISE EXCEPTION 'Management requirement association' USING ERRCODE='23503'; END IF;
  IF EXISTS(SELECT 1 FROM management_references x WHERE x.tenant_id=NEW.tenant_id AND x.project_id=NEW.project_id AND x.reference_set_id=NEW.reference_set_id AND x.task_version_id=NEW.task_version_id AND x.criterion_id=NEW.criterion_id AND x.criterion_version_id=NEW.criterion_version_id AND (x.relation<>NEW.relation OR (x.kind=NEW.kind AND (to_jsonb(x)->>(NEW.kind||'_id')) IS NOT DISTINCT FROM (j->>(NEW.kind||'_id'))))) THEN RAISE EXCEPTION 'Management duplicate or contradictory reference' USING ERRCODE='23514'; END IF;
  IF NEW.kind='qa_handoff_event' THEN
   IF NOT EXISTS(SELECT 1 FROM qa_handoff_events e JOIN qa_handoffs h ON h.tenant_id=e.tenant_id AND h.project_id=e.project_id AND h.id=e.handoff_id WHERE e.tenant_id=NEW.tenant_id AND e.project_id=NEW.project_id AND e.id=NEW.qa_handoff_event_id AND (h.task_version_id,h.criterion_id,h.criterion_version_id)=(NEW.task_version_id,NEW.criterion_id,NEW.criterion_version_id)) THEN RAISE EXCEPTION 'Management handoff association' USING ERRCODE='23503'; END IF;
  ELSIF NEW.kind='qa_defect_event' THEN
   IF NOT EXISTS(SELECT 1 FROM qa_defect_events e WHERE e.tenant_id=NEW.tenant_id AND e.project_id=NEW.project_id AND e.id=NEW.qa_defect_event_id AND (e.task_version_id,e.criterion_id,e.criterion_version_id)=(NEW.task_version_id,NEW.criterion_id,NEW.criterion_version_id)) THEN RAISE EXCEPTION 'Management defect association' USING ERRCODE='23503'; END IF;
  ELSIF NEW.kind='qa_execution_version' THEN
   IF NOT EXISTS(SELECT 1 FROM qa_execution_versions e WHERE e.tenant_id=NEW.tenant_id AND e.project_id=NEW.project_id AND e.id=NEW.qa_execution_version_id AND ((e.responsibility->>'task_version_id')::uuid,e.criterion_id,e.criterion_version_id)=(NEW.task_version_id,NEW.criterion_id,NEW.criterion_version_id)) THEN RAISE EXCEPTION 'Management execution association' USING ERRCODE='23503'; END IF;
  ELSIF NEW.kind IN ('ba_request_version','ba_answer_version','ba_review') THEN
   subject=NEW.ba_request_version_id;
   IF NEW.kind='ba_answer_version' THEN SELECT request_version_id INTO subject FROM ba_answer_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.ba_answer_version_id; END IF;
   IF NEW.kind='ba_review' THEN SELECT request_version_id INTO subject FROM ba_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.ba_review_id; END IF;
   IF NOT EXISTS(SELECT 1 FROM ba_request_links WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND request_version_id=subject AND (task_version_id,criterion_id,criterion_version_id)=(NEW.task_version_id,NEW.criterion_id,NEW.criterion_version_id)) THEN RAISE EXCEPTION 'Management BA association' USING ERRCODE='23503'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE FUNCTION guard_management_atomic_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE root_row record; state_row record;
BEGIN
 IF nullif(current_setting('app.management_authority_closed',true),'') IS DISTINCT FROM '1' THEN RAISE EXCEPTION 'Management coordination required' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM projects WHERE tenant_id=NEW.tenant_id AND id=NEW.project_id FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Management scoped project missing' USING ERRCODE='23503'; END IF;
 IF TG_TABLE_NAME='management_terminal_applicability' THEN
  IF EXISTS(SELECT 1 FROM management_terminal_applicability WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND (kind,subject_id,proof_hash,reason_code)=(NEW.kind,NEW.subject_id,NEW.proof_hash,NEW.reason_code)) THEN RETURN NULL; END IF;
 END IF;
 IF NEW.project_generation IS NOT NULL THEN RAISE EXCEPTION 'Management generation is database assigned' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='management_events' THEN
  SELECT * INTO root_row FROM management_actions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.action_id FOR UPDATE;
  IF root_row.id IS NULL OR root_row.revision<>NEW.revision-1 OR (NEW.revision=1 AND (NEW.operation<>'action.open' OR root_row.current_event_id IS NOT NULL)) OR (NEW.revision>1 AND NOT EXISTS(SELECT 1 FROM management_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND action_id=NEW.action_id AND revision=NEW.revision-1 AND id=root_row.current_event_id)) THEN RAISE EXCEPTION 'Management contiguous event transition required' USING ERRCODE='23514'; END IF;
 END IF;
 INSERT INTO management_project_state(id,tenant_id,project_id) VALUES(gen_random_uuid(),NEW.tenant_id,NEW.project_id) ON CONFLICT(tenant_id,project_id) DO NOTHING;
 SELECT * INTO state_row FROM management_project_state WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id FOR UPDATE;
 NEW.project_generation=state_row.generation+1;
 UPDATE management_project_state SET generation=NEW.project_generation,current_event_id=CASE WHEN TG_TABLE_NAME='management_events' THEN NEW.id ELSE NULL END,current_terminal_id=CASE WHEN TG_TABLE_NAME='management_terminal_applicability' THEN NEW.id ELSE NULL END WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id;
 RETURN NEW;
END $$;
CREATE FUNCTION guard_management_atomic_commit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE final_root record; final_state record;
BEGIN
 IF TG_TABLE_NAME='management_project_state' THEN
  IF NEW.generation=0 AND num_nonnulls(NEW.current_event_id,NEW.current_terminal_id)=0 THEN RETURN NEW; END IF;
  IF num_nonnulls(NEW.current_event_id,NEW.current_terminal_id)<>1 OR (NEW.current_event_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM management_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.current_event_id AND project_generation=NEW.generation)) OR (NEW.current_terminal_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM management_terminal_applicability WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.current_terminal_id AND project_generation=NEW.generation)) THEN RAISE EXCEPTION 'Management exact generation fact required' USING ERRCODE='23514'; END IF;
 ELSE
  IF NEW.project_generation IS NULL THEN RAISE EXCEPTION 'Management new fact generation required' USING ERRCODE='23514'; END IF;
  SELECT * INTO final_state FROM management_project_state WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id;
  IF final_state.generation IS NULL OR final_state.generation<NEW.project_generation THEN RAISE EXCEPTION 'Management generation must reach new fact' USING ERRCODE='23514'; END IF;
  IF TG_TABLE_NAME='management_events' THEN
   SELECT * INTO final_root FROM management_actions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.action_id;
   IF final_root.id IS NULL OR final_root.revision<NEW.revision OR NOT EXISTS(SELECT 1 FROM management_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND action_id=NEW.action_id AND revision=final_root.revision AND id=final_root.current_event_id) THEN RAISE EXCEPTION 'Management root must reach new event' USING ERRCODE='23514'; END IF;
   IF EXISTS(SELECT 1 FROM management_terminal_applicability WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND project_generation=NEW.project_generation) THEN RAISE EXCEPTION 'Management generation fact collision' USING ERRCODE='23514'; END IF;
  ELSE
   IF EXISTS(SELECT 1 FROM management_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND project_generation=NEW.project_generation) THEN RAISE EXCEPTION 'Management generation fact collision' USING ERRCODE='23514'; END IF;
  END IF;
  IF final_state.current_event_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM management_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND project_generation=final_state.generation AND id=final_state.current_event_id) OR final_state.current_terminal_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM management_terminal_applicability WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND project_generation=final_state.generation AND id=final_state.current_terminal_id) THEN RAISE EXCEPTION 'Management final generation head mismatch' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER management_atomic_insert BEFORE INSERT ON management_events FOR EACH ROW EXECUTE FUNCTION guard_management_atomic_insert();
CREATE TRIGGER management_atomic_insert BEFORE INSERT ON management_terminal_applicability FOR EACH ROW EXECUTE FUNCTION guard_management_atomic_insert();
CREATE CONSTRAINT TRIGGER management_atomic_commit AFTER INSERT ON management_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_management_atomic_commit();
CREATE CONSTRAINT TRIGGER management_atomic_commit AFTER INSERT ON management_terminal_applicability DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_management_atomic_commit();
CREATE CONSTRAINT TRIGGER management_atomic_commit AFTER INSERT OR UPDATE ON management_project_state DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_management_atomic_commit();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Management atomic lineage must be preserved.');
    }
};
