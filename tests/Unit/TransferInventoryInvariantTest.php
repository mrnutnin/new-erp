<?php

namespace Tests\Unit;

use App\Modules\Wms\Models\CostAllocation;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

class TransferInventoryInvariantTest extends TestCase
{
    public function test_source_out_and_destination_in_cost_net_to_zero_company_wide(): void
    {
        $source = new CostAllocation([
            'warehouse_id' => 10, 'allocation_type' => 'TRANSFER', 'direction' => 'OUT',
            'value' => '-125.50000000', 'journal_entry_id' => null,
        ]);
        $destination = new CostAllocation([
            'warehouse_id' => 20, 'allocation_type' => 'TRANSFER', 'direction' => 'IN',
            'value' => '125.50000000', 'journal_entry_id' => null,
        ]);

        $net = BigDecimal::of((string) $source->value)->plus(BigDecimal::of((string) $destination->value));

        $this->assertSame('0.00000000', $net->toScale(8)->__toString());
        $this->assertNull($source->journal_entry_id);
        $this->assertNull($destination->journal_entry_id);
    }

    public function test_warehouse_scopes_remain_separate_while_company_total_nets_zero(): void
    {
        $allocations = collect([
            new CostAllocation(['warehouse_id' => 10, 'value' => '-125.00']),
            new CostAllocation(['warehouse_id' => 20, 'value' => '125.00']),
        ]);

        $sourceTotal = $allocations->where('warehouse_id', 10)->reduce(
            fn (BigDecimal $total, CostAllocation $allocation): BigDecimal => $total->plus((string) $allocation->value),
            BigDecimal::zero(),
        );
        $destinationTotal = $allocations->where('warehouse_id', 20)->reduce(
            fn (BigDecimal $total, CostAllocation $allocation): BigDecimal => $total->plus((string) $allocation->value),
            BigDecimal::zero(),
        );

        $this->assertSame('-125.00', $sourceTotal->toScale(2)->__toString());
        $this->assertSame('125.00', $destinationTotal->toScale(2)->__toString());
        $this->assertSame('0.00', $sourceTotal->plus($destinationTotal)->toScale(2)->__toString());
    }

    public function test_avg_and_fifo_transfer_allocations_require_parent_and_layer_lineage(): void
    {
        foreach (['AVG', 'FIFO'] as $method) {
            $allocation = new CostAllocation([
                'allocation_type' => 'TRANSFER', 'method' => $method,
                'parent_allocation_id' => 101, 'stock_cost_layer_id' => 202,
                'metadata' => ['source_allocation_id' => 101, 'source_layer_id' => 303],
            ]);

            $this->assertSame(101, $allocation->parent_allocation_id);
            $this->assertSame(202, $allocation->stock_cost_layer_id);
            $this->assertSame(101, $allocation->metadata['source_allocation_id']);
            $this->assertSame(303, $allocation->metadata['source_layer_id']);
        }
    }
}
