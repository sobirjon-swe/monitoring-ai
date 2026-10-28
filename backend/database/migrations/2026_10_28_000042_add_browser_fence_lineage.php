<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.browser_session_fences ADD COLUMN generation bigint NOT NULL DEFAULT 0 CHECK (generation >= 0), ADD COLUMN successor_hash char(64)');
        DB::statement('ALTER TABLE public.browser_session_fences ADD CONSTRAINT browser_fence_successor_fk FOREIGN KEY (tenant_id, successor_hash) REFERENCES public.browser_session_fences(tenant_id, session_hash)');
    }

    public function down(): void
    {
        throw new LogicException('Session fencing data must be preserved.');
    }
};
