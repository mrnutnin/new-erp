<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmCustomer360DocumentTrailContractTest extends TestCase
{
    #[Test]
    public function document_trail_reuses_the_pos_sales_chain_and_loads_payments_in_one_query(): void
    {
        $service = file_get_contents(base_path('app/Modules/Crm/Services/Customer360DocumentTrailService.php'));

        self::assertStringContainsString('SalesDocumentTrail::for($intake)', $service);
        self::assertStringContainsString("'rfq.quotation.order.physicalSales'", $service);
        self::assertStringContainsString("'finance_settlement_allocation_intents as intents'", $service);
        self::assertStringContainsString("'finance_open_items as open_items'", $service);
        self::assertStringContainsString('limit(10)', $service);
        self::assertStringContainsString("->where(['party_id' => \$partyId, 'branch_id' => \$branchId])", $service);
    }

    #[Test]
    public function customer_page_renders_all_six_bounded_document_stages(): void
    {
        $show = file_get_contents(base_path('app/Modules/Crm/Views/customers/show.blade.php'));
        $service = file_get_contents(base_path('app/Modules/Crm/Services/Customer360DocumentTrailService.php'));

        foreach (['Sales Intake', 'RFQ', 'Quotation', 'Sales Order', 'Invoice / Sale', 'Payment'] as $label) {
            self::assertStringContainsString("'label' => '{$label}'", $service);
        }
        self::assertStringContainsString('Document Trail', $show);
        self::assertStringContainsString("\$documentTrails as \$trail", $show);
        self::assertStringContainsString('รอดำเนินการ', $show);
    }
}
