<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE public.browser_session_fences (
 tenant_id uuid NOT NULL,
 session_hash char(64) NOT NULL CHECK (session_hash ~ '^[0-9a-f]{64}$'),
 user_id uuid NOT NULL,
 expires_at timestamptz(6) NOT NULL,
 revoked_at timestamptz(6),
 PRIMARY KEY (tenant_id, session_hash),
 FOREIGN KEY (tenant_id, user_id) REFERENCES public.users(tenant_id, id)
)
SQL);
        DB::statement('ALTER TABLE public.browser_session_fences ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.browser_session_fences FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON public.browser_session_fences USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
        DB::statement('GRANT SELECT, INSERT, UPDATE ON public.browser_session_fences TO monitoring_ai_app');
    }

    public function down(): void
    {
        throw new LogicException('Session fencing data must be preserved.');
    }
};
