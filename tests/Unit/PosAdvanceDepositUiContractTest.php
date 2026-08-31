<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosAdvanceDepositUiContractTest extends TestCase
{
    public function test_ai_pos_routes_permissions_views_and_server_eligible_contract_are_declared(): void
    {
        $base = dirname(__DIR__, 2);
        $routes = file_get_contents($base.'/app/Modules/Pos/Routes/web.php');
        $rbac = file_get_contents($base.'/database/seeders/RbacSeeder.php');
        $index = file_get_contents($base.'/app/Modules/Pos/Views/advance-deposits/index.blade.php');
        $form = file_get_contents($base.'/app/Modules/Pos/Views/advance-deposits/form.blade.php');
        $detail = file_get_contents($base.'/app/Modules/Pos/Views/advance-deposits/show.blade.php');
        $layout = file_get_contents($base.'/app/Modules/Pos/Views/layout.blade.php');
        $controller = file_get_contents($base.'/app/Modules/Pos/Controllers/AdvanceDepositController.php');
        $sale = file_get_contents($base.'/app/Modules/Pos/Views/physical-sales/show.blade.php');

        self::assertStringContainsString("Route::get('/advance-deposits'", $routes);
        self::assertStringContainsString("Route::post('/advance-deposits'", $routes);
        self::assertStringContainsString("Route::get('/advance-deposits/{advanceDeposit}/pdf'", $routes);
        self::assertStringContainsString('pos.advance-deposits.view', $rbac);
        self::assertStringContainsString('pos.advance-deposits.create', $rbac);
        self::assertStringContainsString('pos.advance-deposits.print', $rbac);
        self::assertStringContainsString('pos.advance-deposits.refund', $rbac);
        self::assertStringContainsString('advance-deposits-table', $index);
        self::assertStringContainsString('ใช้กับ HS', $index);
        self::assertStringContainsString('อ้างอิง GL', $index);
        self::assertStringContainsString("'used_hs_label'", $controller);
        self::assertStringContainsString("'gl_reference_label'", $controller);
        self::assertStringContainsString('js-quick-customer', $form);
        self::assertStringContainsString('quick-customer-modal', $form);
        self::assertStringContainsString("route('pos.customers.quick-options')", $form);
        self::assertStringContainsString("route('pos.customers.store')", $form);
        self::assertStringContainsString('name="prices_include_vat"', $form);
        self::assertStringContainsString('name="tax_treatment"', $form);
        self::assertStringContainsString('value="VAT_INCLUSIVE"', $form);
        self::assertStringContainsString('value="VAT_EXCLUSIVE"', $form);
        self::assertStringContainsString('value="NONE"', $form);
        self::assertStringContainsString('withholding_tax_code_id', $form);
        self::assertStringContainsString('tenders[__INDEX__][bank_account_id]', $form);
        self::assertStringContainsString('route(\'pos.advance-deposits.pdf\'', $detail);
        self::assertStringContainsString('eligible-advance-deposits', $routes);
        self::assertStringContainsString('post-eligible-advance-deposits', $sale);
        self::assertStringContainsString('$.getJSON($eligibleAdvances.data(\'url\'))', $sale);
        self::assertStringNotContainsString('name="advance_deposit_ids[]"', $sale);
        self::assertStringContainsString('name="advance_allocations[${index}][advance_deposit_id]"', $sale);
        self::assertStringContainsString('name="advance_allocations[${index}][amount]"', $sale);
        self::assertStringContainsString('function cashDue()', $sale);
        self::assertStringContainsString('syncAdvances()', $sale);
        self::assertStringContainsString("Route::post('/advance-deposits/{advanceDeposit}/refund'", $routes);
        self::assertStringContainsString('permission:pos.advance-deposits.refund', $routes);
        self::assertStringContainsString('advance-deposit-refund-modal', $detail);
        self::assertStringContainsString('js-advance-deposit-cancel', $detail);
        self::assertStringContainsString('js-advance-deposit-cancel', $layout);
        self::assertStringContainsString('>ยกเลิกเอกสาร</button>', $detail);
        self::assertStringContainsString('ยกเลิกเอกสารรับเงินล่วงหน้า', $detail);
        self::assertStringNotContainsString('ยืนยันคืนเงิน / กลับรายการ', $detail);
        self::assertStringContainsString('ช่องทางคืนเงิน', $detail);
        self::assertStringContainsString('advanceDeposit->tenders as $tender', $detail);
        self::assertStringNotContainsString('name="refund_bank_account_id"', $detail);
        self::assertStringContainsString('name="refund_date"', $detail);
        self::assertStringNotContainsString('name="reversal_date"', $detail);
        self::assertStringContainsString('advanceDeposit->applications', $detail);
        self::assertStringContainsString('physicalSale?->document_number', $detail);
        self::assertStringContainsString('journal-preview.show', $detail);
        self::assertStringContainsString('รายละเอียดการรับเงิน', $detail);
        self::assertStringContainsString('ยอดคงเหลือใช้ได้', $detail);
        self::assertStringContainsString('>ย้อนกลับ</a>', $detail);
        self::assertStringContainsString("'VOID'=>'text-bg-danger'", $detail);
        self::assertStringContainsString('advanceDeposit->tenders as $tender', $detail);
        self::assertStringContainsString("'remainingAmount' => \$this->remaining(\$advanceDeposit)", $controller);
        self::assertStringContainsString("in_array(\$advanceDeposit->status, ['POSTED', 'PARTIAL'], true)", $detail);
        self::assertStringContainsString('activeApplications->isEmpty()', $detail);
        self::assertStringContainsString('advanceDeposit->tenders->isNotEmpty()', $detail);
        self::assertStringContainsString('คืนเงินเข้าบัญชีเงินสด/ธนาคารเดิมทุกช่องทางตามยอดเดิม', $detail);
        self::assertStringNotContainsString('@foreach($bankAccounts as $account)', $detail);
    }
}
