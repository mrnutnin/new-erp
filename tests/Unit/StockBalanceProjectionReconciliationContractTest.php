<?php

namespace Tests\Unit;

use Tests\TestCase;

class StockBalanceProjectionReconciliationContractTest extends TestCase
{
    public function test_reconciliation_is_read_only_and_uses_business_date_ledgers(): void
    {
        $service = file_get_contents(base_path('app/Modules/Wms/Services/StockBalanceProjectionReconciliationService.php'));
        $controller = file_get_contents(base_path('app/Modules/Wms/Controllers/StockController.php'));
        $valuation = file_get_contents(base_path('app/Modules/Wms/Services/InventoryCostAllocationService.php'));
        $routes = file_get_contents(base_path('app/Modules/Wms/Routes/web.php'));

        self::assertStringContainsString("where('status', 'POSTED')", $service);
        self::assertStringContainsString("where('business_date', '<=', \$asOf)", $service);
        self::assertStringContainsString("'status' => \$status", $service);
        self::assertStringContainsString("'read_only' => true", $service);
        self::assertStringNotContainsString('->update(', $service);
        self::assertStringNotContainsString('->save(', $service);
        self::assertStringContainsString('StockBalanceProjectionReconciliationService', $controller);
        self::assertStringContainsString('CASE WHEN COALESCE(valuation.final_quantity, 0) = 0 THEN 0 ELSE COALESCE(valuation.final_value, 0) END AS inventory_value', $controller);
        self::assertStringContainsString('terminal_marker', $valuation);
        self::assertStringContainsString('SUM(movement_value) OVER', $valuation);
        self::assertStringContainsString("name('stock.reconciliation')", $routes);
    }
}
