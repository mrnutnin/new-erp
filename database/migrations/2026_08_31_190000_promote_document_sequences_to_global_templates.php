<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_document_sequences', fn (Blueprint $table) => $table->enum('document_type', ['RECEIPT', 'PAYMENT', 'SALES_INVOICE', 'SALES_CREDIT_NOTE', 'PURCHASE_INVOICE', 'PURCHASE_CREDIT_NOTE', 'PURCHASE_ORDER', 'INVENTORY_ADJUSTMENT', 'INVENTORY_ISSUE', 'INVENTORY_RETURN', 'SALES_RFQ', 'SALES_INTAKE', 'SALES_QUOTATION', 'SALES_ORDER', 'PHYSICAL_SALE_HS', 'PHYSICAL_SALE_IV', 'SALES_RETURN', 'CUSTOMER', 'ADVANCE_DEPOSIT_AI', 'PURCHASE_REQUISITION', 'GOODS_RECEIPT', 'WMS_TRANSFER', 'STOCK_COUNT'])->change());

        foreach (DB::table('finance_document_sequences')->whereNotNull('warehouse_id')->orderBy('id')->get()->groupBy('document_type') as $type => $rows) {
            if (DB::table('finance_document_sequences')->whereNull('warehouse_id')->where('document_type', $type)->exists()) {
                continue;
            }
            $source = $rows->first();
            DB::table('finance_document_sequences')->insert((array) collect((array) $source)->only(['document_type', 'name', 'prefix', 'number_format', 'reset_rule', 'next_number', 'last_reset_key', 'is_active', 'number_reuse_policy', 'created_by', 'created_at', 'updated_at'])->all() + ['warehouse_id' => null]);
        }

        foreach (['PURCHASE_REQUISITION' => ['ใบขอซื้อ', 'PR'], 'GOODS_RECEIPT' => ['ใบรับสินค้า', 'GR'], 'WMS_TRANSFER' => ['ใบโอนสินค้า', 'TR'], 'STOCK_COUNT' => ['ใบนับสินค้า', 'SC']] as $type => [$name, $prefix]) {
            DB::table('finance_document_sequences')->updateOrInsert(
                ['warehouse_id' => null, 'document_type' => $type],
                ['name' => $name, 'prefix' => $prefix, 'number_format' => '{PREFIX}{BRANCH}{YYMM}{NUMBER:6}', 'reset_rule' => 'MONTHLY', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void {}
};
