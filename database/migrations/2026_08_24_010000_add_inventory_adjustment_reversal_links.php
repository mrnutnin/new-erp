<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_inventory_adjustments', function (Blueprint $table): void {
            $table->enum('reversal_status', ['NONE', 'REVERSED'])->default('NONE')->after('status');
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('cost_allocation_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_movement_id')->nullable()->after('reversal_journal_entry_id')->constrained('wms_stock_movements')->restrictOnDelete();
            $table->foreignId('reversal_allocation_id')->nullable()->after('reversal_movement_id')->constrained('wms_cost_allocations')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_at');
            $table->unsignedInteger('reversal_revision')->default(0)->after('reversal_reason');
            $table->unique(['id', 'reversal_revision'], 'wms_adjustments_reversal_revision_unique');
            $table->unique('reversal_journal_entry_id', 'wms_adjustments_reversal_journal_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wms_inventory_adjustments', function (Blueprint $table): void {
            $table->dropUnique('wms_adjustments_reversal_journal_unique');
            $table->dropUnique('wms_adjustments_reversal_revision_unique');
            $table->dropForeign(['reversal_allocation_id']);
            $table->dropForeign(['reversal_movement_id']);
            $table->dropForeign(['reversal_journal_entry_id']);
            $table->dropForeign(['reversed_by']);
            $table->dropColumn([
                'reversal_status', 'reversal_journal_entry_id', 'reversal_movement_id', 'reversal_allocation_id',
                'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_revision',
            ]);
        });
    }
};
