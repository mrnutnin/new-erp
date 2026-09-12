<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wms_cost_revaluation_deltas')) {
            return;
        }

        Schema::table('wms_cost_revaluation_deltas', function (Blueprint $table): void {
            if (! Schema::hasColumn('wms_cost_revaluation_deltas', 'applied_cost_allocation_id')) {
                $table->foreignId('applied_cost_allocation_id')->nullable()->after('allocation_id')->constrained('wms_cost_allocations')->restrictOnDelete();
            }
            if (! Schema::hasColumn('wms_cost_revaluation_deltas', 'applied_at')) {
                $table->timestamp('applied_at')->nullable()->after('delta_value');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wms_cost_revaluation_deltas')) {
            return;
        }

        Schema::table('wms_cost_revaluation_deltas', function (Blueprint $table): void {
            if (Schema::hasColumn('wms_cost_revaluation_deltas', 'applied_cost_allocation_id')) {
                $table->dropForeign(['applied_cost_allocation_id']);
                $table->dropColumn('applied_cost_allocation_id');
            }
            if (Schema::hasColumn('wms_cost_revaluation_deltas', 'applied_at')) {
                $table->dropColumn('applied_at');
            }
        });
    }
};
