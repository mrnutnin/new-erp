<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FinanceSettlementHandoffContractTest extends TestCase
{
    public function test_pos_can_handoff_to_finance_settlement_without_weakening_finance_context(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/app/Modules/Platform/Routes/web.php');
        $controller = file_get_contents($root.'/app/Modules/Platform/Controllers/ContextController.php');

        self::assertStringContainsString('handoffToFinanceSettlement', $routes);
        self::assertStringContainsString("name('context.finance-settlement.handoff')", $routes);
        self::assertStringContainsString("hasPermission('finance.settlements.create')", $controller);
        self::assertStringContainsString("where('code', 'finance')", $controller);
        self::assertStringContainsString("put('selected_program_id', \$program->id)", $controller);
        self::assertStringContainsString("route('finance.settlements.create'", $controller);
    }
}
