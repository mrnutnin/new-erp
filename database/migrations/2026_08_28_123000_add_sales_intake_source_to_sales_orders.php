<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('source_sales_intake_id')->nullable()->after('sales_rfq_id')->constrained('sales_intakes')->restrictOnDelete();
            $table->unique('source_sales_intake_id', 'sales_orders_intake_unique');
        });

        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->foreignId('source_sales_intake_line_id')->nullable()->after('source_rfq_line_id')->constrained('sales_intake_lines')->restrictOnDelete();
            $table->index('source_sales_intake_line_id', 'sales_order_lines_intake_line_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->dropForeign(['source_sales_intake_line_id']);
            $table->dropIndex('sales_order_lines_intake_line_idx');
            $table->dropColumn('source_sales_intake_line_id');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropUnique('sales_orders_intake_unique');
            $table->dropConstrainedForeignId('source_sales_intake_id');
        });
    }
};
