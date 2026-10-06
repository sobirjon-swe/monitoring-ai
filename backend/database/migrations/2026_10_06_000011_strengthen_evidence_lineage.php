<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ingestion_receipts ADD CONSTRAINT receipt_evidence_hash_unique UNIQUE(tenant_id,project_id,connector_id,source_instance_id,source_object_type,source_object_id,id,canonical_hash)');
        DB::statement('ALTER TABLE evidence_link_proposals ADD CONSTRAINT evidence_receipt_hash_fk FOREIGN KEY(tenant_id,project_id,connector_id,source_instance_id,source_object_type,source_object_id,receipt_id,canonical_hash) REFERENCES ingestion_receipts(tenant_id,project_id,connector_id,source_instance_id,source_object_type,source_object_id,id,canonical_hash)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE evidence_link_proposals DROP CONSTRAINT evidence_receipt_hash_fk');
        DB::statement('ALTER TABLE ingestion_receipts DROP CONSTRAINT receipt_evidence_hash_unique');
    }
};
