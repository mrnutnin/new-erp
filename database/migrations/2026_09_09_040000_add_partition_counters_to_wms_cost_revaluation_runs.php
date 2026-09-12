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
            foreach (['expected_partitions', 'completed_partitions', 'failed_partitions'] as $column) {
                if (! Schema::hasColumn('wms_cost_revaluation_runs', $column)) {
                    $table->unsignedInteger($column)->default($column === 'expected_partitions' ? 1 : 0)->after('nodes_scanned');
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wms_cost_revaluation_runs')) {
            return;
        }
        Schema::table('wms_cost_revaluation_runs', function (Blueprint $table): void {
            foreach (['failed_partitions', 'completed_partitions', 'expected_partitions'] as $column) {
                if (Schema::hasColumn('wms_cost_revaluation_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
