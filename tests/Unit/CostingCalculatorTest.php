<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\CostingCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CostingCalculatorTest extends TestCase
{
    public function test_average_calculates_weighted_cost(): void
    {
        $result = CostingCalculator::average('10', '5', '5', '8');
        $this->assertSame('15.00000000', $result['quantity']);
        $this->assertSame('6.00000000', $result['unit_cost']);
    }

    public function test_fifo_consumes_oldest_layers_first(): void
    {
        $result = CostingCalculator::fifoIssue([['quantity' => '3', 'unit_cost' => '10'], ['quantity' => '4', 'unit_cost' => '12']], '5');
        $this->assertSame([0, 1], array_column($result, 'layer'));
        $this->assertSame(['30.00000000', '24.00000000'], array_column($result, 'value'));
    }

    public function test_fifo_rejects_insufficient_layers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CostingCalculator::fifoIssue([['quantity' => '1', 'unit_cost' => '10']], '2');
    }

    public function test_average_issue_reduces_value_at_current_average_cost(): void
    {
        $result = CostingCalculator::averageIssue('15', '6', '5');

        $this->assertSame('10.00000000', $result['quantity']);
        $this->assertSame('60.00000000', $result['value']);
        $this->assertSame('6.00000000', $result['unit_cost']);
    }
}
