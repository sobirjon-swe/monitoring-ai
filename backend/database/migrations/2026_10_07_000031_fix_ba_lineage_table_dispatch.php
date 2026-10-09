<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Resolve typed NEW fields only after dispatching to their actual table.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_ba_lineage() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record; p record; e record; body jsonb; predecessor uuid; expected_complete boolean;
BEGIN
 IF TG_TABLE_NAME='ba_receipts' THEN
  IF NEW.variant='source' AND (NEW.source_instance_id::text,NEW.object_id,NEW.source_revision::text,NEW.operation,NEW.occurred_at,NEW.source_updated_at) IS DISTINCT FROM (NEW.envelope->>'source_instance_id',NEW.envelope->>'object_id',NEW.envelope->>'source_revision',NEW.envelope->>'operation',(NEW.envelope->>'occurred_at')::timestamptz,(NEW.envelope->>'source_updated_at')::timestamptz) THEN RAISE EXCEPTION 'BA envelope source boundary' USING ERRCODE='23514'; END IF;
  IF NEW.variant='human' AND NEW.operation IS DISTINCT FROM NEW.envelope->>'kind' THEN RAISE EXCEPTION 'BA human envelope operation' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 ELSIF TG_TABLE_NAME='ba_source_heads' THEN
  SELECT * INTO r FROM ba_receipts WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.receipt_id;
  expected_complete=NEW.source_revision=1;
  IF TG_OP='UPDATE' THEN
   expected_complete=NEW.source_revision=OLD.source_revision+1;
   IF r.family='party' THEN
    predecessor=(r.envelope->'body'->>'predecessor_party_version_id')::uuid;
    SELECT * INTO p FROM ba_source_party_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=predecessor;
    expected_complete=expected_complete AND p.receipt_id IS NOT DISTINCT FROM OLD.receipt_id AND predecessor IS NOT NULL;
   ELSIF r.family='answer' THEN
    predecessor=(r.envelope->'body'->>'predecessor_answer_version_id')::uuid;
    SELECT * INTO p FROM ba_answer_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=predecessor;
    expected_complete=expected_complete AND p.receipt_id IS NOT DISTINCT FROM OLD.receipt_id AND predecessor IS NOT NULL;
   END IF;
  END IF;
  IF NEW.complete IS DISTINCT FROM expected_complete THEN RAISE EXCEPTION 'BA source completeness must match exact predecessor' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 SELECT * INTO r FROM ba_receipts WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.receipt_id;
 IF TG_TABLE_NAME IN ('ba_request_versions','ba_answer_versions','ba_reviews','ba_events') THEN
  IF NEW.actor_id IS DISTINCT FROM r.actor_id OR NEW.authority_proof IS DISTINCT FROM r.authority_proof THEN RAISE EXCEPTION 'BA fact must retain receipt authority' USING ERRCODE='23514'; END IF;
 END IF;
 IF TG_TABLE_NAME='ba_source_party_versions' THEN
  IF NEW.predecessor_party_version_id IS NOT NULL THEN
  SELECT * INTO p FROM ba_source_party_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.predecessor_party_version_id;
  IF (p.source_instance_id,p.object_id) IS DISTINCT FROM (NEW.source_instance_id,NEW.object_id) OR p.source_revision>=NEW.source_revision THEN RAISE EXCEPTION 'BA party predecessor boundary' USING ERRCODE='23503'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='ba_request_bindings' THEN
  IF NEW.event_id IS NOT NULL THEN
   SELECT * INTO e FROM ba_events WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.event_id;
   IF (NEW.request_version_id IS NOT NULL AND NEW.request_version_id IS DISTINCT FROM e.request_version_id) OR (NEW.answer_version_id IS NOT NULL AND NEW.answer_version_id IS DISTINCT FROM e.answer_version_id) OR (NEW.review_id IS NOT NULL AND NEW.review_id IS DISTINCT FROM e.review_id) THEN RAISE EXCEPTION 'BA output event pins' USING ERRCODE='23503'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Immutable BA lineage dispatch must be preserved.');
    }
};
