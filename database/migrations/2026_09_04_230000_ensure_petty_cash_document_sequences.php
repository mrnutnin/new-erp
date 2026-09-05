<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE finance_document_sequences MODIFY document_type VARCHAR(40) NOT NULL');
        $userId = DB::table('users')->orderBy('id')->value('id');
        foreach (DB::table('warehouses')->whereNull('deleted_at')->pluck('id') as $warehouseId) {
            foreach ([['PETTY_CASH', 'ใบสำคัญเงินสดย่อย', 'PC'], ['PETTY_CASH_TOP_UP', 'ใบเติมเงินสดย่อย', 'PCT']] as [$type, $name, $prefix]) {
                if (! DB::table('finance_document_sequences')->where('warehouse_id', $warehouseId)->where('document_type', $type)->exists()) {
                    DB::table('finance_document_sequences')->insert([
                        'warehouse_id' => $warehouseId,
                        'document_type' => $type,
                        'name' => $name,
                        'prefix' => $prefix,
                        'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}',
                        'reset_rule' => 'YEARLY',
                        'next_number' => 1,
                        'is_active' => true,
                        'number_reuse_policy' => 'NEVER_REUSE',
                        'created_by' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('finance_document_sequences')->whereIn('document_type', ['PETTY_CASH', 'PETTY_CASH_TOP_UP'])->delete();
    }
};
