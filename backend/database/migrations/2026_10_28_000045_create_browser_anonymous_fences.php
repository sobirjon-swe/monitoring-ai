<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE public.browser_anonymous_fences (
 session_hash char(64) PRIMARY KEY CHECK (session_hash ~ '^[0-9a-f]{64}$'),
 key_version integer NOT NULL CHECK (key_version = 1),
 expires_at timestamptz(6) NOT NULL,
 revoked_at timestamptz(6),
 successor_hash char(64) CHECK (successor_hash IS NULL OR successor_hash ~ '^[0-9a-f]{64}$')
)
SQL);
        DB::statement('ALTER TABLE public.browser_anonymous_fences ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.browser_anonymous_fences FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY session_isolation ON public.browser_anonymous_fences USING (session_hash = nullif(current_setting('app.browser_session_hash', true), '')) WITH CHECK (session_hash = nullif(current_setting('app.browser_session_hash', true), ''))");
        DB::statement('REVOKE ALL ON public.browser_anonymous_fences FROM PUBLIC, monitoring_ai_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON public.browser_anonymous_fences TO monitoring_ai_app');
    }

    public function down(): void
    {
        throw new LogicException('Session fencing data must be preserved.');
    }
};
