<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('programs', 'requires_branch')) {
            Schema::table('programs', fn (Blueprint $table) => $table->boolean('requires_branch')->default(false)->after('description'));
        }

        DB::table('programs')->where('code', 'asset')->update([
            'requires_branch' => true,
            'requires_warehouse' => false,
            'entry_route' => 'asset.index',
            'updated_at' => now(),
        ]);

        if (! Schema::hasColumn('company_settings', 'asset_enabled')) {
            Schema::table('company_settings', fn (Blueprint $table) => $table->boolean('asset_enabled')->nullable()->after('production_enabled'));
        }
        DB::table('company_settings')->whereNull('asset_enabled')->update(['asset_enabled' => true]);

        Schema::table('journal_entries', fn (Blueprint $table) => $table->foreignId('warehouse_id')->nullable()->change());
        Schema::table('finance_document_sequences', fn (Blueprint $table) => $table->enum('document_type', [
            'RECEIPT', 'PAYMENT', 'SALES_INVOICE', 'SALES_CREDIT_NOTE', 'PURCHASE_INVOICE', 'PURCHASE_CREDIT_NOTE',
            'PURCHASE_ORDER', 'INVENTORY_ADJUSTMENT', 'INVENTORY_ISSUE', 'INVENTORY_RETURN', 'SALES_RFQ', 'SALES_INTAKE',
            'SALES_QUOTATION', 'SALES_ORDER', 'PHYSICAL_SALE_HS', 'PHYSICAL_SALE_IV', 'SALES_RETURN', 'CUSTOMER', 'SUPPLIER',
            'ADVANCE_DEPOSIT_AI', 'PURCHASE_REQUISITION', 'GOODS_RECEIPT', 'WMS_TRANSFER', 'STOCK_COUNT',
            'ASSET_REGISTER', 'ASSET_CAPITALIZATION', 'ASSET_TRANSFER', 'ASSET_COUNT', 'ASSET_MAINTENANCE',
            'ASSET_DEPRECIATION', 'ASSET_IMPAIRMENT', 'ASSET_DISPOSAL',
        ])->change());
    }

    public function down(): void
    {
        Schema::table('finance_document_sequences', fn (Blueprint $table) => $table->enum('document_type', [
            'RECEIPT', 'PAYMENT', 'SALES_INVOICE', 'SALES_CREDIT_NOTE', 'PURCHASE_INVOICE', 'PURCHASE_CREDIT_NOTE',
            'PURCHASE_ORDER', 'INVENTORY_ADJUSTMENT', 'INVENTORY_ISSUE', 'INVENTORY_RETURN', 'SALES_RFQ', 'SALES_INTAKE',
            'SALES_QUOTATION', 'SALES_ORDER', 'PHYSICAL_SALE_HS', 'PHYSICAL_SALE_IV', 'SALES_RETURN', 'CUSTOMER', 'SUPPLIER',
            'ADVANCE_DEPOSIT_AI', 'PURCHASE_REQUISITION', 'GOODS_RECEIPT', 'WMS_TRANSFER', 'STOCK_COUNT',
        ])->change());
        Schema::table('journal_entries', fn (Blueprint $table) => $table->foreignId('warehouse_id')->nullable(false)->change());
        if (Schema::hasColumn('company_settings', 'asset_enabled')) {
            Schema::table('company_settings', fn (Blueprint $table) => $table->dropColumn('asset_enabled'));
        }
        if (Schema::hasColumn('programs', 'requires_branch')) {
            Schema::table('programs', fn (Blueprint $table) => $table->dropColumn('requires_branch'));
        }
    }
};
