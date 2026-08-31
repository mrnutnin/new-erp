<?php

namespace Tests\Unit;

use Tests\TestCase;

final class StockRecostInventoryDeltaContractTest extends TestCase
{
    public function test_recost_updates_inventory_with_the_contra_side_of_the_cogs_delta(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/StockRecostService.php'));

        $this->assertStringContainsString('$balanceDelta = $balanceDelta->minus(BigDecimal::of($result[\'cost_delta\']));', $source);
        $this->assertStringContainsString('actual issue cost - provisional issue cost', $source);
    }
}
