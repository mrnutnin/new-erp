<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropForeign(['source_sales_intake_id']);
            $table->dropUnique('sales_orders_intake_unique');
            $table->index('source_sales_intake_id', 'sales_orders_intake_idx');
            $table->foreign('source_sales_intake_id')->references('id')->on('sales_intakes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropForeign(['source_sales_intake_id']);
            $table->dropIndex('sales_orders_intake_idx');
            $table->unique('source_sales_intake_id', 'sales_orders_intake_unique');
            $table->foreign('source_sales_intake_id')->references('id')->on('sales_intakes')->restrictOnDelete();
        });
    }
};
