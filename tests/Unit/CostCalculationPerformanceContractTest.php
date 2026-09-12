<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CostCalculationPerformanceContractTest extends TestCase
{
    public function test_timeline_uses_sargable_keyset_query_with_bounded_selected_rows(): void
    {
        $reader = file_get_contents($this->path('app/Modules/Wms/Services/CostTimelineReader.php'));
        $migration = file_get_contents($this->path('database/migrations/2026_09_08_030000_add_cost_timeline_partition_index.php'));

        self::assertStringNotContainsString('whereDate(', $reader);
        self::assertStringContainsString("->where('business_date', '>=',", $reader);
        self::assertStringContainsString('->limit($limit + 1)->get([', $reader);
        self::assertStringContainsString("['warehouse_id', 'item_id', 'uom_id', 'method', 'business_date', 'stock_movement_id', 'id']", $migration);
    }

    public function test_read_only_explorer_and_shadow_queries_are_bounded_and_sargable(): void
    {
        $explorer = file_get_contents($this->path('app/Modules/Wms/Services/CostLineageExplorer.php'));
        $shadow = file_get_contents($this->path('app/Modules/Wms/Services/CostShadowCalculationService.php'));

        self::assertStringContainsString('limit(max(1, min($limit, 5000)))', $explorer);
        self::assertStringContainsString('get($this->allocationColumns())', $explorer);
        self::assertStringNotContainsString('whereDate(', $shadow);
        self::assertStringNotContainsString('->get();', $shadow);
        self::assertStringContainsString('$this->timelines->read(', $shadow);
        self::assertStringContainsString('$this->avgPool->resolve(', $shadow);
        self::assertStringContainsString('$this->fifoLayers->resolve(', $shadow);
        self::assertStringContainsString('$this->directBridges->resolve(', $shadow);
        self::assertStringContainsString('TRANSFER_REPLAY_MAX_DEPTH_REACHED', $shadow);
        self::assertStringContainsString('TRANSFER_REPLAY_CYCLE_DETECTED', $shadow);
        self::assertStringContainsString('TRANSFER_REPLAY_NODE_LIMIT_REACHED', $shadow);
        self::assertStringContainsString("->whereIn('id', \$ids->all())", $shadow);
    }

    public function test_effective_dates_are_batch_primed_with_selected_columns(): void
    {
        $resolver = file_get_contents($this->path('app/Modules/Wms/Services/EffectiveDocumentDateResolver.php'));
        $reader = file_get_contents($this->path('app/Modules/Wms/Services/CostTimelineReader.php'));

        self::assertStringContainsString('public function prime(iterable $allocations)', $resolver);
        self::assertStringNotContainsString('->get();', $resolver);
        self::assertStringContainsString('$this->dates->prime($page);', $reader);
    }

    public function test_fifo_resolver_is_pure_and_only_replays_bounded_input(): void
    {
        $resolver = file_get_contents($this->path('app/Modules/Wms/Services/FifoLayerResolver.php'));

        self::assertStringNotContainsString('::query(', $resolver);
        self::assertStringNotContainsString('DB::', $resolver);
        self::assertStringNotContainsString('->get(', $resolver);
        self::assertStringContainsString('FIFO_LAYER_QUANTITY_EXCEEDED', $resolver);
        self::assertStringContainsString('FIFO_RECOST_REQUIRES_LAYER_REBUILD', $resolver);
        self::assertStringContainsString('BOUNDED_FIFO_ALLOCATION_LEDGER', file_get_contents($this->path('app/Modules/Wms/Services/CostTimelineReader.php')));
    }

    public function test_direct_bridge_discovery_is_batched_selected_and_fan_out_bounded(): void
    {
        $resolver = file_get_contents($this->path('app/Modules/Wms/Services/CostDirectBridgeResolver.php'));

        self::assertStringContainsString('->keys()->chunk(250)', $resolver);
        self::assertStringContainsString('->limit($remaining + 1)', $resolver);
        self::assertStringNotContainsString('->get();', $resolver);
        self::assertStringContainsString('DIRECT_BRIDGE_FAN_OUT_LIMIT_EXCEEDED', $resolver);
    }

    public function test_production_bridge_discovery_is_bounded_selected_and_sargable(): void
    {
        $resolver = file_get_contents($this->path('app/Modules/Wms/Services/ProductionBridgeResolver.php'));

        self::assertStringContainsString("->whereIn('allocation.id', \$parents->keys()->all())", $resolver);
        self::assertStringContainsString('->limit($limit + 1)', $resolver);
        self::assertStringNotContainsString('whereDate(', $resolver);
        self::assertStringNotContainsString('->get();', $resolver);
        self::assertStringContainsString('PRODUCTION_BRIDGE_FAN_OUT_LIMIT_EXCEEDED', $resolver);
        self::assertStringContainsString('PRODUCTION_SOURCE_CONSUMPTION_EXCEEDED', $resolver);
    }

    private function path(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
