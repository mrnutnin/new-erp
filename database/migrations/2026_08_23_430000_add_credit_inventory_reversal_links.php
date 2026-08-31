<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_documents', 'inventory_reversal_movement_id')) {
                $table->foreignId('inventory_reversal_movement_id')->nullable()->after('reversal_journal_entry_id')->constrained('wms_stock_movements')->restrictOnDelete();
            }
            if (! Schema::hasColumn('purchase_documents', 'inventory_reversal_allocation_id')) {
                $table->foreignId('inventory_reversal_allocation_id')->nullable()->after('inventory_reversal_movement_id')->constrained('wms_cost_allocations')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_documents', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_documents', 'inventory_reversal_allocation_id')) {
                $table->dropForeign(['inventory_reversal_allocation_id']);
                $table->dropColumn('inventory_reversal_allocation_id');
            }
            if (Schema::hasColumn('purchase_documents', 'inventory_reversal_movement_id')) {
                $table->dropForeign(['inventory_reversal_movement_id']);
                $table->dropColumn('inventory_reversal_movement_id');
            }
        });
    }
};
