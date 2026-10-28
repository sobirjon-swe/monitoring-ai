<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.browser_attempt_cleanup_cursors ADD COLUMN through_id uuid');
    }

    public function down(): void
    {
        throw new LogicException('Cleanup cursor data must be preserved.');
    }
};
