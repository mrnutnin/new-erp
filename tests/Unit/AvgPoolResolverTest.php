<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\AvgPoolResolver;
use PHPUnit\Framework\TestCase;

final class AvgPoolResolverTest extends TestCase
{
    public function test_replays_weighted_receipt_and_issue_without_delta(): void
    {
        $result = (new AvgPoolResolver)->resolve($this->anchor(), [
            $this->row(1, 10, 'IN', 'RECEIPT', '5', '40'),
            $this->row(2, 11, 'OUT', 'ISSUE', '3', '-18'),
        ]);

        self::assertTrue($result['ready']);
        self::assertSame('12.00000000', $result['summary']['ending_new']['quantity']);
        self::assertSame('72.00000000', $result['summary']['ending_new']['value']);
        self::assertSame('6.00000000', $result['summary']['ending_new']['average_unit_cost']);
        self::assertSame('0.00000000', $result['summary']['ending_delta_value']);
        self::assertTrue($result['summary']['converged']);
    }

    public function test_final_issue_zeroes_terminal_avg_pool_value(): void
    {
        $result = (new AvgPoolResolver)->resolve($this->anchor(), [
            $this->row(1, 10, 'OUT', 'ISSUE', '10', '-50'),
        ]);

        self::assertSame('0.00000000', $result['summary']['ending_new']['quantity']);
        self::assertSame('0.00000000', $result['summary']['ending_new']['value']);
        self::assertSame('0.00000000', $result['summary']['ending_new']['average_unit_cost']);
    }

    public function test_receipt_override_propagates_new_average_to_later_issue(): void
    {
        $result = (new AvgPoolResolver)->resolve($this->anchor(), [
            $this->row(1, 10, 'IN', 'RECEIPT', '5', '40'),
            $this->row(2, 11, 'OUT', 'ISSUE', '3', '-18'),
        ], [1 => ['unit_cost' => '10']]);

        self::assertTrue($result['ready']);
        self::assertSame('50.00000000', $result['rows'][0]['event']['new_value']);
        self::assertSame('-20.00000001', $result['rows'][1]['event']['new_value']);
        self::assertSame('-2.00000001', $result['rows'][1]['event']['delta_value']);
        self::assertSame('79.99999999', $result['summary']['ending_new']['value']);
        self::assertSame('7.99999999', $result['summary']['ending_delta_value']);
        self::assertSame(2, $result['summary']['nodes_affected']);
    }

    public function test_recost_changes_value_without_changing_quantity(): void
    {
        $result = (new AvgPoolResolver)->resolve($this->anchor(), [
            $this->row(1, 10, 'IN', 'RECOST', '2', '4'),
        ]);

        self::assertSame('10.00000000', $result['summary']['ending_new']['quantity']);
        self::assertSame('54.00000000', $result['summary']['ending_new']['value']);
        self::assertSame('5.40000000', $result['summary']['ending_new']['average_unit_cost']);
    }

    public function test_split_allocations_count_movement_quantity_only_once(): void
    {
        $first = $this->row(1, 10, 'IN', 'RETURN', '5', '40');
        $first['pool_quantity'] = '5';
        $second = $this->row(2, 10, 'IN', 'RETURN', '5', '10');
        $second['pool_quantity'] = '0';

        $result = (new AvgPoolResolver)->resolve($this->anchor(), [$first, $second]);

        self::assertSame('15.00000000', $result['summary']['ending_new']['quantity']);
        self::assertSame('100.00000000', $result['summary']['ending_new']['value']);
        self::assertSame('6.66666667', $result['summary']['ending_new']['average_unit_cost']);
    }

    public function test_explicit_parent_delta_propagates_to_partial_return(): void
    {
        $parent = $this->row(1, 10, 'OUT', 'ISSUE', '10', '-50');
        $child = $this->row(2, 11, 'IN', 'RETURN', '4', '20');
        $child['parent_allocation_id'] = 1;

        $result = (new AvgPoolResolver)->resolve(['status' => 'READY', 'quantity' => '20', 'value' => '100', 'blockers' => []], [$parent, $child], [1 => ['unit_cost' => '6']]);

        self::assertSame('-10.00000000', $result['rows'][0]['event']['delta_value']);
        self::assertSame('4.00000000', $result['rows'][1]['event']['delta_value']);
        self::assertSame('1.00000000', $result['summary']['propagation'][2]);
    }

    public function test_parent_delta_survives_page_continuation(): void
    {
        $first = (new AvgPoolResolver)->resolve(['status' => 'READY', 'quantity' => '20', 'value' => '100', 'blockers' => []], [
            $this->row(1, 10, 'OUT', 'ISSUE', '10', '-50'),
        ], [1 => ['unit_cost' => '6']]);
        $child = $this->row(2, 11, 'IN', 'RETURN', '4', '20');
        $child['parent_allocation_id'] = 1;

        $second = (new AvgPoolResolver)->resolve([
            'status' => 'READY',
            'old' => $first['summary']['ending_old'],
            'new' => $first['summary']['ending_new'],
            'propagation' => $first['summary']['propagation'],
        ], [$child]);

        self::assertSame('4.00000000', $second['rows'][0]['event']['delta_value']);
    }

    public function test_continuation_keeps_separate_old_and_new_pool_states(): void
    {
        $first = (new AvgPoolResolver)->resolve($this->anchor(), [
            $this->row(1, 10, 'IN', 'RECEIPT', '5', '40'),
        ], [1 => ['unit_cost' => '10']]);
        $second = (new AvgPoolResolver)->resolve([
            'status' => 'READY',
            'blockers' => [],
            'old' => $first['summary']['ending_old'],
            'new' => $first['summary']['ending_new'],
        ], [$this->row(2, 11, 'OUT', 'ISSUE', '3', '-18')]);

        self::assertSame('-20.00000001', $second['rows'][0]['event']['new_value']);
        self::assertSame('7.99999999', $second['summary']['ending_delta_value']);
    }

    public function test_anchor_or_timeline_blocker_stops_partition_without_throwing_worker_error(): void
    {
        $anchor = $this->anchor();
        $anchor['status'] = 'REQUIRES_REBUILD';
        $anchor['blockers'] = ['ANCHOR_LIMIT_EXCEEDED'];
        $blockedAnchor = (new AvgPoolResolver)->resolve($anchor, [$this->row(1, 10, 'IN', 'RECEIPT', '1', '5')]);

        self::assertFalse($blockedAnchor['ready']);
        self::assertSame([], $blockedAnchor['rows']);
        self::assertContains('HISTORICAL_ANCHOR_NOT_READY', $blockedAnchor['blockers']);

        $row = $this->row(1, 10, 'IN', 'RECEIPT', '1', '5');
        $row['blockers'] = ['PENDING_COST'];
        $blockedRow = (new AvgPoolResolver)->resolve($this->anchor(), [$row]);
        self::assertFalse($blockedRow['ready']);
        self::assertContains('ALLOCATION_1:PENDING_COST', $blockedRow['blockers']);
    }

    public function test_out_of_order_or_duplicate_timeline_is_blocked(): void
    {
        $result = (new AvgPoolResolver)->resolve($this->anchor(), [
            $this->row(2, 11, 'IN', 'RECEIPT', '1', '5'),
            $this->row(1, 10, 'IN', 'RECEIPT', '1', '5'),
        ]);

        self::assertFalse($result['ready']);
        self::assertContains('ALLOCATION_1:TIMELINE_ORDER_INVALID', $result['blockers']);
    }

    public function test_invalid_ledger_value_direction_is_blocked(): void
    {
        $result = (new AvgPoolResolver)->resolve($this->anchor(), [
            $this->row(1, 10, 'OUT', 'ISSUE', '1', '5'),
        ]);

        self::assertFalse($result['ready']);
        self::assertContains('ALLOCATION_1:VALUE_DIRECTION_MISMATCH', $result['blockers']);
    }

    /** @return array<string, mixed> */
    private function anchor(): array
    {
        return ['status' => 'READY', 'quantity' => '10', 'value' => '50', 'blockers' => []];
    }

    /** @return array<string, mixed> */
    private function row(int $allocationId, int $movementId, string $direction, string $type, string $quantity, string $value): array
    {
        return [
            'allocation_id' => $allocationId,
            'movement_id' => $movementId,
            'business_date' => '2026-09-01',
            'effective_date' => '2026-09-01',
            'direction' => $direction,
            'allocation_type' => $type,
            'quantity' => $quantity,
            'value' => $value,
            'blockers' => [],
            'impact' => ['impact_bucket' => $direction === 'IN' ? 'INVENTORY_ON_HAND' : 'COGS_CONSUMED'],
            'cursor' => ['business_date' => '2026-09-01', 'movement_id' => $movementId, 'allocation_id' => $allocationId],
        ];
    }
}
