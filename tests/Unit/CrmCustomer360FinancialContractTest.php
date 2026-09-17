<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmCustomer360FinancialContractTest extends TestCase
{
    #[Test]
    public function customer_360_uses_posted_sales_returns_and_active_finance_allocations(): void
    {
        $service = file_get_contents(base_path('app/Modules/Crm/Services/Customer360FinancialService.php'));

        self::assertStringContainsString("'pos_physical_sales'", $service);
        self::assertStringContainsString("'pos_sales_returns as returns'", $service);
        self::assertStringContainsString("'finance_open_items as oi'", $service);
        self::assertStringContainsString("'finance_allocations'", $service);
        self::assertStringContainsString("'finance_advance_deposit_applications'", $service);
        self::assertStringContainsString("where(['party_id' => \$partyId, 'branch_id' => \$branchId, 'status' => 'POSTED'])", $service);
    }

    #[Test]
    public function customer_360_displays_credit_status(): void
    {
        $show = file_get_contents(base_path('app/Modules/Crm/Views/customers/show.blade.php'));

        self::assertStringContainsString('ยอดขายสุทธิ 12 เดือน', $show);
        self::assertStringContainsString('ลูกหนี้คงค้างรวม', $show);
        self::assertStringContainsString('crm-credit-progress', $show);
    }
}
