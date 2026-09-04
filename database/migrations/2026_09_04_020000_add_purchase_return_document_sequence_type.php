<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE finance_document_sequences MODIFY document_type ENUM('RECEIPT','PAYMENT','SALES_INVOICE','SALES_CREDIT_NOTE','PURCHASE_INVOICE','PURCHASE_CREDIT_NOTE','PURCHASE_ORDER','INVENTORY_ADJUSTMENT','INVENTORY_ISSUE','INVENTORY_RETURN','SALES_RFQ','SALES_INTAKE','SALES_QUOTATION','SALES_ORDER','PHYSICAL_SALE_HS','PHYSICAL_SALE_IV','SALES_RETURN','CUSTOMER','SUPPLIER','ADVANCE_DEPOSIT_AI','PURCHASE_REQUISITION','GOODS_RECEIPT','WMS_TRANSFER','STOCK_COUNT','ASSET_REGISTER','ASSET_CAPITALIZATION','ASSET_ADDITION','ASSET_TRANSFER','ASSET_COUNT','ASSET_MAINTENANCE','ASSET_DEPRECIATION','ASSET_IMPAIRMENT','ASSET_DISPOSAL','LANDED_COST','PURCHASE_RETURN') NOT NULL");
    }

    public function down(): void
    {
        // Keep the sequence type once it has been used; document numbers are immutable.
    }
};
