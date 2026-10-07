<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE qa_definition_versions ADD CONSTRAINT qa_definition_initial_review CHECK((review_revision=0 AND review_state='designed') OR (review_revision>0 AND review_state<>'designed'));
ALTER TABLE qa_plan_versions ADD CONSTRAINT qa_plan_initial_review CHECK((review_revision=0 AND review_state='draft') OR (review_revision>0 AND review_state<>'draft'));
CREATE FUNCTION guard_qa_review_head_binding() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE review qa_reviews;
BEGIN
 IF NEW.review_revision=0 THEN
  IF NEW.current_review_id IS NOT NULL OR NEW.review_state<>(CASE WHEN TG_TABLE_NAME='qa_plan_versions' THEN 'draft' ELSE 'designed' END) THEN RAISE EXCEPTION 'Initial version cannot bypass review' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO review FROM qa_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.current_review_id AND revision=NEW.review_revision;
  IF NOT FOUND OR review.decision IS DISTINCT FROM NEW.review_state OR review.subject_hash IS DISTINCT FROM NEW.content_hash OR (TG_TABLE_NAME='qa_plan_versions' AND review.plan_id IS DISTINCT FROM NEW.id) OR (TG_TABLE_NAME='qa_definition_versions' AND review.definition_id IS DISTINCT FROM NEW.id) THEN RAISE EXCEPTION 'Review head must bind subject and decision' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER qa_review_head_binding BEFORE INSERT OR UPDATE ON qa_definition_versions FOR EACH ROW EXECUTE FUNCTION guard_qa_review_head_binding();
CREATE TRIGGER qa_review_head_binding BEFORE INSERT OR UPDATE ON qa_plan_versions FOR EACH ROW EXECUTE FUNCTION guard_qa_review_head_binding();
CREATE FUNCTION guard_qa_selected_review() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM qa_plan_versions p JOIN qa_reviews r ON r.tenant_id=p.tenant_id AND r.project_id=p.project_id AND r.plan_id=p.id AND r.id=p.current_review_id AND r.revision=p.review_revision AND r.subject_hash=p.content_hash AND r.decision='approved' WHERE p.tenant_id=NEW.tenant_id AND p.project_id=NEW.project_id AND p.id=NEW.plan_id AND p.review_state='approved') THEN RAISE EXCEPTION 'Selection must bind reviewed plan' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER qa_selected_review BEFORE INSERT ON qa_selection_events FOR EACH ROW EXECUTE FUNCTION guard_qa_selected_review();
CREATE FUNCTION guard_qa_defect_closure() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.event_type='verified_closed' THEN
  IF NEW.execution_id IS NULL OR NEW.fix_request_event_id IS NULL THEN RAISE EXCEPTION 'Closure requires execution and fix request' USING ERRCODE='23514'; END IF;
  IF NOT EXISTS(SELECT 1 FROM qa_execution_versions e JOIN qa_execution_attempts a ON a.tenant_id=e.tenant_id AND a.project_id=e.project_id AND a.id=e.attempt_id AND a.series_id=e.series_id JOIN qa_defect_events f ON f.tenant_id=a.tenant_id AND f.project_id=a.project_id AND f.id=a.fix_request_event_id WHERE e.tenant_id=NEW.tenant_id AND e.project_id=NEW.project_id AND e.id=NEW.execution_id AND a.fix_request_event_id=NEW.fix_request_event_id AND f.defect_id=NEW.defect_id AND f.event_type='fix_requested' AND (e.criterion_version_id,e.definition_id,e.build_id,e.environment_id)=(NEW.criterion_version_id,NEW.definition_id,NEW.build_id,NEW.environment_id)) THEN RAISE EXCEPTION 'Closure execution must retest this exact defect request' USING ERRCODE='23503'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER qa_defect_closure BEFORE INSERT ON qa_defect_events FOR EACH ROW EXECUTE FUNCTION guard_qa_defect_closure();
ALTER TABLE qa_request_keys ADD CONSTRAINT qa_request_operation_output CHECK(CASE
 WHEN operation IN ('build','environment','definition','execution') THEN receipt_id IS NOT NULL
 WHEN operation='defect.report' THEN receipt_id IS NOT NULL OR defect_id IS NOT NULL
 WHEN operation='handoff.open' THEN receipt_id IS NOT NULL OR handoff_id IS NOT NULL
 WHEN operation='plan.entry' THEN entry_id IS NOT NULL
 WHEN operation='plan.compose' THEN plan_id IS NOT NULL
 ELSE false END);
CREATE FUNCTION guard_qa_request_receipt_kind() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE expected text;
BEGIN
 IF NEW.receipt_id IS NOT NULL AND NEW.operation IN ('build','environment','definition','execution','defect.report','handoff.open') THEN
  expected=CASE NEW.operation WHEN 'defect.report' THEN 'defect' WHEN 'handoff.open' THEN 'handoff' ELSE NEW.operation END;
  IF NOT EXISTS(SELECT 1 FROM qa_receipts WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.receipt_id AND kind=expected) THEN RAISE EXCEPTION 'Request receipt must match operation kind' USING ERRCODE='23503'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER qa_request_receipt_kind BEFORE INSERT ON qa_request_keys FOR EACH ROW EXECUTE FUNCTION guard_qa_request_receipt_kind();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Immutable QA review and lineage must be preserved.');
    }
};
