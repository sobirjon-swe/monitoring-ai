<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This R1 strengthening migration never fabricates lineage for persisted approvals.
        DB::statement('ALTER TABLE requirement_version_criteria ADD COLUMN requirement_id uuid NOT NULL');
        DB::statement('ALTER TABLE requirement_versions ADD CONSTRAINT requirement_version_identity_unique UNIQUE(tenant_id,project_id,requirement_id,id)');
        DB::statement('ALTER TABLE requirement_version_criteria ADD CONSTRAINT requirement_join_version_identity_fk FOREIGN KEY(tenant_id,project_id,requirement_id,requirement_version_id) REFERENCES requirement_versions(tenant_id,project_id,requirement_id,id)');
        DB::statement('ALTER TABLE requirement_version_criteria ADD CONSTRAINT requirement_join_criterion_identity_fk FOREIGN KEY(tenant_id,project_id,requirement_id,criterion_id) REFERENCES criteria(tenant_id,project_id,requirement_id,id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE requirement_version_criteria DROP COLUMN requirement_id');
        DB::statement('ALTER TABLE requirement_versions DROP CONSTRAINT requirement_version_identity_unique');
    }
};
