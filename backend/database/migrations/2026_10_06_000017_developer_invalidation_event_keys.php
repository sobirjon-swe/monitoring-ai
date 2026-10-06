<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE developer_invalidations ADD COLUMN event_key varchar(64) CHECK(event_key IS NULL OR event_key ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE developer_invalidations ADD UNIQUE(tenant_id,project_id,event_key)');
    }

    public function down(): void
    {
        throw new LogicException('Immutable developer invalidation history must be preserved.');
    }
};
