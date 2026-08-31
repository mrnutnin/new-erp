<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_rfqs', function (Blueprint $table): void {
            $table->decimal('subtotal', 18, 2)->unsigned()->default(0);
            $table->decimal('discount_amount', 18, 2)->unsigned()->default(0);
            $table->json('promotion_snapshot')->nullable();
            $table->decimal('promotion_discount_amount', 18, 2)->unsigned()->default(0);
            $table->decimal('total_amount', 18, 2)->unsigned()->default(0);
        });

        Schema::table('sales_rfq_lines', function (Blueprint $table): void {
            $table->decimal('line_total', 18, 2)->unsigned()->default(0);
            $table->json('pricing_snapshot')->nullable();
            $table->decimal('promotion_discount_amount', 18, 2)->unsigned()->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sales_rfq_lines', function (Blueprint $table): void {
            $table->dropColumn(['line_total', 'pricing_snapshot', 'promotion_discount_amount']);
        });

        Schema::table('sales_rfqs', function (Blueprint $table): void {
            $table->dropColumn(['subtotal', 'discount_amount', 'promotion_snapshot', 'promotion_discount_amount', 'total_amount']);
        });
    }
};
