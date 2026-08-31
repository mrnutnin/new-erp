<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'sales_documents', 'sales_intakes', 'sales_rfqs', 'sales_quotations', 'sales_orders', 'pos_physical_sales', 'pos_sales_returns',
        'purchase_documents', 'purchase_orders', 'purchase_requisitions', 'goods_receipts',
        'wms_inventory_adjustment_documents', 'wms_stock_count_documents', 'wms_issue_documents', 'wms_issue_returns',
        'finance_advance_deposits',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('branch_id')->nullable()->after('warehouse_id')->constrained()->restrictOnDelete();
                $table->index('branch_id');
            });

            DB::table($name.' as document')
                ->join('warehouses as warehouse', 'warehouse.id', '=', 'document.warehouse_id')
                ->whereNull('document.branch_id')
                ->update(['document.branch_id' => DB::raw('warehouse.branch_id')]);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['branch_id']);
                $table->dropConstrainedForeignId('branch_id');
            });
        }
    }
};
