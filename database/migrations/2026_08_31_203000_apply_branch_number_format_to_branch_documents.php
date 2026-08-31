<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('finance_document_sequences')
            ->whereNull('warehouse_id')
            ->whereIn('document_type', [
                'RECEIPT', 'PAYMENT',
                'SALES_INVOICE', 'SALES_CREDIT_NOTE', 'SALES_RFQ', 'SALES_INTAKE', 'SALES_QUOTATION', 'SALES_ORDER',
                'PHYSICAL_SALE_HS', 'PHYSICAL_SALE_IV', 'SALES_RETURN', 'ADVANCE_DEPOSIT_AI',
                'PURCHASE_INVOICE', 'PURCHASE_CREDIT_NOTE', 'PURCHASE_ORDER', 'PURCHASE_REQUISITION', 'GOODS_RECEIPT',
                'INVENTORY_ADJUSTMENT', 'INVENTORY_ISSUE', 'INVENTORY_RETURN', 'WMS_TRANSFER', 'STOCK_COUNT',
            ])
            ->update([
                'number_format' => '{PREFIX}{BRANCH}{YYMM}{NUMBER:6}',
                'reset_rule' => 'MONTHLY',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Existing document numbers are immutable; do not attempt to restore templates.
    }
};
