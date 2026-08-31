<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales_commission_records', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('warehouse_id')->constrained()->restrictOnDelete();
        });

        DB::table('pos_sales_commission_records as records')
            ->leftJoin('pos_physical_sales as sales', 'sales.id', '=', 'records.physical_sale_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'records.warehouse_id')
            ->whereNull('records.branch_id')
            ->update(['records.branch_id' => DB::raw('COALESCE(sales.branch_id, warehouses.branch_id)')]);

        Schema::table('pos_sales_commission_records', function (Blueprint $table): void {
            $table->index(['branch_id', 'warehouse_id', 'status', 'calculated_at'], 'pos_commission_records_branch_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales_commission_records', function (Blueprint $table): void {
            $table->dropIndex('pos_commission_records_branch_scope_idx');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
