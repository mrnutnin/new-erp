<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_issue_return_line_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_line_id')->constrained('wms_issue_return_lines')->restrictOnDelete();
            $table->foreignId('source_allocation_id')->constrained('wms_cost_allocations')->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->unique()->constrained('wms_stock_movements')->restrictOnDelete();
            $table->foreignId('cost_allocation_id')->nullable()->unique()->constrained('wms_cost_allocations')->restrictOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->timestamps();
            $table->unique(['return_line_id', 'source_allocation_id'], 'wms_issue_return_line_alloc_source_unique');
            $table->index(['source_allocation_id', 'return_line_id'], 'wms_issue_return_alloc_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_issue_return_line_allocations');
    }
};
