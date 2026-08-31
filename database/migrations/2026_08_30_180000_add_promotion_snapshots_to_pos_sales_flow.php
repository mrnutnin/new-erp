<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_intakes', 'sales_quotations', 'sales_orders', 'pos_physical_sales'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->json('promotion_snapshot')->nullable();
                $table->decimal('promotion_discount_amount', 18, 2)->unsigned()->default(0);
            });
        }

        foreach (['sales_intake_lines', 'sales_quotation_lines', 'sales_order_lines', 'pos_physical_sale_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->json('pricing_snapshot')->nullable();
                $table->decimal('promotion_discount_amount', 18, 2)->unsigned()->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_intake_lines', 'sales_quotation_lines', 'sales_order_lines', 'pos_physical_sale_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['pricing_snapshot', 'promotion_discount_amount']);
            });
        }

        foreach (['sales_intakes', 'sales_quotations', 'sales_orders', 'pos_physical_sales'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['promotion_snapshot', 'promotion_discount_amount']);
            });
        }
    }
};
