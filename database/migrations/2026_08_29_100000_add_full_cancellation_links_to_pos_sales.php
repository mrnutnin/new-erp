<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales_returns', function (Blueprint $table): void {
            $table->date('posting_date')->nullable()->after('document_date');
            $table->foreignId('journal_entry_id')->nullable()->after('total_amount')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('cogs_journal_entry_id')->nullable()->after('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->after('posted_by');
            $table->string('reversal_key', 100)->nullable()->unique()->after('void_reason');
            $table->unsignedInteger('reversal_revision')->default(0)->after('reversal_key');
        });

        Schema::table('pos_sales_return_lines', function (Blueprint $table): void {
            $table->foreignId('stock_uom_id')->nullable()->after('uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('stock_quantity', 18, 8)->nullable()->after('quantity');
            $table->decimal('uom_factor', 18, 8)->nullable()->after('stock_quantity');
            $table->json('conversion_snapshot')->nullable()->after('item_snapshot');
        });

        Schema::create('pos_sales_return_inventory_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_return_line_id');
            $table->foreignId('source_stock_movement_id');
            $table->foreignId('reversal_stock_movement_id')->nullable();
            $table->foreignId('source_cost_allocation_id')->nullable();
            $table->foreignId('reversal_cost_allocation_id')->nullable();
            $table->timestamps();

            $table->foreign('sales_return_line_id', 'psril_return_line_fk')->references('id')->on('pos_sales_return_lines')->restrictOnDelete();
            $table->foreign('source_stock_movement_id', 'psril_source_movement_fk')->references('id')->on('wms_stock_movements')->restrictOnDelete();
            $table->foreign('reversal_stock_movement_id', 'psril_reversal_movement_fk')->references('id')->on('wms_stock_movements')->restrictOnDelete();
            $table->foreign('source_cost_allocation_id', 'psril_source_allocation_fk')->references('id')->on('wms_cost_allocations')->restrictOnDelete();
            $table->foreign('reversal_cost_allocation_id', 'psril_reversal_allocation_fk')->references('id')->on('wms_cost_allocations')->restrictOnDelete();
            $table->unique(['sales_return_line_id', 'source_cost_allocation_id'], 'pos_sales_return_inventory_source_alloc_unique');
            $table->unique('reversal_cost_allocation_id', 'pos_sales_return_inventory_reversal_alloc_unique');
        });

        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->foreignId('cancellation_return_id')->nullable()->unique()->after('cogs_journal_entry_id')->constrained('pos_sales_returns')->restrictOnDelete();
            $table->enum('reversal_status', ['NONE', 'IN_PROGRESS', 'REVERSED'])->default('NONE')->after('status');
            $table->unsignedInteger('reversal_revision')->default(0)->after('reversal_status');
            $table->string('reversal_key', 100)->nullable()->unique()->after('reversal_revision');
        });
    }

    public function down(): void
    {
        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->dropForeign(['cancellation_return_id']);
            $table->dropUnique(['cancellation_return_id']);
            $table->dropUnique(['reversal_key']);
            $table->dropColumn(['cancellation_return_id', 'reversal_status', 'reversal_revision', 'reversal_key']);
        });

        Schema::dropIfExists('pos_sales_return_inventory_links');

        Schema::table('pos_sales_return_lines', function (Blueprint $table): void {
            $table->dropForeign(['stock_uom_id']);
            $table->dropColumn(['stock_uom_id', 'stock_quantity', 'uom_factor', 'conversion_snapshot']);
        });

        Schema::table('pos_sales_returns', function (Blueprint $table): void {
            $table->dropUnique(['reversal_key']);
            $table->dropForeign(['journal_entry_id', 'cogs_journal_entry_id', 'posted_by']);
            $table->dropColumn(['posting_date', 'journal_entry_id', 'cogs_journal_entry_id', 'posted_by', 'posted_at', 'reversal_key', 'reversal_revision']);
        });
    }
};
