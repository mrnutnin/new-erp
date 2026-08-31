<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_document_sequences', function (Blueprint $table): void {
            $table->enum('document_type', ['RECEIPT', 'PAYMENT', 'SALES_INVOICE', 'SALES_CREDIT_NOTE', 'PURCHASE_INVOICE', 'PURCHASE_CREDIT_NOTE', 'PURCHASE_ORDER', 'INVENTORY_ADJUSTMENT', 'INVENTORY_ISSUE', 'INVENTORY_RETURN', 'SALES_RFQ', 'SALES_INTAKE', 'SALES_QUOTATION', 'SALES_ORDER', 'PHYSICAL_SALE_HS', 'PHYSICAL_SALE_IV', 'SALES_RETURN', 'CUSTOMER'])->change();
        });

        if (! DB::table('finance_document_sequences')->whereNull('warehouse_id')->where('document_type', 'CUSTOMER')->exists()) {
            DB::table('finance_document_sequences')->insert([
                'warehouse_id' => null, 'document_type' => 'CUSTOMER', 'name' => 'รหัสลูกค้า', 'prefix' => 'CUST',
                'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1,
                'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'created_by' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve issued customer codes and any administrator changes to the shared sequence.
    }
};
