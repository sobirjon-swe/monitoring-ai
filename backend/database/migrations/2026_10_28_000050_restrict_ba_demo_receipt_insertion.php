<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('REVOKE INSERT ON public.ba_demo_receipts FROM monitoring_ai_app, PUBLIC');
    }

    public function down(): void
    {
        throw new LogicException('Synthetic BA receipt insertion is restricted.');
    }
};
