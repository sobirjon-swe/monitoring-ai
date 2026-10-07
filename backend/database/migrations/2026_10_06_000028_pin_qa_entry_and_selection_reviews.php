<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE qa_reviews ADD CONSTRAINT qa_review_definition_identity UNIQUE(tenant_id,project_id,definition_id,id);
ALTER TABLE qa_reviews ADD CONSTRAINT qa_review_plan_identity UNIQUE(tenant_id,project_id,plan_id,id);
ALTER TABLE qa_plan_entry_definitions ADD COLUMN definition_review_id uuid NOT NULL;
ALTER TABLE qa_selection_events ADD COLUMN plan_review_id uuid NOT NULL;
ALTER TABLE qa_plan_entry_definitions ADD CONSTRAINT qa_entry_definition_review_fk FOREIGN KEY(tenant_id,project_id,definition_id,definition_review_id) REFERENCES qa_reviews(tenant_id,project_id,definition_id,id);
ALTER TABLE qa_selection_events ADD CONSTRAINT qa_selection_plan_review_fk FOREIGN KEY(tenant_id,project_id,plan_id,plan_review_id) REFERENCES qa_reviews(tenant_id,project_id,plan_id,id);
CREATE FUNCTION guard_qa_entry_review_pin() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM qa_definition_versions d JOIN qa_reviews r ON r.tenant_id=d.tenant_id AND r.project_id=d.project_id AND r.definition_id=d.id AND r.id=NEW.definition_review_id AND r.subject_hash=d.content_hash AND r.decision='reviewed' WHERE d.tenant_id=NEW.tenant_id AND d.project_id=NEW.project_id AND d.id=NEW.definition_id AND d.review_state='reviewed' AND d.current_review_id=r.id AND d.review_revision=r.revision) THEN RAISE EXCEPTION 'Entry requires exact current reviewed definition pin' USING ERRCODE='23503'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER qa_entry_review_pin BEFORE INSERT ON qa_plan_entry_definitions FOR EACH ROW EXECUTE FUNCTION guard_qa_entry_review_pin();
CREATE FUNCTION guard_qa_selection_review_pin() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM qa_plan_versions p JOIN qa_reviews r ON r.tenant_id=p.tenant_id AND r.project_id=p.project_id AND r.plan_id=p.id AND r.id=NEW.plan_review_id AND r.subject_hash=p.content_hash AND r.decision='approved' WHERE p.tenant_id=NEW.tenant_id AND p.project_id=NEW.project_id AND p.id=NEW.plan_id AND p.review_state='approved' AND p.current_review_id=r.id AND p.review_revision=r.revision) THEN RAISE EXCEPTION 'Selection requires exact current approved plan pin' USING ERRCODE='23503'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER qa_selection_review_pin BEFORE INSERT ON qa_selection_events FOR EACH ROW EXECUTE FUNCTION guard_qa_selection_review_pin();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Immutable QA original review pins must be preserved.');
    }
};
