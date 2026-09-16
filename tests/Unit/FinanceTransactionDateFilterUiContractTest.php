<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FinanceTransactionDateFilterUiContractTest extends TestCase
{
    public function test_transaction_lists_keep_server_side_date_range_filters(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Finance/';
        $cases = [
            ['Views/employee-advances/index.blade.php', 'Controllers/EmployeeAdvanceController.php', 'employee-advance-date-from', 'document_date'],
            ['Views/employee-advance-clearings/index.blade.php', 'Controllers/EmployeeAdvanceClearingController.php', 'eac-date-from', 'document_date'],
            ['Views/petty-cash/index.blade.php', 'Controllers/PettyCashController.php', 'petty-cash-date-from', 'document_date'],
            ['Views/petty-cash/top-ups/index.blade.php', 'Controllers/PettyCashTopUpController.php', 'top-up-date-from', 'document_date'],
            ['Views/petty-cash-clearings/index.blade.php', 'Controllers/PettyCashClearingController.php', 'clearing-date-from', 'clearing_date'],
        ];

        foreach ($cases as [$viewPath, $controllerPath, $fromInput, $dateColumn]) {
            $view = file_get_contents($root.$viewPath);
            $controller = file_get_contents($root.$controllerPath);

            $this->assertStringContainsString($fromInput, $view);
            $this->assertStringContainsString('date_to', $view);
            $this->assertStringContainsString('window.erpExcelButton', $view);
            $this->assertStringContainsString("whereDate('{$dateColumn}'", $controller);
            $this->assertStringContainsString("'date_from'", $controller);
            $this->assertStringContainsString("'date_to'", $controller);
        }
    }

    public function test_commission_payouts_filter_by_overlapping_calculation_period(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Finance/';
        $view = file_get_contents($root.'Views/commission-payouts/index.blade.php');
        $controller = file_get_contents($root.'Controllers/CommissionPayoutController.php');

        $this->assertStringContainsString('commission-period-from', $view);
        $this->assertStringContainsString('commission-period-to', $view);
        $this->assertStringContainsString('window.erpExcelButton(table, filters)', $view);
        $this->assertStringContainsString("whereDate('period_to', '>=', \$request->string('period_from'))", $controller);
        $this->assertStringContainsString("whereDate('period_from', '<=', \$request->string('period_to'))", $controller);
    }
}
