<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosPromotionPerformanceReportContractTest extends TestCase
{
    public function test_promotion_report_uses_posted_snapshots_and_nets_returns(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReportController.php');
        $intakes = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesIntakeController.php');
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');

        $this->assertStringContainsString('function promotionData', $controller);
        $this->assertStringContainsString("DB::table('pos_sales_returns')", $controller);
        $this->assertStringContainsString("JSON_EXTRACT(sales.promotion_snapshot, '$.promotion_id')", $controller);
        $this->assertStringContainsString("'document_promotion' => [", $intakes);
        $this->assertStringContainsString("name('sales-reports.promotion.data')", $routes);
        $this->assertStringContainsString('function campaignRoiData', $controller);
        $this->assertStringContainsString("name('sales-reports.campaign-roi.data')", $routes);
        $this->assertStringContainsString("DB::table('pos_promotion_campaign_costs')", $controller);
        $this->assertStringContainsString('campaign_budget_amount', $controller);
        $this->assertStringContainsString('budget_remaining', $controller);
        $this->assertStringContainsString('document_promotion.discount_amount', $controller);
        $this->assertStringContainsString('SUM({$documentPromotionDiscount}) AS document_discount', $controller);
        $this->assertStringContainsString('AS allocation_weight', $controller);
        $this->assertStringContainsString('CASE WHEN attribution.promotion_discount_amount <> 0 THEN attribution.promotion_discount_amount ELSE attribution.allocation_weight END', $controller);
        $this->assertStringContainsString("->where('sales.branch_id', \$branchId)->whereIn('sales.warehouse_id', \$warehouseIds)->where('sales.status', 'POSTED')", $controller);
        $this->assertStringContainsString("->where('returns.status', 'POSTED')->where('allocations.status', 'POSTED')->where('allocations.cost_status', 'FINAL')", $controller);
        $this->assertStringContainsString("->whereIn('status', ['PENDING', 'APPROVED', 'PAID'])", $controller);
        $this->assertStringContainsString('MAX(COALESCE(commissions.amount, 0)) AS commission_amount', $controller);
        $this->assertStringContainsString("MAX(JSON_UNQUOTE(JSON_EXTRACT(lines.pricing_snapshot, '$.promotion_code'))) AS promotion_code", $controller);
        $this->assertStringContainsString('COALESCE(MAX(usage.net_sales), 0) - COALESCE(MAX(usage.cogs_amount), 0)', $controller);
    }
}
