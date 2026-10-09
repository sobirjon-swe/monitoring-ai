<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_ba_receipt_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record; root record; fact record; body jsonb;
BEGIN
 SELECT * INTO r FROM ba_receipts WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.receipt_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'BA receipt boundary scope' USING ERRCODE='23503'; END IF;
 body=CASE WHEN r.variant='source' THEN r.envelope->'body' ELSE r.envelope END;
 IF r.variant='human' AND NEW.clarification_id::text IS DISTINCT FROM body->>'clarification_id' THEN RAISE EXCEPTION 'BA human outer root boundary' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='ba_request_versions' THEN
  IF r.variant='source' THEN
   SELECT * INTO root FROM ba_clarifications WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.clarification_id;
   IF (root.source_instance_id,root.object_id,root.original_requested_at,NEW.revision,NEW.episode) IS DISTINCT FROM (r.source_instance_id,r.object_id,(body->>'requested_at')::timestamptz,1::bigint,1::bigint) THEN RAISE EXCEPTION 'BA opening root source object boundary' USING ERRCODE='23514'; END IF;
  ELSE body=body->'request'; END IF;
  IF NEW.requested_at IS DISTINCT FROM (body->>'requested_at')::timestamptz THEN RAISE EXCEPTION 'BA requested time receipt boundary' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='ba_answer_versions' THEN
  IF NEW.response_at IS DISTINCT FROM (body->>'response_at')::timestamptz THEN RAISE EXCEPTION 'BA response time receipt boundary' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='ba_events' THEN
  IF body ? 'clarification_id' AND NEW.clarification_id::text IS DISTINCT FROM body->>'clarification_id' THEN RAISE EXCEPTION 'BA event receipt root boundary' USING ERRCODE='23514'; END IF;
  IF body ? 'expected_revision' AND NEW.revision IS DISTINCT FROM (body->>'expected_revision')::bigint+1 THEN RAISE EXCEPTION 'BA event aggregate receipt boundary' USING ERRCODE='23514'; END IF;
  IF NEW.kind IN ('context.add','request.withdraw','request.revise','request.reopen') AND (NEW.context,NEW.reason) IS DISTINCT FROM (body->>'context',body->>'reason') THEN RAISE EXCEPTION 'BA event receipt content boundary' USING ERRCODE='23514'; END IF;
  IF NEW.kind IN ('request.open','request.revise','request.reopen') THEN
   SELECT * INTO fact FROM ba_request_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.request_version_id;
   IF fact.receipt_id IS DISTINCT FROM r.id OR (NEW.kind='request.open' AND NEW.revision<>1) THEN RAISE EXCEPTION 'BA event exact request receipt boundary' USING ERRCODE='23514'; END IF;
  ELSIF NEW.kind IN ('answer.record','answer.correct','answer.retract') THEN
   SELECT * INTO fact FROM ba_answer_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.answer_version_id;
   IF fact.receipt_id IS DISTINCT FROM r.id OR NEW.request_version_id::text IS DISTINCT FROM body->>'request_version_id' THEN RAISE EXCEPTION 'BA event exact answer receipt boundary' USING ERRCODE='23514'; END IF;
  ELSIF NEW.kind IN ('review.confirm','review.reject','review.revoke') THEN
   SELECT * INTO fact FROM ba_reviews WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.review_id;
   IF fact.receipt_id IS DISTINCT FROM r.id OR (NEW.request_version_id::text,NEW.answer_version_id::text) IS DISTINCT FROM (body->>'request_version_id',body->>'answer_version_id') THEN RAISE EXCEPTION 'BA event exact review receipt boundary' USING ERRCODE='23514'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER ba_receipt_boundary BEFORE INSERT ON ba_request_versions FOR EACH ROW EXECUTE FUNCTION guard_ba_receipt_boundary();
CREATE TRIGGER ba_receipt_boundary BEFORE INSERT ON ba_answer_versions FOR EACH ROW EXECUTE FUNCTION guard_ba_receipt_boundary();
CREATE TRIGGER ba_receipt_boundary BEFORE INSERT ON ba_events FOR EACH ROW EXECUTE FUNCTION guard_ba_receipt_boundary();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Immutable BA receipt boundaries must be preserved.');
    }
};
