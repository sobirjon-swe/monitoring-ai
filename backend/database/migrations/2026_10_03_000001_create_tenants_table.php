<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('status', 20)->default('active');
            $table->timestampsTz(6);
        });
        DB::statement("ALTER TABLE public.tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE public.tenants ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.tenants FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON public.tenants USING (id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
