<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('sales_rfq_id')->nullable()->after('sales_quotation_id')->constrained('sales_rfqs')->restrictOnDelete();
            $table->unique('sales_rfq_id', 'sales_orders_rfq_unique');
            $table->foreignId('sales_quotation_id')->nullable()->change();
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->foreignId('source_quotation_line_id')->nullable()->change();
            $table->foreignId('source_rfq_line_id')->nullable()->after('source_quotation_line_id')->constrained('sales_rfq_lines')->restrictOnDelete();
            $table->index('source_rfq_line_id', 'sales_order_lines_rfq_line_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropForeign(['source_rfq_line_id']);
            $table->dropIndex('sales_order_lines_rfq_line_idx');
            $table->dropColumn('source_rfq_line_id');
            $table->foreignId('source_quotation_line_id')->nullable(false)->change();
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique('sales_orders_rfq_unique');
            $table->dropForeign(['sales_rfq_id']);
            $table->dropColumn('sales_rfq_id');
            $table->foreignId('sales_quotation_id')->nullable(false)->change();
        });
    }
};
