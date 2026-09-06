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
            $table->enum('reversal_status', ['NONE', 'REVERSED'])->default('NONE');
            $table->decimal('quantity', 20, 8);
            $table->decimal('value', 20, 8);
            $table->date('business_date');
            $table->string('reason', 500);
            $table->string('idempotency_key', 180)->unique();
            $table->foreignId('stock_movement_id')->nullable()->unique()->constrained('wms_stock_movements')->restrictOnDelete();
            $table->foreignId('cost_allocation_id')->nullable()->unique()->constrained('wms_cost_allocations')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_movement_id')->nullable()->constrained('wms_stock_movements')->restrictOnDelete();
            $table->foreignId('reversal_allocation_id')->nullable()->constrained('wms_cost_allocations')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->unsignedInteger('reversal_revision')->default(0);
            $table->timestamps();
            $table->index(['warehouse_id', 'business_date', 'status'], 'wms_adj_scope_date_status_idx');
            $table->unique(['id', 'reversal_revision'], 'wms_adjustments_reversal_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_inventory_adjustments');
    }
};
