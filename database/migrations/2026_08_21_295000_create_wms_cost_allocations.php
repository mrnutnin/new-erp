<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_cost_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_movement_id')->constrained('wms_stock_movements')->restrictOnDelete();
            $table->foreignId('stock_cost_layer_id')->nullable()->constrained('wms_stock_cost_layers')->restrictOnDelete();
            $table->foreignId('recost_request_id')->nullable()->constrained('wms_cost_recalculation_requests')->restrictOnDelete();
            $table->foreignId('parent_allocation_id')->nullable()->constrained('wms_cost_allocations')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('wms_items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->enum('allocation_type', ['RECEIPT', 'ISSUE', 'RETURN', 'ADJUSTMENT', 'TRANSFER', 'PRODUCTION', 'RECOST']);
            $table->enum('direction', ['IN', 'OUT']);
            $table->enum('cost_status', ['FINAL', 'PENDING']);
            $table->enum('status', ['PENDING', 'POSTED', 'REVERSED', 'REQUIRES_RECOST'])->default('PENDING');
            $table->string('method', 10);
            $table->string('policy_version', 30);
            $table->unsignedInteger('revision')->default(0);
            $table->decimal('quantity', 20, 8);
            $table->decimal('unit_cost', 20, 8);
            $table->decimal('value', 20, 8);
            $table->date('business_date');
            $table->string('idempotency_key', 180)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['item_id', 'warehouse_id', 'business_date', 'status'], 'wms_allocations_valuation_idx');
            $table->index(['stock_movement_id', 'revision'], 'wms_allocations_movement_revision_idx');
            $table->index(['journal_entry_id', 'status'], 'wms_allocations_journal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_cost_allocations');
    }
};
