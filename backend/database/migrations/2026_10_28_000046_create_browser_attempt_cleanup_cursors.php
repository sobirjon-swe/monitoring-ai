<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE public.browser_attempt_cleanup_cursors (tenant_id uuid PRIMARY KEY REFERENCES public.tenants(id), after_id uuid)');
        DB::statement('ALTER TABLE public.browser_attempt_cleanup_cursors ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.browser_attempt_cleanup_cursors FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON public.browser_attempt_cleanup_cursors USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
        DB::statement('REVOKE ALL ON public.browser_attempt_cleanup_cursors FROM PUBLIC, monitoring_ai_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON public.browser_attempt_cleanup_cursors TO monitoring_ai_app');
    }

    public function down(): void
    {
        throw new LogicException('Cleanup cursor data must be preserved.');
    }
};
