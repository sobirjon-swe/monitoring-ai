<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.browser_session_fences ADD CONSTRAINT browser_fence_actor_unique UNIQUE (tenant_id, session_hash, user_id)');
        DB::statement('ALTER TABLE public.browser_ba_attempts ADD CONSTRAINT browser_attempt_fence_actor_fk FOREIGN KEY (tenant_id, session_hash, actor_id) REFERENCES public.browser_session_fences (tenant_id, session_hash, user_id)');
    }

    public function down(): void
    {
        throw new LogicException('Attempt actor boundaries must be preserved.');
    }
};
