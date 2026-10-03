<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['tenants', 'employees', 'departments', 'department_memberships', 'reporting_relationships', 'audit_events', 'auth_subjects', 'auth_tokens', 'project_memberships', 'role_assignments', 'access_grants', 'identity_mappings', 'identity_mapping_histories'];
        foreach ($tables as $table) {
            $columns = DB::select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND data_type = 'timestamp with time zone' AND datetime_precision <> 6", [$table]);
            foreach ($columns as $column) {
                // Identifiers come only from this fixed table list and the database catalog.
                $name = str_replace('"', '""', $column->column_name);
                DB::statement('ALTER TABLE public."'.$table.'" ALTER COLUMN "'.$name.'" TYPE timestamp(6) with time zone');
            }
        }
    }

    public function down(): void
    {
        // Downgrading would irreversibly round newly preserved instants.
        throw new RuntimeException('Timestamp precision migration cannot be rolled back without data loss.');
    }
};
