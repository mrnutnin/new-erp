<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_opening_balance_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('cutover_date');
            $table->enum('costing_method', ['AVG', 'FIFO']);
            $table->enum('status', ['DRAFT', 'POSTED', 'VOIDED'])->default('DRAFT')->index();
            $table->decimal('total_value', 20, 8)->default(0);
            $table->string('source_reference', 100)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'cutover_date', 'status'], 'wms_opening_batch_scope_idx');
        });

        Schema::create('wms_opening_balance_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('wms_opening_balance_batches')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->decimal('unit_cost', 20, 8);
            $table->decimal('total_value', 20, 8);
            $table->foreignId('stock_movement_id')->nullable()->unique()->constrained('wms_stock_movements')->restrictOnDelete();
            $table->foreignId('cost_layer_id')->nullable()->unique()->constrained('wms_stock_cost_layers')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['batch_id', 'item_id', 'uom_id'], 'wms_opening_line_item_uq');
            $table->index(['item_id', 'uom_id'], 'wms_opening_line_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_opening_balance_lines');
        Schema::dropIfExists('wms_opening_balance_batches');
    }
};
