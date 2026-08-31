<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_document_sequences', function (Blueprint $table): void {
            $table->enum('document_type', ['RECEIPT', 'PAYMENT', 'SALES_INVOICE', 'SALES_CREDIT_NOTE', 'PURCHASE_INVOICE', 'PURCHASE_CREDIT_NOTE', 'PURCHASE_ORDER', 'INVENTORY_ADJUSTMENT', 'INVENTORY_ISSUE', 'INVENTORY_RETURN', 'SALES_RFQ', 'SALES_INTAKE', 'SALES_QUOTATION', 'SALES_ORDER', 'PHYSICAL_SALE_HS', 'PHYSICAL_SALE_IV'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('finance_document_sequences', function (Blueprint $table): void {
            $table->enum('document_type', ['RECEIPT', 'PAYMENT', 'SALES_INVOICE', 'SALES_CREDIT_NOTE', 'PURCHASE_INVOICE', 'PURCHASE_CREDIT_NOTE', 'PURCHASE_ORDER', 'INVENTORY_ADJUSTMENT', 'INVENTORY_ISSUE', 'INVENTORY_RETURN', 'SALES_RFQ', 'SALES_INTAKE', 'SALES_QUOTATION', 'SALES_ORDER'])->change();
        });
    }
};
