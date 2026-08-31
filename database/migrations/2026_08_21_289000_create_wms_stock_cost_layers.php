<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $balanceColumns = Schema::getColumnListing('wms_stock_balances');
        Schema::table('wms_stock_balances', function (Blueprint $table) use ($balanceColumns): void {
            if (! in_array('inventory_value', $balanceColumns, true)) {
                $table->decimal('inventory_value', 20, 8)->default(0)->after('available');
            }
            if (! in_array('average_unit_cost', $balanceColumns, true)) {
                $table->decimal('average_unit_cost', 20, 8)->default(0)->after('inventory_value');
            }
        });

        if (! Schema::hasTable('wms_stock_cost_layers')) {
            Schema::create('wms_stock_cost_layers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
                $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
                $table->foreignId('source_movement_id')->constrained('wms_stock_movements')->restrictOnDelete();
                $table->decimal('original_quantity', 20, 8);
                $table->decimal('remaining_quantity', 20, 8);
                $table->decimal('unit_cost', 20, 8);
                $table->enum('method', ['AVG', 'FIFO']);
                $table->enum('cost_status', ['FINAL', 'PENDING'])->default('FINAL');
                $table->date('business_date');
                $table->timestamps();
                $table->index(['item_id', 'method', 'business_date'], 'wms_cost_layers_item_date_idx');
                $table->index(['warehouse_id', 'item_id', 'remaining_quantity'], 'wms_cost_layers_balance_idx');
                $table->unique('source_movement_id', 'wms_cost_layers_source_unique');
            });
        } else {
            $indexes = collect(Schema::getIndexes('wms_stock_cost_layers'))->pluck('name')->all();
            Schema::table('wms_stock_cost_layers', function (Blueprint $table) use ($indexes): void {
                if (! in_array('wms_cost_layers_balance_idx', $indexes, true)) {
                    $table->index(['warehouse_id', 'item_id', 'remaining_quantity'], 'wms_cost_layers_balance_idx');
                }
                if (! in_array('wms_cost_layers_source_unique', $indexes, true)) {
                    $table->unique('source_movement_id', 'wms_cost_layers_source_unique');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_stock_cost_layers');
        $columns = Schema::getColumnListing('wms_stock_balances');
        Schema::table('wms_stock_balances', function (Blueprint $table) use ($columns): void {
            $drop = array_values(array_intersect(['inventory_value', 'average_unit_cost'], $columns));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
