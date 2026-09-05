<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $userId = DB::table('users')->orderBy('id')->value('id');
        foreach ([null, ...DB::table('warehouses')->whereNull('deleted_at')->pluck('id')->all()] as $warehouseId) {
            DB::table('finance_document_sequences')->updateOrInsert(
                ['warehouse_id' => $warehouseId, 'document_type' => 'EMPLOYEE_ADVANCE'],
                ['name' => 'เงินทดรองจ่ายพนักงาน', 'prefix' => 'EA', 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('finance_document_sequences')->where('document_type', 'EMPLOYEE_ADVANCE')->delete();
    }
};
