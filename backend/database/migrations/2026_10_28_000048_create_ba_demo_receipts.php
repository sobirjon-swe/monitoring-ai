<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX ba_demo_token_tenant_key ON public.auth_tokens (tenant_id, id)');
        DB::statement(<<<'SQL'
CREATE TABLE public.ba_demo_receipts (
 tenant_id uuid NOT NULL CHECK (tenant_id = 'f0200000-0000-4000-8000-000000000001'),
 project_id uuid NOT NULL CHECK (project_id = 'f0200000-0000-4000-8000-000000000002'),
 writer_id uuid NOT NULL CHECK (writer_id = 'f0200000-0000-4000-8000-000000000011'),
 producer_id uuid NOT NULL CHECK (producer_id = 'f0200000-0000-4000-8000-000000000021'),
 token_id uuid NOT NULL CHECK (token_id = 'f0200000-0000-4000-8000-000000000013'),
 receipt_version text NOT NULL CHECK (receipt_version = 'ba-demo-receipt-v1'),
 manifest_hash text NOT NULL CHECK (manifest_hash = '156a475518989fee0ba15d9fb9b210e75ea6be548af3adb9194e111394363371'),
 snapshot_hash text NOT NULL CHECK (snapshot_hash ~ '^[a-f0-9]{64}$'),
 secret_hash text NOT NULL CHECK (secret_hash ~ '^[a-f0-9]{64}$'),
 ciphertext_hash text NOT NULL CHECK (ciphertext_hash ~ '^[a-f0-9]{64}$'),
 volume_id text NOT NULL CHECK (volume_id ~ '^[a-f0-9]{8}$'),
 parent_file_id text NOT NULL CHECK (parent_file_id ~ '^[a-f0-9]{16}$'),
 file_id text NOT NULL CHECK (file_id ~ '^[a-f0-9]{16}$' AND file_id <> parent_file_id),
 expires_at timestamptz(6) NOT NULL,
 created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY (tenant_id, project_id),
 FOREIGN KEY (tenant_id, project_id) REFERENCES public.projects(tenant_id, id),
 FOREIGN KEY (tenant_id, writer_id) REFERENCES public.users(tenant_id, id),
 FOREIGN KEY (tenant_id, producer_id) REFERENCES public.users(tenant_id, id),
 FOREIGN KEY (tenant_id, token_id) REFERENCES public.auth_tokens(tenant_id, id)
)
SQL);
        DB::statement(<<<'SQL'
CREATE FUNCTION public.ba_demo_receipt_validate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM public.auth_tokens t JOIN public.auth_subjects s
   ON s.tenant_id = t.tenant_id AND s.id = t.auth_subject_id
  WHERE t.tenant_id = NEW.tenant_id AND t.id = NEW.token_id
   AND s.user_id = NEW.writer_id AND s.id = 'f0200000-0000-4000-8000-000000000012'
   AND t.secret_hash = NEW.secret_hash AND t.expires_at = NEW.expires_at
   AND t.revoked_at IS NULL AND s.revoked_at IS NULL AND t.expires_at > clock_timestamp()
 ) THEN RAISE EXCEPTION 'Synthetic BA receipt token mismatch'; END IF;
 RETURN NEW;
END
$$
SQL);
        DB::statement('CREATE TRIGGER ba_demo_receipt_validate BEFORE INSERT ON public.ba_demo_receipts FOR EACH ROW EXECUTE FUNCTION public.ba_demo_receipt_validate()');
        DB::statement('ALTER TABLE public.ba_demo_receipts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.ba_demo_receipts FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON public.ba_demo_receipts USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
        DB::statement('GRANT SELECT, INSERT ON public.ba_demo_receipts TO monitoring_ai_app');
    }

    public function down(): void
    {
        throw new LogicException('Synthetic BA receipts must be preserved.');
    }
};
