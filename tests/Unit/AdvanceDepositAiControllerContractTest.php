<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdvanceDepositAiControllerContractTest extends TestCase
{
    public function test_ai_routes_use_posting_service_and_server_scoped_eligibility(): void
    {
        $controller = file_get_contents(__DIR__.'/../../app/Modules/Pos/Controllers/AdvanceDepositController.php');
        $routes = file_get_contents(__DIR__.'/../../app/Modules/Pos/Routes/web.php');
        self::assertStringContainsString('AdvanceDepositPostingService', $controller);
        self::assertStringContainsString('AdvanceDepositRefundService', $controller);
        self::assertStringContainsString('RefundAdvanceDepositRequest', $controller);
        self::assertStringContainsString('DB::transaction', $controller);
        self::assertStringContainsString("where('party_id', \$physicalSale->party_id)", $controller);
        self::assertStringContainsString("where('tax_treatment', \$physicalSale->tax_treatment)", $controller);
        self::assertStringContainsString("where('prices_include_vat', \$physicalSale->prices_include_vat)", $controller);
        self::assertStringContainsString('advance-deposits/{advanceDeposit}', $routes);
        self::assertStringContainsString('eligible-advance-deposits', $routes);
        self::assertStringContainsString('advance-deposits/{advanceDeposit}/refund', $routes);
    }
}
