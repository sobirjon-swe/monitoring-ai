<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE lifecycle_project_state (
 tenant_id uuid NOT NULL, project_id uuid NOT NULL, generation bigint NOT NULL DEFAULT 0 CHECK(generation>=0),
 current_baseline_id uuid, current_dod_policy_id uuid, is_closed boolean NOT NULL DEFAULT false,
 PRIMARY KEY(tenant_id,project_id), FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id)
);
CREATE TABLE requirements (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, created_at timestamptz(6) NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,project_id,id), FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id)
);
CREATE TABLE requirement_drafts (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, requirement_id uuid NOT NULL,
 revision integer NOT NULL DEFAULT 1 CHECK(revision>0), content jsonb NOT NULL CHECK(jsonb_typeof(content)='object'),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,requirement_id),
 FOREIGN KEY(tenant_id,project_id,requirement_id) REFERENCES requirements(tenant_id,project_id,id)
);
CREATE TABLE requirement_versions (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, requirement_id uuid NOT NULL,
 version integer NOT NULL CHECK(version>0), draft_revision integer NOT NULL CHECK(draft_revision>0), content jsonb NOT NULL CHECK(jsonb_typeof(content)='object'),
 content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'), approved_by uuid NOT NULL, approved_at timestamptz(6) NOT NULL DEFAULT now(), reason varchar(500) NOT NULL CHECK(length(btrim(reason))>0),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,requirement_id,version), UNIQUE(tenant_id,project_id,requirement_id,draft_revision),
 FOREIGN KEY(tenant_id,project_id,requirement_id) REFERENCES requirements(tenant_id,project_id,id), FOREIGN KEY(tenant_id,approved_by) REFERENCES users(tenant_id,id)
);
CREATE TABLE criteria (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, requirement_id uuid NOT NULL, key varchar(100) NOT NULL CHECK(length(btrim(key))>0),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,requirement_id,key), UNIQUE(tenant_id,project_id,requirement_id,id),
 FOREIGN KEY(tenant_id,project_id,requirement_id) REFERENCES requirements(tenant_id,project_id,id)
);
CREATE TABLE criterion_versions (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, criterion_id uuid NOT NULL, version integer NOT NULL CHECK(version>0),
 text varchar(4000) NOT NULL CHECK(length(btrim(text))>0), required_test_keys jsonb NOT NULL CHECK(jsonb_typeof(required_test_keys)='array' AND jsonb_array_length(required_test_keys) BETWEEN 1 AND 20),
 context_hash varchar(64) NOT NULL CHECK(context_hash ~ '^[0-9a-f]{64}$'), content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,criterion_id,id), UNIQUE(tenant_id,project_id,criterion_id,version),
 FOREIGN KEY(tenant_id,project_id,criterion_id) REFERENCES criteria(tenant_id,project_id,id)
);
CREATE TABLE requirement_version_criteria (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, requirement_version_id uuid NOT NULL, criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL,
 UNIQUE(tenant_id,project_id,requirement_version_id,criterion_id),
 FOREIGN KEY(tenant_id,project_id,requirement_version_id) REFERENCES requirement_versions(tenant_id,project_id,id),
 FOREIGN KEY(tenant_id,project_id,criterion_id,criterion_version_id) REFERENCES criterion_versions(tenant_id,project_id,criterion_id,id)
);
CREATE TABLE dod_policy_versions (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, version integer NOT NULL CHECK(version>0), policy jsonb NOT NULL,
 content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'), approved_by uuid NOT NULL, approved_at timestamptz(6) NOT NULL DEFAULT now(),
 CHECK(policy='{"mandatory_qa":true,"human_acceptance":true,"fictional":true}'::jsonb),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,version), FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id), FOREIGN KEY(tenant_id,approved_by) REFERENCES users(tenant_id,id)
);
ALTER TABLE lifecycle_project_state ADD FOREIGN KEY(tenant_id,project_id,current_dod_policy_id) REFERENCES dod_policy_versions(tenant_id,project_id,id);
CREATE TABLE lifecycle_changes (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, subject_kind varchar(40) NOT NULL, subject_id uuid NOT NULL,
 input_version varchar(100) NOT NULL, reason varchar(500) NOT NULL, actor_id uuid NOT NULL, authorizing_purpose varchar(80) NOT NULL, generation bigint NOT NULL CHECK(generation>0),
 idempotency_key varchar(64) NOT NULL CHECK(idempotency_key ~ '^[0-9a-f]{64}$'), occurred_at timestamptz(6) NOT NULL DEFAULT now(),
 CHECK(subject_kind IN ('criterion_replaced','criterion_removed','policy_activated','baseline_activated','evidence_changed','qa_changed','acceptance_changed','project_closed')),
 CHECK(length(btrim(input_version))>0 AND length(btrim(reason))>0),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,idempotency_key), FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id), FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
CREATE TABLE lifecycle_outbox (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, change_id uuid NOT NULL,
 status varchar(20) NOT NULL DEFAULT 'pending', attempt_count integer NOT NULL DEFAULT 0 CHECK(attempt_count BETWEEN 0 AND 5), available_at timestamptz(6) NOT NULL DEFAULT now(),
 lease_token uuid, lease_until timestamptz(6), completed_at timestamptz(6), reason_code varchar(40),
 UNIQUE(tenant_id,project_id,id), UNIQUE(change_id), FOREIGN KEY(tenant_id,project_id,change_id) REFERENCES lifecycle_changes(tenant_id,project_id,id),
 CHECK(status IN ('pending','processing','retry','completed','dead_letter','blocked')),
 CHECK((status='processing' AND lease_token IS NOT NULL AND lease_until IS NOT NULL) OR (status<>'processing' AND lease_token IS NULL AND lease_until IS NULL)),
 CHECK((status='completed')=(completed_at IS NOT NULL)), CHECK(reason_code IS NULL OR reason_code IN ('processing_failed','access_revoked','stale_generation','input_unavailable'))
);
CREATE FUNCTION guard_lifecycle_keys() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (to_jsonb(NEW)->'tenant_id',to_jsonb(NEW)->'project_id',to_jsonb(NEW)->'id',to_jsonb(NEW)->'requirement_id',to_jsonb(NEW)->'change_id') IS DISTINCT FROM
 (to_jsonb(OLD)->'tenant_id',to_jsonb(OLD)->'project_id',to_jsonb(OLD)->'id',to_jsonb(OLD)->'requirement_id',to_jsonb(OLD)->'change_id') THEN RAISE EXCEPTION 'Lifecycle keys are immutable' USING ERRCODE='42501'; END IF;
 IF TG_TABLE_NAME='requirement_drafts' THEN IF NEW.revision<>OLD.revision+1 THEN RAISE EXCEPTION 'Draft revision must advance' USING ERRCODE='23514'; END IF; END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['lifecycle_project_state', 'requirements', 'requirement_drafts', 'requirement_versions', 'criteria', 'criterion_versions', 'requirement_version_criteria', 'dod_policy_versions', 'lifecycle_changes', 'lifecycle_outbox'] as $t) {
            DB::statement("ALTER TABLE $t ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $t FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $t USING(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
            DB::statement("REVOKE DELETE,TRUNCATE ON $t FROM monitoring_ai_app");
            if (in_array($t, ['lifecycle_project_state', 'requirement_drafts', 'lifecycle_outbox'])) {
                DB::statement("CREATE TRIGGER lifecycle_keys_immutable BEFORE UPDATE ON $t FOR EACH ROW EXECUTE FUNCTION guard_lifecycle_keys()");
            } else {
                DB::statement("REVOKE UPDATE ON $t FROM monitoring_ai_app");
                DB::statement("CREATE TRIGGER lifecycle_immutable BEFORE UPDATE OR DELETE ON $t FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            }
        }
    }

    public function down(): void
    {
        foreach (['lifecycle_outbox', 'lifecycle_changes', 'requirement_version_criteria', 'criterion_versions', 'criteria', 'requirement_versions', 'requirement_drafts', 'requirements', 'lifecycle_project_state', 'dod_policy_versions'] as $t) {
            DB::statement("DROP TABLE $t");
        } DB::statement('DROP FUNCTION guard_lifecycle_keys()');
    }
};
