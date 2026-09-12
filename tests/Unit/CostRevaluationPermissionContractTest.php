<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CostRevaluationPermissionContractTest extends TestCase
{
    public function test_revaluation_actions_are_split_by_lifecycle_permission(): void
    {
        $routes = file_get_contents(__DIR__.'/../../app/Modules/Wms/Routes/web.php');

        $this->assertStringContainsString("->middleware('permission:wms.cost-revaluation.trigger')->name('stock-valuation.manual-trigger.dispatch')", $routes);
        $this->assertStringContainsString("'/stock-valuation/revaluation/{run}/approve'", $routes);
        $this->assertStringContainsString("'/stock-valuation/revaluation/{run}/reject'", $routes);
        $this->assertStringContainsString("->middleware('permission:wms.cost-revaluation.approve')->name('stock-valuation.revaluation.approve')", $routes);
        $this->assertStringContainsString("->middleware('permission:wms.cost-revaluation.post')->name('stock-valuation.revaluation.apply')", $routes);
        $this->assertStringContainsString("->middleware('permission:wms.cost-revaluation.post')->name('stock-valuation.revaluation.post-journal')", $routes);
        $this->assertStringContainsString("->middleware('permission:wms.cost-revaluation.recover')->name('stock-valuation.revaluation.resume')", $routes);
        $this->assertStringContainsString("->middleware('permission:wms.cost-revaluation.cancel')->name('stock-valuation.revaluation.cancel')", $routes);
        $this->assertStringContainsString("->middleware('permission:wms.cost-revaluation.emergency-rebuild')->name('stock-valuation.emergency-rebuild.dispatch')", $routes);
    }
}
