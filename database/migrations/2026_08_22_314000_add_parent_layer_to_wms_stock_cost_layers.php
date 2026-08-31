<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wms_stock_cost_layers') || Schema::hasColumn('wms_stock_cost_layers', 'parent_layer_id')) {
            return;
        }

        Schema::table('wms_stock_cost_layers', function (Blueprint $table): void {
            $table->dropForeign(['source_movement_id']);
            $table->dropUnique('wms_cost_layers_source_unique');
            $table->index('source_movement_id', 'wms_cost_layers_source_idx');
            $table->foreign('source_movement_id', 'wms_cost_layers_source_fk')
                ->references('id')->on('wms_stock_movements')->restrictOnDelete();
            $table->foreignId('parent_layer_id')->nullable()->after('source_movement_id')->constrained('wms_stock_cost_layers')->restrictOnDelete();
            $table->string('lineage_key', 220)->nullable()->after('parent_layer_id');
            $table->index(['parent_layer_id', 'warehouse_id', 'item_id'], 'wms_cost_layers_parent_scope_idx');
            $table->unique('lineage_key', 'wms_cost_layers_lineage_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wms_stock_cost_layers') || ! Schema::hasColumn('wms_stock_cost_layers', 'parent_layer_id')) {
            return;
        }

        Schema::table('wms_stock_cost_layers', function (Blueprint $table): void {
            $table->dropForeign(['parent_layer_id']);
            $table->dropUnique('wms_cost_layers_lineage_unique');
            $table->dropIndex('wms_cost_layers_parent_scope_idx');
            $table->dropForeign('wms_cost_layers_source_fk');
            $table->dropIndex('wms_cost_layers_source_idx');
            $table->unique('source_movement_id', 'wms_cost_layers_source_unique');
            $table->foreign('source_movement_id', 'wms_stock_cost_layers_source_movement_id_foreign')
                ->references('id')->on('wms_stock_movements')->restrictOnDelete();
            $table->dropColumn(['parent_layer_id', 'lineage_key']);
        });
    }
};
