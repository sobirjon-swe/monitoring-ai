<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve already retained synthetic history: absent old authority can never be inferred or favorable.
        DB::statement('ALTER TABLE human_acceptance_decisions ADD COLUMN authority jsonb');
        DB::statement('ALTER TABLE human_acceptance_decisions ADD COLUMN authority_hash varchar(64)');
        DB::statement("ALTER TABLE human_acceptance_decisions ADD CONSTRAINT acceptance_authority_required CHECK(authority IS NOT NULL AND jsonb_typeof(authority)='object' AND authority_hash IS NOT NULL AND authority_hash ~ '^[0-9a-f]{64}$') NOT VALID");
    }

    public function down(): void
    {
        throw new LogicException('Immutable decision authority must be preserved.');
    }
};
