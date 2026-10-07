<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE qa_changes ADD COLUMN lifecycle_mutation_id uuid, ADD COLUMN task_mutation_version_id uuid;
ALTER TABLE qa_changes ADD FOREIGN KEY(tenant_id,project_id,lifecycle_mutation_id) REFERENCES lifecycle_changes(tenant_id,project_id,id);
ALTER TABLE qa_changes ADD FOREIGN KEY(tenant_id,project_id,task_mutation_version_id) REFERENCES task_versions(tenant_id,project_id,id);
DO $$ DECLARE c record; BEGIN
 FOR c IN SELECT conname FROM pg_constraint WHERE conrelid='qa_changes'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%authority_proof%' LOOP
  EXECUTE format('ALTER TABLE qa_changes DROP CONSTRAINT %I',c.conname);
 END LOOP;
END $$;
ALTER TABLE qa_changes ADD CONSTRAINT qa_change_origin CHECK(
 (origin='qa' AND authority_proof IS NOT NULL AND authority_proof->>'version'='qa-authority-v1' AND num_nonnulls(lifecycle_mutation_id,task_mutation_version_id,criterion_version_id,task_version_id,baseline_id,lifecycle_change_id)=0)
 OR (origin='lifecycle' AND authority_proof IS NULL AND num_nonnulls(lifecycle_mutation_id,task_mutation_version_id)=1 AND num_nonnulls(criterion_version_id,task_version_id,baseline_id,lifecycle_change_id)=1)
);
CREATE OR REPLACE FUNCTION guard_qa_proof() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE proof jsonb; grant_row jsonb; row_json jsonb;
BEGIN
 row_json=to_jsonb(NEW); proof=row_json->'authority_proof';
 IF TG_TABLE_NAME='qa_changes' AND row_json->>'origin'='lifecycle' THEN RETURN NEW; END IF;
 IF jsonb_typeof(proof) IS DISTINCT FROM 'object' OR proof->>'version' IS DISTINCT FROM 'qa-authority-v1' OR proof->>'tenant_id' IS DISTINCT FROM NEW.tenant_id::text OR proof->>'project_id' IS DISTINCT FROM NEW.project_id::text OR proof->>'actor_id' IS DISTINCT FROM NEW.actor_id::text
  OR jsonb_typeof(proof->'membership') IS DISTINCT FROM 'object' OR jsonb_typeof(proof->'grants') IS DISTINCT FROM 'array' OR jsonb_array_length(proof->'grants') NOT BETWEEN 1 AND 9 OR jsonb_typeof(proof->'purposes') IS DISTINCT FROM 'array'
 THEN RAISE EXCEPTION 'Invalid original QA proof' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM users u JOIN project_memberships m ON m.tenant_id=u.tenant_id AND m.employee_id=u.employee_id AND m.project_id=NEW.project_id WHERE u.tenant_id=NEW.tenant_id AND u.id=NEW.actor_id AND u.status='active' AND m.id::text=proof->'membership'->>'id' AND m.revision::text=proof->'membership'->>'revision' AND m.valid_from<=clock_timestamp() AND (m.valid_to IS NULL OR m.valid_to>clock_timestamp())) THEN RAISE EXCEPTION 'Invalid original membership' USING ERRCODE='23503'; END IF;
 FOR grant_row IN SELECT value FROM jsonb_array_elements(proof->'grants') LOOP
  IF NOT EXISTS(SELECT 1 FROM access_grants g JOIN policy_versions p ON p.tenant_id=g.tenant_id AND p.id=g.policy_version_id WHERE g.tenant_id=NEW.tenant_id AND g.user_id=NEW.actor_id AND g.id::text=grant_row->>'id' AND g.revision::text=grant_row->>'revision' AND g.purpose=grant_row->>'purpose' AND g.policy_version_id::text=grant_row->>'policy_version_id' AND p.version::text=grant_row->>'policy_version' AND p.status='active' AND g.revoked_at IS NULL AND g.valid_from<=clock_timestamp() AND (g.valid_to IS NULL OR g.valid_to>clock_timestamp()) AND ((g.scope_type='project' AND g.project_id=NEW.project_id AND g.purpose IN ('qa.fixture.manage','qa.plan.manage','qa.execution.record','qa.defect.review','qa.handoff.manage','qa.read','qa.source.read','qa.process')) OR (g.scope_type='department' AND g.department_id::text=proof->>'department_id' AND g.purpose='qa.department.read'))) THEN RAISE EXCEPTION 'Invalid original grant' USING ERRCODE='23503'; END IF;
 END LOOP;
 RETURN NEW;
END $$;
CREATE FUNCTION guard_qa_lifecycle_origin() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE binding jsonb; mutation lifecycle_changes; origin_task task_versions; affected_task task_versions; state lifecycle_project_state;
BEGIN
 IF NEW.origin='qa' THEN
  IF NEW.lifecycle_mutation_id IS NOT NULL OR NEW.task_mutation_version_id IS NOT NULL THEN RAISE EXCEPTION 'QA origin cannot fabricate lifecycle proof' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.authority_proof IS NOT NULL OR num_nonnulls(NEW.lifecycle_mutation_id,NEW.task_mutation_version_id)<>1 THEN RAISE EXCEPTION 'Lifecycle origin requires one mutation' USING ERRCODE='23514'; END IF;
 IF NEW.lifecycle_mutation_id IS NOT NULL THEN
  SELECT * INTO mutation FROM lifecycle_changes WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.lifecycle_mutation_id AND actor_id=NEW.actor_id;
  IF NOT FOUND THEN RAISE EXCEPTION 'Mutation scope and actor must match' USING ERRCODE='23503'; END IF;
 ELSE
  SELECT * INTO origin_task FROM task_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.task_mutation_version_id AND actor_id=NEW.actor_id;
  IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM lifecycle_tasks WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=origin_task.task_id AND current_version_id=origin_task.id AND revision=origin_task.revision) THEN RAISE EXCEPTION 'Task origin must match current inserted version and actor' USING ERRCODE='23503'; END IF;
  SELECT * INTO affected_task FROM task_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.task_version_id AND task_id=origin_task.task_id;
  IF NOT FOUND THEN RAISE EXCEPTION 'Affected task must match stable origin task' USING ERRCODE='23503'; END IF;
 END IF;
 binding=nullif(current_setting('app.lifecycle_mutation',true),'')::jsonb;
 SELECT * INTO state FROM lifecycle_project_state WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id;
 IF binding IS NULL OR jsonb_typeof(binding) IS DISTINCT FROM 'array' OR jsonb_array_length(binding)<>5 OR binding->>0 IS DISTINCT FROM NEW.tenant_id::text OR binding->>1 IS DISTINCT FROM NEW.project_id::text OR binding->>2 IS DISTINCT FROM NEW.actor_id::text OR (binding->>4)::bigint IS DISTINCT FROM state.generation THEN RAISE EXCEPTION 'Lifecycle origin requires live mutation binding' USING ERRCODE='23514'; END IF;
 IF NEW.lifecycle_mutation_id IS NOT NULL THEN
  IF binding->>3 IS DISTINCT FROM mutation.authorizing_purpose OR mutation.generation<>state.generation+1 THEN RAISE EXCEPTION 'Lifecycle purpose and generation must match' USING ERRCODE='23514'; END IF;
  IF NEW.criterion_version_id IS NOT NULL THEN
   IF mutation.subject_kind NOT IN ('criterion_replaced','criterion_removed') OR NOT EXISTS(SELECT 1 FROM criterion_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.criterion_version_id AND criterion_id=mutation.subject_id) THEN RAISE EXCEPTION 'Criterion subject must match mutation' USING ERRCODE='23503'; END IF;
  ELSIF NEW.baseline_id IS NOT NULL THEN
   IF mutation.subject_kind NOT IN ('baseline_activated','project_closed') OR NEW.baseline_id<>mutation.subject_id THEN RAISE EXCEPTION 'Baseline subject must match mutation' USING ERRCODE='23503'; END IF;
  ELSIF NEW.lifecycle_change_id IS DISTINCT FROM NEW.lifecycle_mutation_id THEN RAISE EXCEPTION 'Lifecycle subject must match mutation' USING ERRCODE='23503';
  END IF;
 ELSE
  IF binding->>3 IS DISTINCT FROM 'requirements.manage' OR origin_task.effective_at<transaction_timestamp() THEN RAISE EXCEPTION 'Task origin requires newly inserted managed version' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER qa_lifecycle_origin BEFORE INSERT ON qa_changes FOR EACH ROW EXECUTE FUNCTION guard_qa_lifecycle_origin();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Immutable QA lifecycle origins must be preserved.');
    }
};
