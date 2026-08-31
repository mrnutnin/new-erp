<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PosReportNavigationContractTest extends TestCase
{
    public function test_pos_reports_are_grouped_without_changing_their_routes_or_permission(): void
    {
        $sidebar = file_get_contents(base_path('app/Modules/Pos/Views/partials/sidebar.blade.php'));

        $this->assertStringContainsString("hasPermission('pos.sales-reports.view')", $sidebar);
        $this->assertStringContainsString('data-bs-target="#pos-report-menu"', $sidebar);
        $this->assertStringContainsString("request()->routeIs('pos.sales-reports.*')", $sidebar);
        $this->assertStringContainsString("route('pos.sales-reports.daily.index')", $sidebar);
        $this->assertStringContainsString("route('pos.sales-reports.customer.index')", $sidebar);
        $this->assertStringContainsString("route('pos.sales-reports.item.index')", $sidebar);
    }

    public function test_pos_sales_navigation_groups_related_documents_without_changing_routes_or_permissions(): void
    {
        $sidebar = file_get_contents(base_path('app/Modules/Pos/Views/partials/sidebar.blade.php'));

        $this->assertStringContainsString('data-bs-target="#pos-sales-document-menu"', $sidebar);
        $this->assertStringContainsString("hasPermission('pos.sales-intakes.view')", $sidebar);
        $this->assertStringContainsString("route('pos.sales-intakes.index')", $sidebar);
        $this->assertStringContainsString("route('pos.sales-rfqs.index')", $sidebar);
        $this->assertStringContainsString("route('pos.sales-quotations.index')", $sidebar);
        $this->assertStringContainsString("route('pos.sales-orders.index')", $sidebar);
        $this->assertStringContainsString("route('pos.physical-sales.index')", $sidebar);

        $this->assertStringContainsString('data-bs-target="#pos-receivable-menu"', $sidebar);
        $this->assertStringContainsString("hasPermission('pos.receivables.view')", $sidebar);
        $this->assertStringContainsString("route('pos.receivables.index')", $sidebar);
        $this->assertStringContainsString("route('pos.receipts.index')", $sidebar);
        $this->assertStringContainsString("route('pos.advance-deposits.index')", $sidebar);
        $this->assertStringContainsString('border-0 small fw-normal', $sidebar);
    }
}
