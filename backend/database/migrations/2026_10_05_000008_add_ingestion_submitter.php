<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Check outside tenant RLS: never invent provenance for a preexisting receipt.
        DB::statement('ALTER TABLE public.ingestion_receipts ADD COLUMN accepted_by uuid');
        try {
            DB::statement('ALTER TABLE public.ingestion_receipts ALTER COLUMN accepted_by SET NOT NULL');
        } catch (Throwable) {
            throw new RuntimeException('Submitter migration requires an empty synthetic receipt baseline.');
        }
        DB::statement('ALTER TABLE public.ingestion_receipts ADD CONSTRAINT ingestion_submitter_tenant_fk FOREIGN KEY (tenant_id,accepted_by) REFERENCES public.users(tenant_id,id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.ingestion_receipts DROP COLUMN accepted_by');
    }
};
