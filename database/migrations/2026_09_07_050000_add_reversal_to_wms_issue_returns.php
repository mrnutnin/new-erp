<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wms_issue_returns')) {
            Schema::table('wms_issue_returns', function (Blueprint $table): void {
                $table->enum('status', ['DRAFT', 'APPROVED', 'POSTED', 'VOID', 'REVERSED'])->default('DRAFT')->change();
                $table->foreignId('reversed_by')->nullable()->after('posted_by')->constrained('users')->nullOnDelete();
                $table->timestamp('reversed_at')->nullable()->after('reversed_by');
                $table->string('reversal_reason', 500)->nullable()->after('reversed_at');
                $table->unsignedInteger('reversal_revision')->default(0)->after('reversal_reason');
            });
        }

        if (Schema::hasTable('wms_issue_return_lines')) {
            Schema::table('wms_issue_return_lines', function (Blueprint $table): void {
                $table->foreignId('reversal_movement_id')->nullable()->after('stock_movement_id')->constrained('wms_stock_movements')->restrictOnDelete();
                $table->foreignId('reversal_allocation_id')->nullable()->after('cost_allocation_id')->constrained('wms_cost_allocations')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wms_issue_return_lines')) {
            Schema::table('wms_issue_return_lines', function (Blueprint $table): void {
                $table->dropForeign(['reversal_movement_id']);
                $table->dropForeign(['reversal_allocation_id']);
                $table->dropColumn(['reversal_movement_id', 'reversal_allocation_id']);
            });
        }

        if (Schema::hasTable('wms_issue_returns')) {
            Schema::table('wms_issue_returns', function (Blueprint $table): void {
                $table->dropForeign(['reversed_by']);
                $table->dropColumn(['reversed_by', 'reversed_at', 'reversal_reason', 'reversal_revision']);
                $table->enum('status', ['DRAFT', 'APPROVED', 'POSTED', 'VOID'])->default('DRAFT')->change();
            });
        }
    }
};
