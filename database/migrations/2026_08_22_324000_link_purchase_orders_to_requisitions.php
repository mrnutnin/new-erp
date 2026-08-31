<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->foreignId('purchase_requisition_id')->nullable()->after('warehouse_id')->constrained('purchase_requisitions')->restrictOnDelete();
            $table->unique('purchase_requisition_id', 'purchase_orders_requisition_unique');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_requisition_line_id')->nullable()->after('purchase_order_id')->constrained('purchase_requisition_lines')->restrictOnDelete();
            $table->index('purchase_requisition_line_id', 'purchase_order_lines_requisition_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropForeign(['purchase_requisition_line_id']);
            $table->dropIndex('purchase_order_lines_requisition_idx');
            $table->dropColumn('purchase_requisition_line_id');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropUnique('purchase_orders_requisition_unique');
            $table->dropForeign(['purchase_requisition_id']);
            $table->dropColumn('purchase_requisition_id');
        });
    }
};
