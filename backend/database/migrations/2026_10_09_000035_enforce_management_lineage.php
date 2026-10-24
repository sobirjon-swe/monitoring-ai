<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_management_fact() RETURNS trigger LANGUAGE plpgsql AS $$
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
  IF NEW.review_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM management_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.review_id AND action_version_id=NEW.action_version_id AND assignment_id=NEW.assignment_id AND support_id=NEW.support_id) THEN RAISE EXCEPTION 'Management event review' USING ERRCODE='23503'; END IF;
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
SQL);
        foreach (['management_project_state', 'management_receipts', 'management_request_bindings', 'management_source_heads', 'management_actions', 'management_action_versions', 'management_assignments', 'management_support_versions', 'management_reviews', 'management_events', 'management_reference_sets', 'management_references', 'management_terminal_applicability'] as $table) {
            DB::statement("CREATE TRIGGER management_fact_guard BEFORE INSERT ON $table FOR EACH ROW EXECUTE FUNCTION guard_management_fact()");
        }
    }

    public function down(): void
    {
        throw new LogicException('Immutable management lineage must be preserved.');
    }
};
