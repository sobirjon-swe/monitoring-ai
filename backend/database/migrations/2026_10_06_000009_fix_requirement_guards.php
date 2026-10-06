<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_lifecycle_keys() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (to_jsonb(NEW)->'tenant_id',to_jsonb(NEW)->'project_id',to_jsonb(NEW)->'id',to_jsonb(NEW)->'requirement_id',to_jsonb(NEW)->'change_id') IS DISTINCT FROM
 (to_jsonb(OLD)->'tenant_id',to_jsonb(OLD)->'project_id',to_jsonb(OLD)->'id',to_jsonb(OLD)->'requirement_id',to_jsonb(OLD)->'change_id') THEN RAISE EXCEPTION 'Lifecycle keys are immutable' USING ERRCODE='42501'; END IF;
 IF TG_TABLE_NAME='requirement_drafts' THEN
  IF NEW.revision<>OLD.revision+1 THEN RAISE EXCEPTION 'Draft revision must advance' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    { /* The corrected guard remains valid for the preceding schema. */
    }
};
