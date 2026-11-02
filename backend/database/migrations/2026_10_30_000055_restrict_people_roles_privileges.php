<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::scalar("SELECT rolsuper OR rolbypassrls FROM pg_roles WHERE rolname = 'monitoring_ai_app'") || DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_class c JOIN pg_roles r ON r.oid=c.relowner WHERE c.relname IN ('role_catalog','project_catalog_role_assignments','people_role_requests') AND r.rolname='monitoring_ai_app')")) {
            throw new LogicException('People roles runtime must be a nonowner without RLS bypass.');
        }
        DB::statement('REVOKE ALL ON public.role_catalog, public.project_catalog_role_assignments, public.people_role_requests FROM monitoring_ai_app, PUBLIC');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON public.role_catalog, public.project_catalog_role_assignments TO monitoring_ai_app');
        DB::statement('GRANT SELECT, INSERT ON public.people_role_requests TO monitoring_ai_app');
    }

    public function down(): void
    {
        throw new LogicException('People role runtime privilege restrictions must be preserved.');
    }
};
