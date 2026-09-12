<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\ProductionBridgeResolver;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

final class ProductionBridgeResolverTest extends TestCase
{
    public function test_many_material_allocations_split_into_many_outputs_with_deterministic_residual(): void
    {
        $parents = [$this->parent(1, '-1.00000001'), $this->parent(2, '-2.00000002')];
        $links = [];
        foreach ([1, 2] as $parentId) {
            $links[] = $this->outputRow($parentId, 10, '33.33333333');
            $links[] = $this->outputRow($parentId, 11, '33.33333333');
            $links[] = $this->outputRow($parentId, 12, '33.33333334');
        }

        $result = (new ProductionBridgeResolver)->compile($parents, $links);

        self::assertTrue($result['ready']);
        self::assertCount(6, $result['edges']);
        self::assertSame(['0.33333334', '0.33333334', '0.33333333'], array_column(array_slice($result['edges'], 0, 3), 'estimated_delta_value'));
        self::assertSame('1.00000001', $this->sum(array_slice($result['edges'], 0, 3), 'estimated_delta_value'));
        self::assertSame('3.00000003', $this->sum($result['partitions'], 'estimated_delta_value'));
    }

    public function test_partial_output_keeps_unconverted_wip_and_over_allocation_is_blocked(): void
    {
        $partial = (new ProductionBridgeResolver)->compile([$this->parent(1, '-10')], [$this->outputRow(1, 10, '40', 8, '40', '40')]);
        $over = (new ProductionBridgeResolver)->compile([$this->parent(1, '-10')], [$this->outputRow(1, 10, '101', 8, '101', '101')]);

        self::assertTrue($partial['ready']);
        self::assertSame('4.00000000', $partial['edges'][0]['estimated_delta_value']);
        self::assertFalse($over['ready']);
        self::assertContains('ALLOCATION_1:PRODUCTION_SOURCE_CONSUMPTION_EXCEEDED', $over['blockers']);
        self::assertSame([], $over['edges']);
    }

    public function test_many_issues_can_feed_many_receipts_without_losing_each_source_identity(): void
    {
        $parents = [$this->parent(1, '-10'), $this->parent(2, '-20')];
        $links = [
            $this->outputRow(1, 10, '10', 81, '40', '40'),
            $this->outputRow(1, 11, '30', 81, '40', '40'),
            $this->outputRow(1, 12, '60', 82, '60', '60'),
            $this->outputRow(2, 20, '25', 83, '100', '100'),
            $this->outputRow(2, 21, '75', 83, '100', '100'),
        ];

        $result = (new ProductionBridgeResolver)->compile($parents, $links);

        self::assertTrue($result['ready']);
        self::assertSame('10.00000000', $this->sum(array_slice($result['edges'], 0, 3), 'estimated_delta_value'));
        self::assertSame('20.00000000', $this->sum(array_slice($result['edges'], 3), 'estimated_delta_value'));
        self::assertSame([81, 81, 82, 83, 83], array_column($result['edges'], 'receipt_document_id'));
    }

    public function test_one_receipt_can_combine_multiple_issue_allocations_by_persisted_consumption(): void
    {
        $parents = [$this->parent(1, '-10'), $this->parent(2, '-20')];
        $links = [
            $this->outputRow(1, 10, '25', 90, '40', '100'),
            $this->outputRow(1, 11, '75', 90, '40', '100'),
            $this->outputRow(2, 10, '25', 90, '60', '100'),
            $this->outputRow(2, 11, '75', 90, '60', '100'),
        ];

        $result = (new ProductionBridgeResolver)->compile($parents, $links);

        self::assertTrue($result['ready']);
        self::assertSame('4.00000000', $this->sum(array_slice($result['edges'], 0, 2), 'estimated_delta_value'));
        self::assertSame('12.00000000', $this->sum(array_slice($result['edges'], 2), 'estimated_delta_value'));
        self::assertSame([10, 11, 10, 11], array_column($result['edges'], 'child_allocation_id'));
    }

    public function test_inconsistent_quantity_value_split_and_receipt_total_are_blocked(): void
    {
        $ratioMismatch = $this->outputRow(1, 10, '50', 8, '50', '50');
        $ratioMismatch['consumed_quantity'] = '40';
        $receiptMismatch = $this->outputRow(1, 10, '49', 8, '50', '50');
        $receiptMismatch['receipt_output_total_value'] = '49';

        $ratioResult = (new ProductionBridgeResolver)->compile([$this->parent(1, '-10')], [$ratioMismatch]);
        $receiptResult = (new ProductionBridgeResolver)->compile([$this->parent(1, '-10')], [$receiptMismatch]);

        self::assertContains('ALLOCATION_1:PRODUCTION_SOURCE_SPLIT_MISMATCH', $ratioResult['blockers']);
        self::assertContains('RECEIPT_8:PRODUCTION_RECEIPT_VALUE_MISMATCH', $receiptResult['blockers']);
        self::assertSame([], $ratioResult['edges']);
        self::assertSame([], $receiptResult['edges']);
    }

    /** @return array<string,mixed> */
    private function parent(int $id, string $delta): array
    {
        return ['allocation_id' => $id, 'warehouse_id' => 1, 'item_id' => $id, 'uom_id' => 1, 'method' => 'AVG', 'business_date' => '2026-09-01', 'impact_bucket' => 'WIP_CONSUMED', 'estimated_delta_value' => $delta];
    }

    /** @return array<string,mixed> */
    private function outputRow(int $parentId, int $outputId, string $value, int $receiptId = 8, string $consumedValue = '100', string $receiptTotal = '100'): array
    {
        return ['source_allocation_id' => $parentId, 'issue_document_id' => 7, 'source_allocation_quantity' => '100', 'source_allocation_value' => '100', 'consumed_quantity' => $consumedValue, 'consumed_value' => $consumedValue, 'receipt_source_total_value' => $receiptTotal, 'receipt_output_total_value' => $receiptTotal, 'receipt_document_id' => $receiptId, 'output_allocation_id' => $outputId, 'output_value' => $value, 'business_date' => '2026-09-02', 'warehouse_id' => 1, 'item_id' => 100 + $outputId, 'uom_id' => 1, 'method' => 'AVG', 'quantity' => '1', 'source_reference' => 'FGR-1'];
    }

    /** @param list<array<string,mixed>> $rows */
    private function sum(array $rows, string $field): string
    {
        return array_reduce($rows, fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row[$field]), BigDecimal::zero())->toScale(8)->__toString();
    }
}
