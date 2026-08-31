<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_inventory_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->enum('direction', ['GAIN', 'LOSS']);
            $table->enum('status', ['DRAFT', 'APPROVED', 'POSTED'])->default('DRAFT');
            $table->decimal('quantity', 20, 8);
            $table->decimal('value', 20, 8);
            $table->date('business_date');
            $table->string('reason', 500);
            $table->string('idempotency_key', 180)->unique();
            $table->foreignId('stock_movement_id')->nullable()->unique()->constrained('wms_stock_movements')->restrictOnDelete();
            $table->foreignId('cost_allocation_id')->nullable()->unique()->constrained('wms_cost_allocations')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'business_date', 'status'], 'wms_adj_scope_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_inventory_adjustments');
    }
};
