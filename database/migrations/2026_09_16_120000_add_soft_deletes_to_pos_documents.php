<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'sales_intakes',
        'sales_quotations',
        'sales_orders',
        'pos_physical_sales',
        'sales_documents',
        'pos_sales_returns',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->softDeletes());
            }
        }

        Schema::table('sales_quotations', function (Blueprint $table): void {
            $table->dropForeign(['sales_rfq_id']);
            $table->dropUnique('sales_quotations_rfq_unique');
            $table->index('sales_rfq_id', 'sales_quotations_rfq_idx');
            $table->foreign('sales_rfq_id')->references('id')->on('sales_rfqs')->restrictOnDelete();
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropForeign(['sales_quotation_id']);
            $table->dropForeign(['sales_rfq_id']);
            $table->dropUnique('sales_orders_quotation_unique');
            $table->dropUnique('sales_orders_rfq_unique');
            $table->index('sales_quotation_id', 'sales_orders_quotation_idx');
            $table->index('sales_rfq_id', 'sales_orders_rfq_idx');
            $table->foreign('sales_quotation_id')->references('id')->on('sales_quotations')->restrictOnDelete();
            $table->foreign('sales_rfq_id')->references('id')->on('sales_rfqs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropForeign(['sales_quotation_id']);
            $table->dropForeign(['sales_rfq_id']);
            $table->dropIndex('sales_orders_quotation_idx');
            $table->dropIndex('sales_orders_rfq_idx');
            $table->unique('sales_quotation_id', 'sales_orders_quotation_unique');
            $table->unique('sales_rfq_id', 'sales_orders_rfq_unique');
            $table->foreign('sales_quotation_id')->references('id')->on('sales_quotations')->restrictOnDelete();
            $table->foreign('sales_rfq_id')->references('id')->on('sales_rfqs')->restrictOnDelete();
        });

        Schema::table('sales_quotations', function (Blueprint $table): void {
            $table->dropForeign(['sales_rfq_id']);
            $table->dropIndex('sales_quotations_rfq_idx');
            $table->unique('sales_rfq_id', 'sales_quotations_rfq_unique');
            $table->foreign('sales_rfq_id')->references('id')->on('sales_rfqs')->restrictOnDelete();
        });

        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropSoftDeletes());
            }
        }
    }
};
