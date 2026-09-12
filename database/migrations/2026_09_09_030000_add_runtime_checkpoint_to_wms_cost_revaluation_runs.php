<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wms_cost_revaluation_runs')) {
            return;
        }

        Schema::table('wms_cost_revaluation_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('wms_cost_revaluation_runs', 'runtime_checkpoint')) {
                $table->json('runtime_checkpoint')->nullable()->after('shadow_snapshot');
            }
            if (! Schema::hasColumn('wms_cost_revaluation_runs', 'nodes_scanned')) {
                $table->unsignedBigInteger('nodes_scanned')->default(0)->after('nodes_affected');
            }
            if (! Schema::hasColumn('wms_cost_revaluation_runs', 'heartbeat_at')) {
                $table->timestamp('heartbeat_at')->nullable()->after('applied_at');
            }
        });

        if (! collect(Schema::getIndexes('wms_cost_revaluation_runs'))->contains('name', 'wms_reval_runs_runtime_idx')) {
            Schema::table('wms_cost_revaluation_runs', fn (Blueprint $table) => $table->index(['status', 'heartbeat_at', 'id'], 'wms_reval_runs_runtime_idx'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('wms_cost_revaluation_runs')) {
            return;
        }

        Schema::table('wms_cost_revaluation_runs', function (Blueprint $table): void {
            if (collect(Schema::getIndexes('wms_cost_revaluation_runs'))->contains('name', 'wms_reval_runs_runtime_idx')) {
                $table->dropIndex('wms_reval_runs_runtime_idx');
            }
            foreach (['runtime_checkpoint', 'nodes_scanned', 'heartbeat_at'] as $column) {
                if (Schema::hasColumn('wms_cost_revaluation_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
