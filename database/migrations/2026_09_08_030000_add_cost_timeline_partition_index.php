<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'wms_ca_timeline_partition_idx';

    public function up(): void
    {
        if (! Schema::hasTable('wms_cost_allocations') || $this->exists()) {
            return;
        }

        Schema::table('wms_cost_allocations', function (Blueprint $table): void {
            $table->index(
                ['warehouse_id', 'item_id', 'uom_id', 'method', 'business_date', 'stock_movement_id', 'id'],
                self::INDEX,
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wms_cost_allocations') || ! $this->exists()) {
            return;
        }

        Schema::table('wms_cost_allocations', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
    }

    private function exists(): bool
    {
        return collect(Schema::getIndexes('wms_cost_allocations'))->contains('name', self::INDEX);
    }
};
