<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EmployeeAdvanceClearingContractTest extends TestCase
{
    public function test_employee_advance_clearing_has_normalized_lines_and_finance_workflow(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root.'/database/migrations/2026_09_05_040000_create_finance_employee_advance_clearings.php');
        $partialMigration = file_get_contents($root.'/database/migrations/2026_09_05_200000_add_is_final_to_finance_employee_advance_clearings.php');
        $service = file_get_contents($root.'/app/Modules/Finance/Services/EmployeeAdvanceClearingService.php');
        $routes = file_get_contents($root.'/app/Modules/Finance/Routes/web.php');
        $view = file_get_contents($root.'/app/Modules/Finance/Views/employee-advance-clearings/form.blade.php');
        $show = file_get_contents($root.'/app/Modules/Finance/Views/employee-advance-clearings/show.blade.php');
        $attachmentController = file_get_contents($root.'/app/Modules/Finance/Controllers/PettyCashAttachmentController.php');
        $attachmentRequest = file_get_contents($root.'/app/Modules/Finance/Requests/StorePettyCashAttachmentRequest.php');
        $rbac = file_get_contents($root.'/database/seeders/RbacSeeder.php');
        $mapping = file_get_contents($root.'/database/migrations/2026_09_05_070000_enable_employee_advance_clearing_mapping.php');
        $posting = file_get_contents($root.'/app/Modules/Accounting/Support/PostingEvent.php');

        self::assertStringContainsString("Schema::create('finance_employee_advance_clearings'", $migration);
        self::assertStringContainsString("Schema::create('finance_employee_advance_clearing_lines'", $migration);
        self::assertStringContainsString("'vat_amount'", $migration);
        self::assertStringContainsString("'wht_amount'", $migration);
        self::assertStringContainsString("boolean('is_final')", $partialMigration);
        self::assertStringContainsString('EmployeeAdvanceClearingController', $routes);
        foreach (['employee-advance-clearings.index', 'employee-advance-clearings.create', 'employee-advance-clearings.store', 'employee-advance-clearings.show', 'employee-advance-clearings.edit', 'employee-advance-clearings.update', 'employee-advance-clearings.submit', 'employee-advance-clearings.approve', 'employee-advance-clearings.void', 'employee-advance-clearings.post', 'employee-advance-clearings.reverse'] as $route) {
            self::assertStringContainsString("name('{$route}')", $routes);
        }
        self::assertStringContainsString('TaxCalculator', $service);
        self::assertStringContainsString('refund_amount', $service);
        self::assertStringContainsString('additional_amount', $service);
        self::assertStringContainsString('$advanceRelease', $service);
        self::assertStringContainsString('$clearing->is_final ? \'CLEARED\' : \'POSTED\'', $service);
        self::assertStringContainsString('JournalPostingService', $service);
        self::assertStringContainsString('postWithinTransaction', $service);
        self::assertStringContainsString('reverseWithinTransaction', $service);
        self::assertStringContainsString("'source_type' => 'FIN_EMP_ADV_CLEARING'", $service);
        self::assertStringContainsString("'employee_advance_clearing'", $posting);
        self::assertStringContainsString("'event_code' => 'employee_advance_clearing'", $mapping);
        self::assertStringContainsString("'key' => 'EMPLOYEE_ADVANCE'", $mapping);
        self::assertStringContainsString('erpAjaxForm', $view);
        self::assertStringContainsString('petty-cash-attachments', $show);
        self::assertStringContainsString("employee-advance-clearings.edit", $show);
        self::assertStringContainsString('employeeAdvanceClearingStore', $attachmentController);
        self::assertStringContainsString('EMPLOYEE_ADVANCE_CLEARING', $attachmentController);
        self::assertStringContainsString("'REFUND'", $attachmentRequest);
        self::assertStringContainsString('finance.employee-advance-clearings.update', $rbac);
    }
}
