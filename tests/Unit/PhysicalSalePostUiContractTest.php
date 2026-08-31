<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSalePostUiContractTest extends TestCase
{
    public function test_physical_sale_post_route_permission_and_draft_action_are_declared(): void
    {
        $base = dirname(__DIR__, 2);
        $routes = file_get_contents($base.'/app/Modules/Pos/Routes/web.php');
        $controller = file_get_contents($base.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');
        $view = file_get_contents($base.'/app/Modules/Pos/Views/physical-sales/show.blade.php');
        $rbac = file_get_contents($base.'/database/seeders/RbacSeeder.php');

        self::assertStringContainsString("Route::post('/physical-sales/{physicalSale}/post'", $routes);
        self::assertStringContainsString("->middleware('permission:pos.physical-sales.post')->name('physical-sales.post')", $routes);
        self::assertStringContainsString('pos.physical-sales.post', $rbac);
        self::assertStringContainsString('js-post-physical-sale', $view);
        self::assertStringContainsString("route('pos.physical-sales.post', \$sale)", $view);
        self::assertStringContainsString('physical-sale-post-modal', $view);
        self::assertStringContainsString('modal-dialog modal-xl', $view);
        self::assertStringContainsString('post-wht-code', $view);
        self::assertStringContainsString('withholding_tax_code_id', $view);
        self::assertStringContainsString('Object.values(errors).flat()[0]', $view);
        self::assertStringContainsString("submit.data('submitting', 1).prop('disabled', true)", $view);
        self::assertStringContainsString(".always(() => submit.data('submitting', 0).prop('disabled', false))", $view);
        self::assertStringContainsString("\$sale->status === 'POSTED' && \$paymentOpenItem", $view);
        self::assertStringContainsString("hasPermission('pos.physical-sales.receive-payment')", $view);
        self::assertStringContainsString("route('pos.physical-sales.receive-payment.create', \$sale)", $view);
        self::assertStringContainsString("where('document_number', \$sale->document_number)", $controller);
        self::assertStringContainsString("remainingAt(\$candidate, today()->format('Y-m-d')) !== '0.00'", $controller);
        self::assertStringContainsString('Settlement::query()->withTrashed()', $controller);
        self::assertStringContainsString("where('open_item_id', \$candidate->id)", $controller);
        self::assertStringContainsString("\$sale->document_type === 'HS'", $view);
        self::assertStringContainsString('ช่องทางรับเงิน', $view);
        self::assertStringContainsString('js-tender-account', $view);
        self::assertStringContainsString('post-overpayment-note', $view);
        self::assertStringContainsString('id="post-wht-calculated" data-raw=', $view);
        self::assertStringContainsString("syncWht();\n        if (\$rows.length && !\$rows.children().length) addTender(cashDue());", $view);
        self::assertStringContainsString('ยอดรับเงินให้ครบ', $view);
        self::assertStringContainsString('รายละเอียดการรับชำระเงิน', $view);
        self::assertStringContainsString('tender->bankAccount', $view);
        self::assertStringContainsString('PhysicalSaleWithholdingSnapshot::build', $controller);
    }
}
