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

        $hasBucket = Schema::hasColumn('wms_cost_revaluation_deltas', 'impact_bucket');
        $hasEvent = Schema::hasColumn('wms_cost_revaluation_deltas', 'target_event');
        $hasWarehouse = Schema::hasColumn('wms_cost_revaluation_deltas', 'target_warehouse_id');
        $hasBranch = Schema::hasColumn('wms_cost_revaluation_deltas', 'target_branch_id');
        $hasProjection = Schema::hasColumn('wms_cost_revaluation_deltas', 'stock_projection_delta_value');

        Schema::table('wms_cost_revaluation_deltas', function (Blueprint $table) use ($hasBucket, $hasEvent, $hasWarehouse, $hasBranch, $hasProjection): void {
            if (! $hasBucket) {
                $table->string('impact_bucket', 40)->nullable()->after('delta_value');
            }
            if (! $hasEvent) {
                $table->string('target_event', 100)->nullable()->after('impact_bucket');
            }
            if (! $hasWarehouse) {
                $table->unsignedBigInteger('target_warehouse_id')->nullable()->after('target_event');
            }
            if (! $hasBranch) {
                $table->unsignedBigInteger('target_branch_id')->nullable()->after('target_warehouse_id');
            }
            if (! $hasProjection) {
                $table->decimal('stock_projection_delta_value', 20, 8)->default(0)->after('target_branch_id');
            }
        });

        $indexes = collect(Schema::getIndexes('wms_cost_revaluation_deltas'))->pluck('name');
        Schema::table('wms_cost_revaluation_deltas', function (Blueprint $table) use ($indexes): void {
            if (! $indexes->contains('wms_reval_delta_bucket_idx')) {
                $table->index(['run_id', 'impact_bucket'], 'wms_reval_delta_bucket_idx');
            }
            if (! $indexes->contains('wms_reval_delta_scope_idx')) {
                $table->index(['target_warehouse_id', 'target_branch_id'], 'wms_reval_delta_scope_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wms_cost_revaluation_deltas')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('wms_cost_revaluation_deltas'))->pluck('name');
        Schema::table('wms_cost_revaluation_deltas', function (Blueprint $table) use ($indexes): void {
            if ($indexes->contains('wms_reval_delta_bucket_idx')) {
                $table->dropIndex('wms_reval_delta_bucket_idx');
            }
            if ($indexes->contains('wms_reval_delta_scope_idx')) {
                $table->dropIndex('wms_reval_delta_scope_idx');
            }
            foreach (['impact_bucket', 'target_event', 'target_warehouse_id', 'target_branch_id', 'stock_projection_delta_value'] as $column) {
                if (Schema::hasColumn('wms_cost_revaluation_deltas', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
