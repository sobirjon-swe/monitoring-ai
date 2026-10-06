<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE public.ingestion_connectors (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES public.tenants(id), project_id uuid NOT NULL,
 source_instance_id uuid NOT NULL, adapter varchar(30) NOT NULL DEFAULT 'synthetic-v1',
 status varchar(20) NOT NULL DEFAULT 'disabled', revision integer NOT NULL DEFAULT 1, created_at timestamptz(6) NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,project_id,id,source_instance_id), UNIQUE(tenant_id,project_id,source_instance_id),
 FOREIGN KEY(tenant_id,project_id) REFERENCES public.projects(tenant_id,id),
 FOREIGN KEY(tenant_id,source_instance_id) REFERENCES public.source_instances(tenant_id,id),
 CHECK(adapter='synthetic-v1'), CHECK(status IN ('active','disabled')), CHECK(revision>0)
);
CREATE TABLE public.ingestion_receipts (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, connector_id uuid NOT NULL,
 source_instance_id uuid NOT NULL, source_object_type varchar(30) NOT NULL DEFAULT 'ticket',
 source_object_id varchar(200) NOT NULL, source_version varchar(200) NOT NULL, event_kind varchar(40) NOT NULL,
 payload_bytes text NOT NULL, payload_json jsonb NOT NULL, transport_hash varchar(64) NOT NULL, canonical_hash varchar(64) NOT NULL,
 occurred_at timestamptz(6) NOT NULL, source_updated_at timestamptz(6), received_at timestamptz(6) NOT NULL,
 UNIQUE(tenant_id,project_id,id),
 UNIQUE(tenant_id,project_id,connector_id,source_instance_id,source_object_type,source_object_id,id),
 UNIQUE(tenant_id,source_instance_id,source_object_type,source_object_id,source_version,event_kind),
 FOREIGN KEY(tenant_id,project_id,connector_id,source_instance_id) REFERENCES public.ingestion_connectors(tenant_id,project_id,id,source_instance_id),
 CHECK(source_object_type='ticket'), CHECK(event_kind='ticket.snapshot'),
 CHECK(length(btrim(source_object_id)) BETWEEN 1 AND 200 AND length(btrim(source_version)) BETWEEN 1 AND 200),
 CHECK(octet_length(payload_bytes) BETWEEN 1 AND 65536), CHECK(jsonb_typeof(payload_json)='object'),
 CHECK(transport_hash ~ '^[0-9a-f]{64}$' AND canonical_hash ~ '^[0-9a-f]{64}$')
);
CREATE TABLE public.ingestion_outbox (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, receipt_id uuid NOT NULL,
 status varchar(20) NOT NULL DEFAULT 'pending', attempt_count integer NOT NULL DEFAULT 0, available_at timestamptz(6) NOT NULL DEFAULT now(),
 lease_token uuid, lease_until timestamptz(6), completed_at timestamptz(6), reason_code varchar(40),
 UNIQUE(tenant_id,receipt_id), FOREIGN KEY(tenant_id,project_id,receipt_id) REFERENCES public.ingestion_receipts(tenant_id,project_id,id),
 CHECK(status IN ('pending','processing','retry','completed','dead_letter','blocked')),
 CHECK(attempt_count BETWEEN 0 AND 5),
 CHECK((status='processing' AND lease_token IS NOT NULL AND lease_until IS NOT NULL) OR (status<>'processing' AND lease_token IS NULL AND lease_until IS NULL)),
 CHECK((status='completed')=(completed_at IS NOT NULL)),
 CHECK(reason_code IS NULL OR reason_code IN ('processing_failed','access_revoked','connector_disabled','identity_unresolved','ordering_conflict'))
);
CREATE TABLE public.ingestion_projections (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, connector_id uuid NOT NULL,
 source_instance_id uuid NOT NULL, source_object_type varchar(30) NOT NULL DEFAULT 'ticket', source_object_id varchar(200) NOT NULL,
 current_receipt_id uuid NOT NULL, source_updated_at timestamptz(6), state jsonb NOT NULL, coverage_flags jsonb NOT NULL,
 UNIQUE(tenant_id,connector_id,source_object_id),
 FOREIGN KEY(tenant_id,project_id,connector_id,source_instance_id,source_object_type,source_object_id,current_receipt_id)
 REFERENCES public.ingestion_receipts(tenant_id,project_id,connector_id,source_instance_id,source_object_type,source_object_id,id),
 CHECK(source_object_type='ticket'), CHECK(jsonb_typeof(state)='object'), CHECK(jsonb_typeof(coverage_flags)='array')
);
CREATE FUNCTION public.guard_ingestion_keys() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='ingestion_connectors' THEN
  IF (NEW.id,NEW.tenant_id,NEW.project_id,NEW.source_instance_id,NEW.adapter,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.project_id,OLD.source_instance_id,OLD.adapter,OLD.created_at) THEN
   RAISE EXCEPTION 'Connector keys are immutable' USING ERRCODE='42501';
  END IF;
  IF NEW.revision <> OLD.revision+1 THEN RAISE EXCEPTION 'Connector revision must advance' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='ingestion_outbox' THEN
  IF (NEW.id,NEW.tenant_id,NEW.project_id,NEW.receipt_id) IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.project_id,OLD.receipt_id) THEN
   RAISE EXCEPTION 'Outbox keys are immutable' USING ERRCODE='42501';
  END IF;
 ELSE
  IF (NEW.id,NEW.tenant_id,NEW.project_id,NEW.connector_id,NEW.source_instance_id,NEW.source_object_type,NEW.source_object_id) IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.project_id,OLD.connector_id,OLD.source_instance_id,OLD.source_object_type,OLD.source_object_id) THEN
   RAISE EXCEPTION 'Projection keys are immutable' USING ERRCODE='42501';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER ingestion_receipt_immutable BEFORE UPDATE OR DELETE ON public.ingestion_receipts FOR EACH ROW EXECUTE FUNCTION public.reject_audit_mutation();
SQL);
        foreach (['ingestion_connectors', 'ingestion_receipts', 'ingestion_outbox', 'ingestion_projections'] as $table) {
            DB::statement("ALTER TABLE public.$table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE public.$table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON public.$table USING (tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK (tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
            DB::statement("REVOKE DELETE, TRUNCATE ON public.$table FROM monitoring_ai_app");
            if ($table !== 'ingestion_receipts') {
                DB::statement("CREATE TRIGGER ingestion_keys_immutable BEFORE UPDATE ON public.$table FOR EACH ROW EXECUTE FUNCTION public.guard_ingestion_keys()");
            }
        }
        DB::statement('REVOKE UPDATE ON public.ingestion_receipts FROM monitoring_ai_app');
    }

    public function down(): void
    {
        foreach (['ingestion_projections', 'ingestion_outbox', 'ingestion_receipts', 'ingestion_connectors'] as $table) {
            DB::statement("DROP TABLE IF EXISTS public.$table");
        }
        DB::statement('DROP FUNCTION IF EXISTS public.guard_ingestion_keys()');
    }
};
