<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE ba_events ADD COLUMN subject_answer_version_id uuid;
ALTER TABLE ba_events ADD CONSTRAINT ba_event_subject_answer_fk FOREIGN KEY(tenant_id,project_id,clarification_id,subject_answer_version_id) REFERENCES ba_answer_versions(tenant_id,project_id,clarification_id,id);
ALTER TABLE ba_events ADD CONSTRAINT ba_event_subject_answer_kind CHECK(subject_answer_version_id IS NULL OR kind IN ('answer.record','answer.correct','answer.retract'));
CREATE FUNCTION guard_ba_answer_event_subject() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE subject record; projected record; root record; head record; request record;
BEGIN
 IF NEW.kind NOT IN ('answer.record','answer.correct','answer.retract') THEN RETURN NEW; END IF;
 SELECT * INTO subject FROM ba_answer_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND clarification_id=NEW.clarification_id AND id=coalesce(NEW.subject_answer_version_id,NEW.answer_version_id);
 SELECT * INTO request FROM ba_request_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND clarification_id=NEW.clarification_id AND id=NEW.request_version_id;
 IF subject.id IS NULL OR request.id IS NULL OR (subject.receipt_id,subject.request_version_id,subject.request_hash,subject.links_hash,subject.operation) IS DISTINCT FROM (NEW.receipt_id,NEW.request_version_id,request.request_hash,request.links_hash,NEW.kind) THEN RAISE EXCEPTION 'BA answer event subject boundary' USING ERRCODE='23514'; END IF;
 IF subject.id IS DISTINCT FROM NEW.answer_version_id THEN
  SELECT * INTO projected FROM ba_answer_versions WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND clarification_id=NEW.clarification_id AND id=NEW.answer_version_id;
  SELECT * INTO root FROM ba_clarifications WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND id=NEW.clarification_id;
  SELECT * INTO head FROM ba_source_heads WHERE tenant_id=NEW.tenant_id AND project_id=NEW.project_id AND source_instance_id=subject.source_instance_id AND family='answer' AND object_id=subject.object_id;
  IF projected.id IS NULL OR head.id IS NULL OR root.id IS NULL OR subject.source_revision>=projected.source_revision OR (subject.source_instance_id,subject.object_id,subject.request_version_id) IS DISTINCT FROM (projected.source_instance_id,projected.object_id,projected.request_version_id) OR head.receipt_id IS DISTINCT FROM projected.receipt_id OR (NEW.state,NEW.request_version_id,NEW.answer_version_id,NEW.review_id,NEW.revision) IS DISTINCT FROM (root.state,root.current_request_id,root.current_answer_id,root.current_review_id,root.revision+1) THEN RAISE EXCEPTION 'BA late answer cannot replace projection' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER ba_answer_subject_guard BEFORE INSERT ON ba_events FOR EACH ROW EXECUTE FUNCTION guard_ba_answer_event_subject();
SQL);
        // Preserve every existing guard and fail closed if its exact predecessor changed.
        $this->replace('guard_ba_receipt_boundary', "id=NEW.answer_version_id;\n   IF fact.receipt_id", "id=coalesce(NEW.subject_answer_version_id,NEW.answer_version_id);\n   IF fact.receipt_id");
        $this->replace('guard_ba_lineage', 'NEW.answer_version_id IS DISTINCT FROM e.answer_version_id', 'NEW.answer_version_id IS DISTINCT FROM coalesce(e.subject_answer_version_id,e.answer_version_id)');
        $this->replace('guard_ba_fact', "IF NEW.kind<>r.operation OR (NEW.kind='review.confirm'", "IF (NEW.subject_answer_version_id IS NULL OR NEW.subject_answer_version_id IS NOT DISTINCT FROM NEW.answer_version_id) AND (NEW.kind<>r.operation OR (NEW.kind='review.confirm'", ["(NEW.kind='request.withdraw' AND NEW.state<>'withdrawn') THEN RAISE EXCEPTION 'BA event type/state mismatch'", "(NEW.kind='request.withdraw' AND NEW.state<>'withdrawn')) THEN RAISE EXCEPTION 'BA event type/state mismatch'"]);
    }

    private function replace(string $function, string $old, string $new, ?array $second = null): void
    {
        $definition = DB::scalar('select pg_get_functiondef(?::regproc)', [$function]);
        if (substr_count($definition, $old) !== 1) {
            throw new LogicException('Unexpected BA guard predecessor.');
        }
        $definition = str_replace($old, $new, $definition);
        if ($second !== null) {
            if (substr_count($definition, $second[0]) !== 1) {
                throw new LogicException('Unexpected BA guard predecessor.');
            }
            $definition = str_replace($second[0], $second[1], $definition);
        }
        DB::unprepared($definition);
    }

    public function down(): void
    {
        throw new LogicException('Immutable BA event subjects must be preserved.');
    }
};
