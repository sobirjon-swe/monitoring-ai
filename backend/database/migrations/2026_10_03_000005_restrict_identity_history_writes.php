<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only the trigger may append snapshots. The table owner remains subject to FORCE RLS.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.snapshot_identity_mapping() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS $$
BEGIN
    IF TG_TABLE_SCHEMA <> 'public' OR TG_TABLE_NAME <> 'identity_mappings' OR TG_OP NOT IN ('INSERT', 'UPDATE') THEN
        RAISE EXCEPTION 'Invalid snapshot trigger source' USING ERRCODE = '42501';
    END IF;
    INSERT INTO public.identity_mapping_histories (id, tenant_id, mapping_id, identity_id, employee_id, status, reviewer_id, changed_by, reason, reviewed_at, valid_from, valid_to, revision, recorded_at)
    VALUES (pg_catalog.gen_random_uuid(), NEW.tenant_id, NEW.id, NEW.identity_id, NEW.employee_id, NEW.status, NEW.reviewer_id, NEW.changed_by, NEW.reason, NEW.reviewed_at, NEW.valid_from, NEW.valid_to, NEW.revision, pg_catalog.clock_timestamp());
    RETURN NEW;
END $$;
REVOKE INSERT ON public.identity_mapping_histories FROM monitoring_ai_app;
REVOKE EXECUTE ON FUNCTION public.snapshot_identity_mapping() FROM PUBLIC, monitoring_ai_app;
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER FUNCTION public.snapshot_identity_mapping() SECURITY INVOKER');
        DB::statement('ALTER FUNCTION public.snapshot_identity_mapping() RESET search_path');
        DB::statement('GRANT EXECUTE ON FUNCTION public.snapshot_identity_mapping() TO PUBLIC');
        DB::statement('GRANT INSERT ON public.identity_mapping_histories TO monitoring_ai_app');
    }
};
