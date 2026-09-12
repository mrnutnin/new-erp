<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\CostDirectBridgeResolver;
use Tests\TestCase;

final class CostDirectBridgeResolverTest extends TestCase
{
    public function test_explicit_relations_preserve_sign_quantity_and_target_partition(): void
    {
        $result = app(CostDirectBridgeResolver::class)->compile([$this->parent()], [
            $this->child(2, 'ISSUE_RETURN', 'RETURN', 'IN', '4'),
            $this->child(3, 'POS', 'RETURN', 'IN', '1', ['sales_return_id' => 3], warehouseId: 2),
            $this->child(4, 'RECOST', 'RECOST', 'OUT', '1'),
            $this->child(5, 'INVENTORY', 'ADJUSTMENT', 'OUT', '4', ['reversal_of_movement_id' => 9]),
        ]);

        self::assertTrue($result['ready']);
        self::assertSame(['ISSUE_RETURN', 'SALES_RETURN', 'RECOST', 'REVERSAL'], array_column($result['edges'], 'relation'));
        self::assertSame(['8.00000000', '2.00000000', '-2.00000000', '-8.00000000'], array_column($result['edges'], 'estimated_delta_value'));
        self::assertFalse($result['edges'][0]['cross_partition']);
        self::assertTrue($result['edges'][1]['cross_partition']);
        self::assertSame('2:10:20:AVG', $result['partitions'][0]['partition_key']);
        self::assertSame('2.00000000', $result['partitions'][0]['estimated_delta_value']);
    }

    public function test_unknown_cross_partition_overdraw_and_earlier_child_are_blocked(): void
    {
        $child = $this->child(2, 'UNKNOWN', 'TRANSFER', 'IN', '11', warehouseId: 2);
        $child['business_date'] = '2026-08-31';

        $result = app(CostDirectBridgeResolver::class)->compile([$this->parent()], [$child]);

        self::assertFalse($result['ready']);
        self::assertContains('ALLOCATION_2:DIRECT_BRIDGE_EVENT_UNSUPPORTED', $result['blockers']);
        self::assertContains('ALLOCATION_2:DIRECT_BRIDGE_QUANTITY_EXCEEDED', $result['blockers']);
        self::assertContains('ALLOCATION_2:DIRECT_BRIDGE_BEFORE_PARENT_DATE', $result['blockers']);
    }

    public function test_transfer_accept_crosses_partition_and_reject_returns_to_source_partition(): void
    {
        $result = app(CostDirectBridgeResolver::class)->compile([$this->parent()], [
            $this->child(2, 'WMS_TRANSFER', 'TRANSFER', 'IN', '4', ['transfer_event' => 'ACCEPT'], warehouseId: 2),
            $this->child(3, 'WMS_TRANSFER', 'TRANSFER', 'IN', '6', ['transfer_event' => 'REJECT']),
        ]);

        self::assertTrue($result['ready']);
        self::assertSame(['TRANSFER_ACCEPT', 'TRANSFER_REJECT'], array_column($result['edges'], 'relation'));
        self::assertSame(['8.00000000', '12.00000000'], array_column($result['edges'], 'estimated_delta_value'));
        self::assertTrue($result['edges'][0]['cross_partition']);
        self::assertFalse($result['edges'][1]['cross_partition']);
        self::assertSame('2:10:20:AVG', $result['partitions'][0]['partition_key']);
        self::assertSame([2], $result['partitions'][0]['root_allocation_ids']);
    }

    public function test_full_purchase_return_is_an_explicit_reversal_bridge(): void
    {
        $result = app(CostDirectBridgeResolver::class)->compile([$this->parent()], [
            $this->child(2, 'PURCHASING', 'RECEIPT', 'OUT', '10', [
                'credit_note_mode' => 'RETURN',
                'purchase_return_mode' => 'FULL',
                'reversal_of_movement_id' => 9,
            ]),
        ]);

        self::assertTrue($result['ready']);
        self::assertSame('PURCHASE_RETURN_FULL', $result['edges'][0]['relation']);
        self::assertSame('-20.00000000', $result['edges'][0]['estimated_delta_value']);
        self::assertFalse($result['edges'][0]['cross_partition']);
    }

    /** @return array<string, mixed> */
    private function parent(): array
    {
        return [
            'allocation_id' => 1,
            'warehouse_id' => 1,
            'item_id' => 10,
            'uom_id' => 20,
            'method' => 'AVG',
            'direction' => 'OUT',
            'quantity' => '10',
            'estimated_delta_value' => '-20',
            'business_date' => '2026-09-01',
        ];
    }

    /** @return array<string, mixed> */
    private function child(int $id, string $sourceType, string $allocationType, string $direction, string $quantity, array $metadata = [], int $warehouseId = 1): array
    {
        return [
            'allocation_id' => $id,
            'parent_allocation_id' => 1,
            'warehouse_id' => $warehouseId,
            'item_id' => 10,
            'uom_id' => 20,
            'method' => 'AVG',
            'direction' => $direction,
            'allocation_type' => $allocationType,
            'quantity' => $quantity,
            'business_date' => '2026-09-02',
            'source_type' => $sourceType,
            'source_reference' => 'TEST',
            'metadata' => $metadata,
            'blockers' => [],
            'warnings' => [],
        ];
    }
}
