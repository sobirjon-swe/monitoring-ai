<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve confirmed context and strictly late historical subjects without
        // weakening the exact predecessor or confirmed support projection.
        DB::unprepared(<<<'SQL'
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
   IF NEW.review_id IS NULL OR num_nonnulls(NEW.action_version_id,NEW.assignment_id,NEW.support_id)<>0 OR NOT EXISTS(SELECT 1 FROM management_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.review_id AND receipt_id=NEW.id) THEN RAISE EXCEPTION 'Management review output' USING ERRCODE='23503'; END IF;
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
  IF NOT is_late AND e.operation IN ('support.record','support.correct') AND (e.state<>'awaiting_review' OR e.current_outcome IS NOT NULL OR e.review_id IS NOT NULL) THEN RAISE EXCEPTION 'Management unverified support projection' USING ERRCODE='23514'; END IF;
  IF e.operation='outcome.confirm' THEN
   SELECT * INTO v FROM management_reviews WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND id=e.review_id;
   SELECT * INTO s FROM management_support_versions WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND id=e.support_id;
   IF e.state<>'reviewed' OR e.review_status<>'confirmed' OR e.support_status<>'recorded' OR v.operation IS DISTINCT FROM 'outcome.confirm' OR e.current_outcome IS DISTINCT FROM s.outcome THEN RAISE EXCEPTION 'Management confirmed projection' USING ERRCODE='23514'; END IF;
  END IF;
  IF e.current_outcome IS NOT NULL THEN
   SELECT * INTO v FROM management_reviews WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND id=e.review_id;
   SELECT * INTO s FROM management_support_versions WHERE tenant_id=e.tenant_id AND project_id=e.project_id AND id=e.support_id;
   IF e.state<>'reviewed' OR e.support_status<>'recorded' OR e.review_status<>'confirmed' OR v.operation IS DISTINCT FROM 'outcome.confirm' OR (v.action_id,v.action_version_id,v.assignment_id,v.support_id,v.action_hash,v.assignment_hash,v.support_hash,v.references_hash) IS DISTINCT FROM (e.action_id,e.action_version_id,e.assignment_id,e.support_id,s.action_hash,s.assignment_hash,s.support_hash,s.references_hash) OR e.current_outcome IS DISTINCT FROM s.outcome THEN RAISE EXCEPTION 'Management favorable output requires exact confirmation' USING ERRCODE='23514'; END IF;
  END IF;
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
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Management history and forward guard corrections must be preserved.');
    }
};
