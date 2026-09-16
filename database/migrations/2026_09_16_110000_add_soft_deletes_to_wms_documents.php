<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'wms_inventory_adjustments',
        'wms_inventory_adjustment_documents',
        'wms_opening_balance_batches',
        'wms_stock_count_documents',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->softDeletes());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropSoftDeletes());
            }
        }
    }
};
