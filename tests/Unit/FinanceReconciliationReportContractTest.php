<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FinanceReconciliationReportContractTest extends TestCase
{
    public function test_reconciliation_report_includes_missing_journal_exception_signal(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Finance/Controllers/FinanceReportController.php');
        $view = file_get_contents($root.'/app/Modules/Finance/Views/reports/reconciliation.blade.php');
        $routes = file_get_contents($root.'/app/Modules/Finance/Routes/web.php');
        $allocationView = file_get_contents($root.'/app/Modules/Finance/Views/reports/settlement-allocations.blade.php');

        self::assertStringContainsString('missing_journal_count', $controller);
        self::assertStringContainsString('unbalanced_journal_count', $controller);
        self::assertStringContainsString('source_url', $controller);
        self::assertStringContainsString('source_count', $controller);
        self::assertStringContainsString('จำนวน Journal ไม่ตรง', $controller);
        self::assertStringContainsString('exceptions_only', $controller);
        self::assertStringContainsString("whereNull('deleted_at')", $controller);
        self::assertStringContainsString("'ไม่พบ Journal'", $controller);
        self::assertStringContainsString("'Journal ไม่สมดุล'", $controller);
        self::assertStringContainsString('ไม่มี Journal', $view);
        self::assertStringContainsString("data:'missing_journal_count'", $view);
        self::assertStringContainsString("data:'unbalanced_journal_count'", $view);
        self::assertStringContainsString('reconciliation-exceptions', $view);
        self::assertStringContainsString('source_url', $view);
        self::assertStringContainsString("data:'source_count'", $view);
        self::assertStringContainsString("reports.settlement-allocations.data", $routes);
        self::assertStringContainsString('settlement-allocation-report', $allocationView);
        self::assertStringContainsString('settlement-allocation-status', $allocationView);
        self::assertStringContainsString('settlement-allocation-warehouse', $allocationView);
    }
}
