<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_qa_proof() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE proof jsonb; grant_row jsonb; row_json jsonb;
BEGIN
 row_json=to_jsonb(NEW); proof=row_json->'authority_proof';
 IF TG_TABLE_NAME='qa_changes' AND row_json->>'origin'='lifecycle' THEN
  IF NOT EXISTS(SELECT 1 FROM lifecycle_changes l WHERE l.tenant_id=NEW.tenant_id AND l.project_id=NEW.project_id AND l.id=(row_json->>'lifecycle_change_id')::uuid AND l.actor_id=NEW.actor_id AND l.authorizing_purpose IN ('requirements.approve','baseline.approve','policy.approve','evidence.review','acceptance.review','lifecycle.process','requirements.manage')) THEN RAISE EXCEPTION 'Lifecycle change must bind trusted mutation' USING ERRCODE='23503'; END IF;
  RETURN NEW;
 END IF;
 IF jsonb_typeof(proof) IS DISTINCT FROM 'object' OR proof->>'version' IS DISTINCT FROM 'qa-authority-v1' OR proof->>'tenant_id' IS DISTINCT FROM NEW.tenant_id::text OR proof->>'project_id' IS DISTINCT FROM NEW.project_id::text OR proof->>'actor_id' IS DISTINCT FROM NEW.actor_id::text
  OR jsonb_typeof(proof->'membership') IS DISTINCT FROM 'object' OR jsonb_typeof(proof->'grants') IS DISTINCT FROM 'array' OR jsonb_array_length(proof->'grants') NOT BETWEEN 1 AND 9 OR jsonb_typeof(proof->'purposes') IS DISTINCT FROM 'array'
 THEN RAISE EXCEPTION 'Invalid original QA proof' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM users u JOIN project_memberships m ON m.tenant_id=u.tenant_id AND m.employee_id=u.employee_id AND m.project_id=NEW.project_id WHERE u.tenant_id=NEW.tenant_id AND u.id=NEW.actor_id AND u.status='active' AND m.id::text=proof->'membership'->>'id' AND m.revision::text=proof->'membership'->>'revision' AND m.valid_from<=clock_timestamp() AND (m.valid_to IS NULL OR m.valid_to>clock_timestamp())) THEN RAISE EXCEPTION 'Invalid original membership' USING ERRCODE='23503'; END IF;
 FOR grant_row IN SELECT value FROM jsonb_array_elements(proof->'grants') LOOP
  IF NOT EXISTS(SELECT 1 FROM access_grants g JOIN policy_versions p ON p.tenant_id=g.tenant_id AND p.id=g.policy_version_id WHERE g.tenant_id=NEW.tenant_id AND g.user_id=NEW.actor_id AND g.id::text=grant_row->>'id' AND g.revision::text=grant_row->>'revision' AND g.purpose=grant_row->>'purpose' AND g.policy_version_id::text=grant_row->>'policy_version_id' AND p.version::text=grant_row->>'policy_version' AND p.status='active' AND g.revoked_at IS NULL AND g.valid_from<=clock_timestamp() AND (g.valid_to IS NULL OR g.valid_to>clock_timestamp()) AND ((g.scope_type='project' AND g.project_id=NEW.project_id AND g.purpose IN ('qa.fixture.manage','qa.plan.manage','qa.execution.record','qa.defect.review','qa.handoff.manage','qa.read','qa.source.read','qa.process')) OR (g.scope_type='department' AND g.department_id::text=proof->>'department_id' AND g.purpose='qa.department.read'))) THEN RAISE EXCEPTION 'Invalid original grant' USING ERRCODE='23503'; END IF;
 END LOOP;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Immutable QA proof guards must be preserved.');
    }
};
