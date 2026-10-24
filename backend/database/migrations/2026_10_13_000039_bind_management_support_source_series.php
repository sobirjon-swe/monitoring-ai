<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Range probes exclude identical binding history through the B-tree bounds.
        DB::unprepared(<<<'SQL'
CREATE INDEX management_support_series_binding_idx ON management_support_versions
 (tenant_id,project_id,source_instance_id,object_id,action_id,action_version_id,assignment_id);
CREATE FUNCTION guard_management_support_series_binding() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF nullif(current_setting('app.management_authority_closed',true),'') IS DISTINCT FROM '1' THEN
  RAISE EXCEPTION 'Management coordination required' USING ERRCODE='42501';
 END IF;
 IF EXISTS(SELECT 1 FROM management_support_versions s
  WHERE s.tenant_id=NEW.tenant_id AND s.project_id=NEW.project_id
   AND s.source_instance_id=NEW.source_instance_id AND s.object_id=NEW.object_id
   AND (s.action_id,s.action_version_id,s.assignment_id)<(NEW.action_id,NEW.action_version_id,NEW.assignment_id) LIMIT 1)
 OR EXISTS(SELECT 1 FROM management_support_versions s
  WHERE s.tenant_id=NEW.tenant_id AND s.project_id=NEW.project_id
   AND s.source_instance_id=NEW.source_instance_id AND s.object_id=NEW.object_id
   AND (s.action_id,s.action_version_id,s.assignment_id)>(NEW.action_id,NEW.action_version_id,NEW.assignment_id) LIMIT 1) THEN
  RAISE EXCEPTION 'Management support series binding' USING ERRCODE='23503';
 END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER management_support_series_binding AFTER INSERT ON management_support_versions
 DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_management_support_series_binding();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Management support source bindings must be preserved.');
    }
};
