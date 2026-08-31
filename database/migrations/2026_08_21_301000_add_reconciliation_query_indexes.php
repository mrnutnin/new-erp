<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->index(['warehouse_id', 'status', 'entry_date'], 'journal_entries_reconciliation_idx');
        });

        Schema::table('wms_cost_allocations', function (Blueprint $table): void {
            $table->index(['warehouse_id', 'business_date', 'status'], 'wms_allocations_reconciliation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('wms_cost_allocations', function (Blueprint $table): void {
            $table->dropIndex('wms_allocations_reconciliation_idx');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropIndex('journal_entries_reconciliation_idx');
        });
    }
};
