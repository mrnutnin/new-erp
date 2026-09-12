<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\CostImpactClassifier;
use App\Modules\Wms\Services\CostPropagationTriggerPlanner;
use App\Modules\Wms\Services\EffectiveDocumentDateResolver;
use PHPUnit\Framework\TestCase;

final class CostPropagationTriggerPlannerTest extends TestCase
{
    public function test_it_combines_same_partition_without_losing_root_lines_or_allocations(): void
    {
        $plan = $this->planner()->compile($this->source([10, 11]), [
            $this->row(10, 100, 1000),
            $this->row(11, 101, 1001),
        ]);

        self::assertTrue($plan['read_only']);
        self::assertFalse($plan['dispatch']);
        self::assertTrue($plan['summary']['ready']);
        self::assertSame(2, $plan['summary']['resolved_root_lines']);
        self::assertSame(1, $plan['summary']['expected_partitions']);
        self::assertSame([10, 11], $plan['partitions'][0]['root_line_ids']);
        self::assertSame([1000, 1001], $plan['partitions'][0]['root_allocation_ids']);
    }

    public function test_it_creates_one_partition_per_warehouse_item_uom_and_method(): void
    {
        $plan = $this->planner()->compile($this->source([10, 11, 12]), [
            $this->row(10, 100, 1000),
            $this->row(11, 101, 1001, itemId: 8),
            $this->row(12, 102, 1002, warehouseId: 2),
        ]);

        self::assertSame(3, $plan['summary']['expected_partitions']);
        self::assertSame(['1:7:9:AVG', '1:8:9:AVG', '2:7:9:AVG'], array_column($plan['partitions'], 'partition_key'));
    }

    public function test_identity_and_order_are_stable_on_retry(): void
    {
        $rows = [$this->row(11, 101, 1001), $this->row(10, 100, 1000)];
        $first = $this->planner()->compile($this->source([10, 11]), $rows);
        $second = $this->planner()->compile($this->source([11, 10]), array_reverse($rows));

        self::assertSame($first['source']['trigger_identity'], $second['source']['trigger_identity']);
        self::assertSame($first['partitions'], $second['partitions']);
    }

    public function test_missing_line_and_allocation_blocker_prevent_readiness(): void
    {
        $row = $this->row(10, 100, 1000);
        $row['blockers'] = ['PENDING_COST'];
        $plan = $this->planner()->compile($this->source([10, 11]), [$row]);

        self::assertFalse($plan['summary']['ready']);
        self::assertContains('ROOT_LINE_MISSING:11', $plan['blockers']);
        self::assertContains('ALLOCATION_1000:PENDING_COST', $plan['blockers']);
        self::assertFalse($plan['partitions'][0]['ready']);
    }

    public function test_movement_without_allocation_and_document_without_stock_lines_are_not_ready(): void
    {
        $source = $this->source([10]);
        $source['movement_lines'] = [100 => 10, 101 => 10];
        $plan = $this->planner()->compile($source, [$this->row(10, 100, 1000)]);

        self::assertFalse($plan['summary']['ready']);
        self::assertContains('ROOT_ALLOCATION_MISSING:101', $plan['blockers']);

        $empty = $this->planner()->compile($this->source([]), []);
        self::assertFalse($empty['summary']['ready']);
        self::assertContains('SOURCE_HAS_NO_STOCK_LINES', $empty['blockers']);
    }

    private function planner(): CostPropagationTriggerPlanner
    {
        return new CostPropagationTriggerPlanner(new EffectiveDocumentDateResolver, new CostImpactClassifier);
    }

    /** @return array<string, mixed> */
    private function source(array $lineIds): array
    {
        return [
            'document_type' => 'ISSUE_DOCUMENT',
            'document_id' => 55,
            'document_reference' => 'ISSUE-55',
            'document_date' => '2026-09-07',
            'status' => 'POSTED',
            'revision' => 0,
            'line_ids' => $lineIds,
            'blockers' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function row(int $lineId, int $movementId, int $allocationId, int $warehouseId = 1, int $itemId = 7): array
    {
        return [
            'line_id' => $lineId,
            'movement_id' => $movementId,
            'allocation_id' => $allocationId,
            'warehouse_id' => $warehouseId,
            'branch_id' => 1,
            'item_id' => $itemId,
            'uom_id' => 9,
            'method' => 'AVG',
            'effective_date' => '2026-09-07',
            'blockers' => [],
        ];
    }
}
