<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmCustomerTimelineContractTest extends TestCase
{
    #[Test]
    public function timeline_is_customer_branch_scoped_searchable_and_server_paginated(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Crm/Controllers/CustomerTimelineController.php'));
        $routes = file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));

        self::assertStringContainsString("'opportunity.party_id' => \$partyId", $controller);
        self::assertStringContainsString("'opportunity.branch_id' => \$branchId", $controller);
        self::assertStringContainsString("Rule::in(['ALL', 'ACTIVITY', 'STAGE', 'DOCUMENT'])", $controller);
        self::assertStringContainsString('paginate(12)', $controller);
        self::assertStringContainsString("'crm.opportunity.updated'", $controller);
        self::assertStringContainsString("Route::get('/customers/{customer}/timeline'", $routes);
    }

    #[Test]
    public function timeline_combines_activity_stage_and_sales_document_events_in_ajax_cards(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Crm/Controllers/CustomerTimelineController.php'));
        $show = file_get_contents(base_path('app/Modules/Crm/Views/customers/show.blade.php'));
        $cards = file_get_contents(base_path('app/Modules/Crm/Views/customers/_timeline.blade.php'));

        foreach (['sales_intakes', 'sales_rfqs', 'sales_quotations', 'sales_orders', 'pos_physical_sales'] as $table) {
            self::assertStringContainsString($table, $controller);
        }
        self::assertStringContainsString("$.getJSON(dataUrl", $show);
        self::assertStringContainsString('js-retry-timeline', $show);
        self::assertStringContainsString('crm-timeline-event', $cards);
    }
}
