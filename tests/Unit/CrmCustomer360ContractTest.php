<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmCustomer360ContractTest extends TestCase
{
    #[Test]
    public function customer_360_reuses_party_and_loads_a_bounded_branch_summary(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Crm/Controllers/CustomerController.php'));
        $routes = file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));

        self::assertStringContainsString('Party::query()', $controller);
        self::assertStringContainsString("whereHas('customerRole'", $controller);
        self::assertStringContainsString("where('branch_id', \$branchId)", $controller);
        self::assertStringContainsString('paginate(12)', $controller);
        self::assertStringContainsString("Route::get('/customers/data'", $routes);
        self::assertStringContainsString("Route::get('/customers/{customer}/timeline'", $routes);
    }

    #[Test]
    public function customer_pages_use_ajax_cards_and_prefill_new_opportunities(): void
    {
        $index = file_get_contents(base_path('app/Modules/Crm/Views/customers/index.blade.php'));
        $cards = file_get_contents(base_path('app/Modules/Crm/Views/customers/_cards.blade.php'));
        $show = file_get_contents(base_path('app/Modules/Crm/Views/customers/show.blade.php'));
        $opportunity = file_get_contents(base_path('app/Modules/Crm/Controllers/OpportunityController.php'));

        self::assertStringContainsString('$.getJSON(dataUrl', $index);
        self::assertStringContainsString('ดู Customer 360°', $cards);
        self::assertStringContainsString("['party_id'=>\$customer->id]", $cards);
        self::assertStringContainsString('Customer Timeline', $show);
        self::assertStringContainsString('Document Trail', $show);
        self::assertStringContainsString('id="customer-activity-create"', $show);
        self::assertStringContainsString("route('crm.opportunities.activities.store',\$opportunity)", $show);
        self::assertStringContainsString("route('crm.opportunities.owner-options')", $show);
        self::assertStringContainsString("'party_id' => \$party?->id", $opportunity);
    }
}
