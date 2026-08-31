<?php

namespace Tests\Unit;

use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Services\StockCostPolicyResolver;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class StockCostPolicyResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_shortage_returns_pending_provisional_cost_from_current_average(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('inventory_costing_method')->andReturn('FIFO');
        $settings->shouldReceive('value')->with('allow_negative_stock')->andReturn(true);
        $settings->shouldReceive('value')->with('negative_stock_cost_method')->andReturn('CURRENT_AVERAGE');

        $result = (new StockCostPolicyResolver($settings))->resolveIssue('2', '5', '10.25');

        $this->assertSame('PENDING', $result['status']);
        $this->assertSame('3.00000000', $result['shortfall_quantity']);
        $this->assertSame('10.25000000', $result['unit_cost']);
        $this->assertSame('30.75000000', $result['value']);
    }

    public function test_shortage_is_rejected_when_negative_stock_is_disabled(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('inventory_costing_method')->andReturn('AVG');
        $settings->shouldReceive('value')->with('allow_negative_stock')->andReturn(false);

        $this->expectException(ValidationException::class);
        (new StockCostPolicyResolver($settings))->resolveIssue('2', '5');
    }

    public function test_issue_within_on_hand_does_not_create_provisional_cost(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('inventory_costing_method')->andReturn('AVG');

        $result = (new StockCostPolicyResolver($settings))->resolveIssue('5', '2');

        $this->assertSame('FINAL', $result['status']);
        $this->assertSame('0.00000000', $result['shortfall_quantity']);
        $this->assertNull($result['unit_cost']);
    }

    public function test_existing_negative_on_hand_accumulates_shortfall(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('inventory_costing_method')->andReturn('AVG');
        $settings->shouldReceive('value')->with('allow_negative_stock')->andReturn(true);
        $settings->shouldReceive('value')->with('negative_stock_cost_method')->andReturn('CURRENT_AVERAGE');

        $result = (new StockCostPolicyResolver($settings))->resolveIssue('-2', '3', '10');

        $this->assertSame('PENDING', $result['status']);
        $this->assertSame('5.00000000', $result['shortfall_quantity']);
    }
}
