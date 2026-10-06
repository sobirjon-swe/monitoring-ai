<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE developer_outbox ADD COLUMN processing_authority jsonb CHECK(processing_authority IS NULL OR (jsonb_typeof(processing_authority)='object' AND octet_length(processing_authority::text)<=32768));
ALTER TABLE developer_outbox ADD COLUMN processing_authority_hash varchar(64) CHECK(processing_authority_hash IS NULL OR processing_authority_hash ~ '^[0-9a-f]{64}$');
ALTER TABLE developer_outbox ADD CONSTRAINT developer_processing_proof_pair CHECK((processing_authority IS NULL)=(processing_authority_hash IS NULL) AND (run_id IS NOT NULL OR processing_authority IS NULL));
CREATE FUNCTION guard_developer_processing_authority() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' THEN
  IF (NEW.processing_authority,NEW.processing_authority_hash) IS DISTINCT FROM (OLD.processing_authority,OLD.processing_authority_hash)
   AND NOT (NEW.run_id IS NOT NULL AND NEW.status='processing' AND NEW.lease_token IS DISTINCT FROM OLD.lease_token AND NEW.attempt_count=OLD.attempt_count+1)
   THEN RAISE EXCEPTION 'Processing authority is immutable within a lease' USING ERRCODE='42501'; END IF;
  IF NEW.run_id IS NOT NULL AND NEW.status='processing' AND NEW.lease_token IS DISTINCT FROM OLD.lease_token AND NEW.processing_authority IS NULL
   THEN RAISE EXCEPTION 'Execution claim requires durable processing authority' USING ERRCODE='23514'; END IF;
 ELSIF NEW.processing_authority IS NOT NULL THEN
  RAISE EXCEPTION 'Processing authority must be captured at claim' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER developer_processing_authority_guard BEFORE INSERT OR UPDATE ON developer_outbox FOR EACH ROW EXECUTE FUNCTION guard_developer_processing_authority();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Durable developer processing authority must be preserved.');
    }
};
