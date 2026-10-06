<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE baselines (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, policy_id uuid NOT NULL, previous_baseline_id uuid,
 content_hash varchar(64) NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'), approved_by uuid NOT NULL, reason varchar(500) NOT NULL CHECK(length(btrim(reason))>0),
 effective_at timestamptz(6) NOT NULL DEFAULT now(), is_reopen boolean NOT NULL DEFAULT false,
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,policy_id,id),
 FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id),
 FOREIGN KEY(tenant_id,project_id,policy_id) REFERENCES dod_policy_versions(tenant_id,project_id,id),
 FOREIGN KEY(tenant_id,project_id,previous_baseline_id) REFERENCES baselines(tenant_id,project_id,id),
 FOREIGN KEY(tenant_id,approved_by) REFERENCES users(tenant_id,id), CHECK(NOT is_reopen OR previous_baseline_id IS NOT NULL)
);
CREATE TABLE baseline_items (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, baseline_id uuid NOT NULL, policy_id uuid NOT NULL,
 stable_criterion_id uuid NOT NULL, criterion_version_id uuid NOT NULL, weight integer NOT NULL CHECK(weight BETWEEN 1 AND 1000000), carry_decision_id uuid,
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,baseline_id,stable_criterion_id),
 UNIQUE(tenant_id,project_id,baseline_id,policy_id,criterion_version_id,id),
 FOREIGN KEY(tenant_id,project_id,policy_id,baseline_id) REFERENCES baselines(tenant_id,project_id,policy_id,id),
 FOREIGN KEY(tenant_id,project_id,stable_criterion_id,criterion_version_id) REFERENCES criterion_versions(tenant_id,project_id,criterion_id,id)
);
CREATE TABLE human_acceptance_decisions (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, baseline_id uuid NOT NULL, item_id uuid NOT NULL,
 policy_id uuid NOT NULL, criterion_version_id uuid NOT NULL, pins jsonb NOT NULL CHECK(jsonb_typeof(pins)='object'),
 pins_hash varchar(64) NOT NULL CHECK(pins_hash ~ '^[0-9a-f]{64}$'), input_generation bigint NOT NULL CHECK(input_generation>=0),
 actor_id uuid NOT NULL, reason varchar(500) NOT NULL CHECK(length(btrim(reason))>0), decided_at timestamptz(6) NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,criterion_version_id,policy_id,id),
 FOREIGN KEY(tenant_id,project_id,baseline_id,policy_id,criterion_version_id,item_id) REFERENCES baseline_items(tenant_id,project_id,baseline_id,policy_id,criterion_version_id,id),
 FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id)
);
ALTER TABLE baseline_items ADD FOREIGN KEY(tenant_id,project_id,criterion_version_id,policy_id,carry_decision_id) REFERENCES human_acceptance_decisions(tenant_id,project_id,criterion_version_id,policy_id,id);
ALTER TABLE lifecycle_project_state ADD FOREIGN KEY(tenant_id,project_id,current_dod_policy_id,current_baseline_id) REFERENCES baselines(tenant_id,project_id,policy_id,id);
ALTER TABLE lifecycle_project_state ADD CHECK((current_baseline_id IS NULL)=(current_dod_policy_id IS NULL));
CREATE TABLE acceptance_applicability (
 tenant_id uuid NOT NULL, project_id uuid NOT NULL, decision_id uuid NOT NULL, status varchar(10) NOT NULL CHECK(status IN ('valid','stale','revoked')),
 input_generation bigint NOT NULL CHECK(input_generation>=0), pins_hash varchar(64) NOT NULL CHECK(pins_hash ~ '^[0-9a-f]{64}$'),
 PRIMARY KEY(tenant_id,project_id,decision_id), FOREIGN KEY(tenant_id,project_id,decision_id) REFERENCES human_acceptance_decisions(tenant_id,project_id,id)
);
CREATE TABLE baseline_progress_snapshots (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, baseline_id uuid NOT NULL, policy_id uuid NOT NULL,
 as_of timestamptz(6) NOT NULL DEFAULT now(), accepted_weight integer NOT NULL CHECK(accepted_weight>=0), total_weight integer NOT NULL CHECK(total_weight BETWEEN 0 AND 100000000),
 availability varchar(20) NOT NULL CHECK(availability IN ('available','partial','unavailable')), versions jsonb NOT NULL CHECK(jsonb_typeof(versions)='array'),
 coverage jsonb NOT NULL CHECK(jsonb_typeof(coverage)='array'), schedule_coverage jsonb NOT NULL CHECK(jsonb_typeof(schedule_coverage)='object'), closure_event_id uuid NOT NULL,
 UNIQUE(tenant_id,project_id,id), UNIQUE(tenant_id,project_id,baseline_id), CHECK(accepted_weight<=total_weight),
 FOREIGN KEY(tenant_id,project_id,policy_id,baseline_id) REFERENCES baselines(tenant_id,project_id,policy_id,id),
 FOREIGN KEY(tenant_id,project_id,closure_event_id) REFERENCES lifecycle_changes(tenant_id,project_id,id)
);
CREATE FUNCTION guard_acceptance_applicability() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.tenant_id,NEW.project_id,NEW.decision_id) IS DISTINCT FROM (OLD.tenant_id,OLD.project_id,OLD.decision_id) THEN RAISE EXCEPTION 'Immutable applicability identity' USING ERRCODE='42501'; END IF;
 IF NEW.input_generation<OLD.input_generation THEN RAISE EXCEPTION 'Applicability generation regressed' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['baselines', 'baseline_items', 'human_acceptance_decisions', 'baseline_progress_snapshots', 'acceptance_applicability'] as $t) {
            DB::statement("ALTER TABLE $t ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $t FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $t USING(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK(tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
            DB::statement("REVOKE DELETE,TRUNCATE ON $t FROM monitoring_ai_app");
            if ($t === 'acceptance_applicability') {
                DB::statement("CREATE TRIGGER applicability_guard BEFORE UPDATE ON $t FOR EACH ROW EXECUTE FUNCTION guard_acceptance_applicability()");
            } else {
                DB::statement("REVOKE UPDATE ON $t FROM monitoring_ai_app");
                DB::statement("CREATE TRIGGER lifecycle_immutable BEFORE UPDATE OR DELETE ON $t FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Approved lifecycle snapshots require an explicit retention procedure.');
    }
};
