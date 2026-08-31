<?php

namespace Tests\Unit;

use App\Models\Warehouse;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\AccountingReportService;
use Tests\TestCase;

class ComparativeIncomeReportTest extends TestCase
{
    public function test_it_compares_posted_revenue_for_the_selected_warehouse_only(): void
    {
        $period = new FiscalPeriod(['start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
        $comparisonPeriod = new FiscalPeriod(['start_date' => '2025-01-01', 'end_date' => '2025-01-31']);
        $warehouse = new Warehouse;
        $warehouse->setAttribute('id', 99);

        $query = (new AccountingReportService)->comparativeIncomeQuery($period, $comparisonPeriod, $warehouse);

        $this->assertStringContainsString('left join', strtolower($query->toSql()));
        $this->assertContains('POSTED', $query->getBindings());
        $this->assertContains('REVENUE', $query->getBindings());
        $this->assertContains(99, $query->getBindings());
    }
}
