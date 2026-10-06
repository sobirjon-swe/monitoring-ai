<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
DO $$ DECLARE c record; BEGIN
 FOR c IN SELECT conname FROM pg_constraint WHERE conrelid='lifecycle_outbox'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%reason_code%' LOOP
  EXECUTE format('ALTER TABLE lifecycle_outbox DROP CONSTRAINT %I',c.conname);
 END LOOP;
END $$;
ALTER TABLE lifecycle_outbox ADD CONSTRAINT lifecycle_outbox_reason CHECK(reason_code IS NULL OR reason_code IN ('processing_failed','access_revoked','input_stale','stale_generation','input_unavailable'));
CREATE OR REPLACE FUNCTION guard_acceptance_applicability() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.tenant_id,NEW.project_id,NEW.decision_id,NEW.pins_hash) IS DISTINCT FROM (OLD.tenant_id,OLD.project_id,OLD.decision_id,OLD.pins_hash) THEN RAISE EXCEPTION 'Immutable applicability identity' USING ERRCODE='42501'; END IF;
 IF NEW.input_generation<OLD.input_generation THEN RAISE EXCEPTION 'Applicability generation regressed' USING ERRCODE='23514'; END IF;
 IF (OLD.status='revoked' AND NEW.status<>'revoked') OR (OLD.status='stale' AND NEW.status='valid') THEN RAISE EXCEPTION 'Terminal applicability cannot be restored' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Observed invalidations must be retained.');
    }
};
