<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\FifoLayerResolver;
use PHPUnit\Framework\TestCase;

final class FifoLayerResolverTest extends TestCase
{
    public function test_receipt_override_changes_only_its_later_fifo_consumption_and_reconciles(): void
    {
        $result = (new FifoLayerResolver)->resolve($this->anchor(), [
            $this->row(10, 100, 3, 'IN', 'RECEIPT', '4', '30', '120'),
            $this->row(11, 101, 1, 'OUT', 'ISSUE', '5', '10', '-50'),
            $this->row(12, 101, 2, 'OUT', 'ISSUE', '3', '20', '-60'),
            $this->row(13, 101, 3, 'OUT', 'ISSUE', '2', '30', '-60'),
        ], [10 => ['unit_cost' => '40']]);

        self::assertTrue($result['ready']);
        self::assertSame('40.00000000', $result['rows'][0]['event']['delta_value']);
        self::assertSame('0.00000000', $result['rows'][1]['event']['delta_value']);
        self::assertSame('0.00000000', $result['rows'][2]['event']['delta_value']);
        self::assertSame('-20.00000000', $result['rows'][3]['event']['delta_value']);
        self::assertSame('20.00000000', $result['summary']['ending_delta_value']);
        self::assertSame(2, $result['summary']['nodes_affected']);
    }

    public function test_partial_layer_consumption_preserves_remaining_quantity(): void
    {
        $result = (new FifoLayerResolver)->resolve($this->anchor(), [
            $this->row(10, 100, 1, 'OUT', 'ISSUE', '2', '10', '-20'),
        ]);

        self::assertSame('3.00000000', $result['summary']['new_layers'][0]['quantity']);
        self::assertSame('13.00000000', $result['summary']['ending_new']['quantity']);
        self::assertTrue($result['summary']['converged']);
    }

    public function test_explicit_parent_delta_sets_partial_return_layer_cost(): void
    {
        $parent = $this->row(10, 100, 1, 'OUT', 'ISSUE', '5', '10', '-50');
        $child = $this->row(11, 101, 3, 'IN', 'RETURN', '2', '10', '20');
        $child['parent_allocation_id'] = 10;

        $result = (new FifoLayerResolver)->resolve($this->anchor(), [$parent, $child], [10 => ['unit_cost' => '12']]);

        self::assertSame('-10.00000000', $result['rows'][0]['event']['delta_value']);
        self::assertSame('4.00000000', $result['rows'][1]['event']['delta_value']);
        self::assertSame('12.00000000', collect($result['summary']['new_layers'])->firstWhere('layer_id', 3)['unit_cost']);
    }

    public function test_continuation_keeps_separate_old_and_new_layers(): void
    {
        $first = (new FifoLayerResolver)->resolve($this->anchor(), [
            $this->row(10, 100, 3, 'IN', 'RECEIPT', '4', '30', '120'),
        ], [10 => ['unit_cost' => '40']]);
        $second = (new FifoLayerResolver)->resolve([
            'status' => 'READY',
            'blockers' => [],
            'old' => ['layers' => $first['summary']['old_layers']],
            'new' => ['layers' => $first['summary']['new_layers']],
        ], [$this->row(11, 101, 3, 'OUT', 'ISSUE', '2', '30', '-60')]);

        self::assertSame('-80.00000000', $second['rows'][0]['event']['new_value']);
        self::assertSame('-20.00000000', $second['rows'][0]['event']['delta_value']);
        self::assertSame('20.00000000', $second['summary']['ending_delta_value']);
    }

    public function test_missing_or_overdrawn_layer_stops_partition_without_throwing(): void
    {
        $missing = (new FifoLayerResolver)->resolve($this->anchor(), [
            $this->row(10, 100, 99, 'OUT', 'ISSUE', '1', '10', '-10'),
        ]);
        $overdrawn = (new FifoLayerResolver)->resolve($this->anchor(), [
            $this->row(10, 100, 1, 'OUT', 'ISSUE', '6', '10', '-60'),
        ]);

        self::assertFalse($missing['ready']);
        self::assertContains('ALLOCATION_10:FIFO_LAYER_NOT_IN_ANCHOR_OR_TIMELINE', $missing['blockers']);
        self::assertFalse($overdrawn['ready']);
        self::assertContains('ALLOCATION_10:FIFO_LAYER_QUANTITY_EXCEEDED', $overdrawn['blockers']);
    }

    public function test_recost_and_out_of_order_rows_are_explicitly_blocked(): void
    {
        $recost = (new FifoLayerResolver)->resolve($this->anchor(), [
            $this->row(10, 100, 1, 'IN', 'RECOST', '1', '1', '1'),
        ]);
        $order = (new FifoLayerResolver)->resolve($this->anchor(), [
            $this->row(11, 101, 3, 'IN', 'RECEIPT', '1', '1', '1'),
            $this->row(10, 100, 4, 'IN', 'RECEIPT', '1', '1', '1'),
        ]);

        self::assertContains('ALLOCATION_10:FIFO_RECOST_REQUIRES_LAYER_REBUILD', $recost['blockers']);
        self::assertContains('ALLOCATION_10:TIMELINE_ORDER_INVALID', $order['blockers']);
    }

    /** @return array<string, mixed> */
    private function anchor(): array
    {
        return [
            'status' => 'READY',
            'blockers' => [],
            'layers' => [
                ['layer_id' => 1, 'quantity' => '5', 'unit_cost' => '10'],
                ['layer_id' => 2, 'quantity' => '10', 'unit_cost' => '20'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function row(int $allocationId, int $movementId, int $layerId, string $direction, string $type, string $quantity, string $unitCost, string $value): array
    {
        return [
            'allocation_id' => $allocationId,
            'movement_id' => $movementId,
            'stock_cost_layer_id' => $layerId,
            'business_date' => '2026-09-01',
            'effective_date' => '2026-09-01',
            'direction' => $direction,
            'allocation_type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'value' => $value,
            'blockers' => [],
            'impact' => ['impact_bucket' => $direction === 'IN' ? 'INVENTORY_ON_HAND' : 'COGS_CONSUMED'],
            'cursor' => ['business_date' => '2026-09-01', 'movement_id' => $movementId, 'allocation_id' => $allocationId],
        ];
    }
}
