<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE role_catalog (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES tenants(id), key uuid NOT NULL,
 name varchar(120) NOT NULL, description varchar(2000) NOT NULL DEFAULT '',
 status varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','archived')),
 revision integer NOT NULL DEFAULT 1 CHECK (revision > 0),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,key), CHECK (length(btrim(name)) > 0)
);
CREATE UNIQUE INDEX role_catalog_name_unique ON role_catalog(tenant_id, lower(btrim(name)));
CREATE TABLE project_catalog_role_assignments (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, project_id uuid NOT NULL, employee_id uuid NOT NULL,
 role_catalog_id uuid NOT NULL, definition_revision integer NOT NULL CHECK (definition_revision > 0),
 definition_name varchar(120) NOT NULL, valid_from timestamptz NOT NULL, valid_to timestamptz,
 revision integer NOT NULL DEFAULT 1 CHECK (revision > 0), UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id),
 FOREIGN KEY(tenant_id,employee_id) REFERENCES employees(tenant_id,id),
 FOREIGN KEY(tenant_id,role_catalog_id) REFERENCES role_catalog(tenant_id,id),
 CHECK(valid_to IS NULL OR valid_to > valid_from),
 EXCLUDE USING gist (tenant_id WITH =, project_id WITH =, employee_id WITH =, role_catalog_id WITH =, tstzrange(valid_from,valid_to,'[)') WITH &&)
);
CREATE TABLE people_role_requests (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL, actor_id uuid NOT NULL, project_id uuid,
 operation varchar(30) NOT NULL, request_hash char(64) NOT NULL, response jsonb NOT NULL,
 FOREIGN KEY(tenant_id,actor_id) REFERENCES users(tenant_id,id),
 FOREIGN KEY(tenant_id,project_id) REFERENCES projects(tenant_id,id), UNIQUE(tenant_id,actor_id,id)
);
SQL);
        foreach (['role_catalog', 'project_catalog_role_assignments', 'people_role_requests'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
        }
    }

    public function down(): void
    {
        foreach (['people_role_requests', 'project_catalog_role_assignments', 'role_catalog'] as $table) {
            DB::statement("DROP TABLE $table");
        }
    }
};
