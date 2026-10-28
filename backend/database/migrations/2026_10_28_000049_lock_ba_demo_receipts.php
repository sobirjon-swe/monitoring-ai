<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.ba_demo_receipts FROM monitoring_ai_app, PUBLIC');
        DB::statement(<<<'SQL'
CREATE FUNCTION public.ba_demo_receipt_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Synthetic BA receipt is immutable' USING ERRCODE = '42501';
END
$$
SQL);
        DB::statement('CREATE TRIGGER ba_demo_receipt_immutable BEFORE UPDATE OR DELETE ON public.ba_demo_receipts FOR EACH ROW EXECUTE FUNCTION public.ba_demo_receipt_immutable()');
    }

    public function down(): void
    {
        throw new LogicException('Synthetic BA receipts must be preserved.');
    }
};
