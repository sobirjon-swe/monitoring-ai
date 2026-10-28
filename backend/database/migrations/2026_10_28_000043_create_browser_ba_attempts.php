<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE public.browser_ba_attempts (
 id uuid PRIMARY KEY,
 tenant_id uuid NOT NULL,
 project_id uuid NOT NULL,
 clarification_id uuid NOT NULL,
 actor_id uuid NOT NULL,
 session_hash char(64) NOT NULL,
 state varchar(16) NOT NULL CHECK (state IN ('claimed', 'completed', 'tombstone', 'closed')),
 ciphertext text,
 result_code varchar(32),
 expires_at timestamptz(6) NOT NULL,
 created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 CHECK ((state IN ('claimed', 'completed') AND ciphertext IS NOT NULL) OR (state IN ('tombstone', 'closed') AND ciphertext IS NULL)),
 UNIQUE (tenant_id, project_id, id),
 FOREIGN KEY (tenant_id, project_id, clarification_id) REFERENCES public.ba_clarifications(tenant_id, project_id, id),
 FOREIGN KEY (tenant_id, actor_id) REFERENCES public.users(tenant_id, id),
 FOREIGN KEY (tenant_id, session_hash) REFERENCES public.browser_session_fences(tenant_id, session_hash)
)
SQL);
        DB::statement("CREATE UNIQUE INDEX browser_ba_active_slot ON public.browser_ba_attempts (tenant_id, session_hash, project_id, clarification_id) WHERE state <> 'closed'");
        DB::statement('ALTER TABLE public.browser_ba_attempts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.browser_ba_attempts FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON public.browser_ba_attempts USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
        DB::statement('GRANT SELECT, INSERT, UPDATE ON public.browser_ba_attempts TO monitoring_ai_app');
    }

    public function down(): void
    {
        throw new LogicException('Attempt coordination data must be preserved.');
    }
};
