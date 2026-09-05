<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $userId = DB::table('users')->orderBy('id')->value('id');
        foreach ([['PETTY_CASH', 'ใบสำคัญเงินสดย่อย', 'PC'], ['PETTY_CASH_TOP_UP', 'ใบเติมเงินสดย่อย', 'PCT'], ['PETTY_CASH_CLEARING', 'ใบเคลียร์เงินสดย่อย', 'PCC']] as [$type, $name, $prefix]) {
            DB::table('finance_document_sequences')->updateOrInsert(
                ['warehouse_id' => null, 'document_type' => $type],
                ['name' => $name, 'prefix' => $prefix, 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('finance_document_sequences')->whereNull('warehouse_id')->whereIn('document_type', ['PETTY_CASH', 'PETTY_CASH_TOP_UP', 'PETTY_CASH_CLEARING'])->delete();
    }
};
