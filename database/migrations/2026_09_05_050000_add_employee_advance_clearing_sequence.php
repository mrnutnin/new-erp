<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('finance_document_sequences')->updateOrInsert(['warehouse_id' => null, 'document_type' => 'EMPLOYEE_ADVANCE_CLEARING'], ['name' => 'ใบเคลียร์เงินทดรองพนักงาน', 'prefix' => 'EAC', 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'updated_at' => $now, 'created_at' => $now]);
    }

    public function down(): void
    {
        DB::table('finance_document_sequences')->whereNull('warehouse_id')->where('document_type', 'EMPLOYEE_ADVANCE_CLEARING')->delete();
    }
};
