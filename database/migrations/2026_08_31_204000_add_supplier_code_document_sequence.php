<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_document_sequences', fn (Blueprint $table) => $table->enum('document_type', [
            'RECEIPT', 'PAYMENT', 'SALES_INVOICE', 'SALES_CREDIT_NOTE', 'PURCHASE_INVOICE', 'PURCHASE_CREDIT_NOTE', 'PURCHASE_ORDER',
            'INVENTORY_ADJUSTMENT', 'INVENTORY_ISSUE', 'INVENTORY_RETURN', 'SALES_RFQ', 'SALES_INTAKE', 'SALES_QUOTATION', 'SALES_ORDER',
            'PHYSICAL_SALE_HS', 'PHYSICAL_SALE_IV', 'SALES_RETURN', 'CUSTOMER', 'SUPPLIER', 'ADVANCE_DEPOSIT_AI',
            'PURCHASE_REQUISITION', 'GOODS_RECEIPT', 'WMS_TRANSFER', 'STOCK_COUNT',
        ])->change());

        DB::table('finance_document_sequences')->updateOrInsert(
            ['warehouse_id' => null, 'document_type' => 'SUPPLIER'],
            ['name' => 'รหัสผู้ขาย/คู่ค้า', 'prefix' => 'SUP', 'number_format' => '{PREFIX}{NUMBER:6}', 'reset_rule' => 'NEVER', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('finance_document_sequences')->whereNull('warehouse_id')->where('document_type', 'SUPPLIER')->delete();
    }
};
