<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COLUMNS = [
        'requirement_versions' => 'approved_at',
        'dod_policy_versions' => 'approved_at',
        'lifecycle_changes' => 'occurred_at',
        'baselines' => 'effective_at',
        'human_acceptance_decisions' => 'decided_at',
        'baseline_progress_snapshots' => 'as_of',
    ];

    public function up(): void
    {
        // Only prospective defaults change; retained immutable history is never rewritten.
        foreach (self::COLUMNS as $table => $column) {
            DB::statement("ALTER TABLE $table ALTER COLUMN $column SET DEFAULT clock_timestamp()");
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            DB::statement("ALTER TABLE $table ALTER COLUMN $column SET DEFAULT now()");
        }
    }
};
