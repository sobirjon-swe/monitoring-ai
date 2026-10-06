<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE developer_runs ADD CONSTRAINT developer_run_complete_lease CHECK((status='running' AND lease_token IS NOT NULL AND lease_until IS NOT NULL) OR (status<>'running' AND lease_token IS NULL AND lease_until IS NULL));
ALTER TABLE developer_outbox ADD CONSTRAINT developer_outbox_complete_lease CHECK((status='processing' AND lease_token IS NOT NULL AND lease_until IS NOT NULL) OR (status<>'processing' AND lease_token IS NULL AND lease_until IS NULL));
ALTER TABLE developer_runs ADD UNIQUE(tenant_id,project_id,snapshot_id,id,input_hash);
ALTER TABLE developer_results ADD CONSTRAINT developer_result_exact_input_fk FOREIGN KEY(tenant_id,project_id,snapshot_id,run_id,input_hash) REFERENCES developer_runs(tenant_id,project_id,snapshot_id,id,input_hash);
CREATE FUNCTION guard_developer_lease() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE active_status text;
BEGIN
 active_status := CASE WHEN TG_TABLE_NAME='developer_runs' THEN 'running' ELSE 'processing' END;
 IF TG_OP='INSERT' THEN
  IF NEW.status<>(CASE WHEN TG_TABLE_NAME='developer_runs' THEN 'queued' ELSE 'pending' END) OR NEW.attempt_count<>0 THEN RAISE EXCEPTION 'Execution must start pending' USING ERRCODE='23514'; END IF;
 ELSIF NEW.status=active_status AND (OLD.status<>active_status OR NEW.lease_token IS DISTINCT FROM OLD.lease_token) THEN
  IF NEW.attempt_count<>OLD.attempt_count+1 OR NEW.lease_until<=clock_timestamp() THEN RAISE EXCEPTION 'New lease requires a bounded new attempt' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER developer_run_lease BEFORE INSERT OR UPDATE ON developer_runs FOR EACH ROW EXECUTE FUNCTION guard_developer_lease();
CREATE TRIGGER developer_outbox_lease BEFORE INSERT OR UPDATE ON developer_outbox FOR EACH ROW EXECUTE FUNCTION guard_developer_lease();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Developer execution guards must be preserved.');
    }
};
