<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wms_stock_policies', 'item_id')) {
            Schema::table('wms_stock_policies', function (Blueprint $table): void {
                $table->foreignId('item_id')->nullable()->after('warehouse_id')->constrained('wms_items')->restrictOnDelete();
            });
        }
        Schema::table('wms_stock_policies', function (Blueprint $table): void {
            // MySQL may use the old unique warehouse index to support the FK.
            // Temporarily replace the FK while changing that index.
            $table->dropForeign(['warehouse_id']);
            $table->dropUnique('wms_stock_policies_warehouse_id_unique');
            $table->unique(['warehouse_id', 'item_id']);
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wms_stock_policies', function (Blueprint $table): void {
            $table->dropForeign(['warehouse_id']);
            $table->dropUnique('wms_stock_policies_warehouse_id_item_id_unique');
            $table->dropForeign(['item_id']);
            $table->dropColumn('item_id');
            $table->unique(['warehouse_id']);
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });
    }
};
