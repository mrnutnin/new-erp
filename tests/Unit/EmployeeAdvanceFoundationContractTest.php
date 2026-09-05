<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EmployeeAdvanceFoundationContractTest extends TestCase
{
    public function test_employee_advance_foundation_keeps_its_own_subledger_and_scope(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root.'/database/migrations/2026_09_05_010000_create_finance_employee_advances.php');
        $model = file_get_contents($root.'/app/Modules/Finance/Models/EmployeeAdvance.php');

        self::assertStringContainsString("Schema::create('finance_employee_advances'", $migration);
        self::assertStringContainsString("employee_user_id", $migration);
        self::assertStringContainsString("warehouse_id", $migration);
        self::assertStringContainsString("status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'PARTIAL', 'CLEARED', 'VOID', 'REVERSED']", $migration);
        self::assertStringContainsString("protected \$table = 'finance_employee_advances'", $model);
        self::assertStringContainsString("belongsTo(User::class, 'employee_user_id')", $model);
        self::assertStringContainsString("is_postable", file_get_contents($root.'/app/Modules/Finance/Services/EmployeeAdvanceService.php'));
    }

    public function test_employee_advance_delivery_keeps_finance_patterns(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Finance/Routes/web.php');
        $rbac = file_get_contents(dirname(__DIR__, 2).'/database/seeders/RBACSeeder.php');
        $sequence = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Finance/Requests/SaveDocumentSequenceRequest.php');
        $index = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Finance/Views/employee-advances/index.blade.php');

        foreach (['employee-advances.index', 'employee-advances.data', 'employee-advances.create', 'employee-advances.store', 'employee-advances.show', 'employee-advances.submit', 'employee-advances.approve', 'employee-advances.void', 'employee-advances.post', 'employee-advances.reverse'] as $route) {
            self::assertStringContainsString("name('{$route}')", $routes);
        }
        foreach (['finance.employee-advances.view', 'finance.employee-advances.create', 'finance.employee-advances.submit', 'finance.employee-advances.approve', 'finance.employee-advances.post', 'finance.employee-advances.reverse'] as $permission) {
            self::assertStringContainsString("'{$permission}'", $rbac);
        }
        self::assertStringContainsString('EMPLOYEE_ADVANCE', $sequence);
        self::assertStringContainsString('DataTable', $index);
        self::assertStringContainsString('erpAjaxForm', file_get_contents(dirname(__DIR__, 2).'/app/Modules/Finance/Views/employee-advances/form.blade.php'));
        $posting = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Accounting/Support/PostingEvent.php');
        $service = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Finance/Services/EmployeeAdvanceService.php');
        self::assertStringContainsString("'employee_advance'", $posting);
        self::assertStringContainsString('JournalPostingService', $service);
        self::assertStringContainsString('reverseWithinTransaction', $service);
    }
}
