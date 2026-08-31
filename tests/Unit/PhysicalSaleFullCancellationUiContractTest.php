<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleFullCancellationUiContractTest extends TestCase
{
    public function test_posted_sale_full_cancellation_ui_route_permission_and_request_are_declared(): void
    {
        $base = dirname(__DIR__, 2);
        $routes = file_get_contents($base.'/app/Modules/Pos/Routes/web.php');
        $request = file_get_contents($base.'/app/Modules/Pos/Requests/CancelFullPhysicalSaleRequest.php');
        $view = file_get_contents($base.'/app/Modules/Pos/Views/physical-sales/show.blade.php');
        $rbac = file_get_contents($base.'/database/seeders/RbacSeeder.php');

        self::assertStringContainsString("Route::post('/physical-sales/{physicalSale}/cancel-full'", $routes);
        self::assertStringContainsString('PhysicalSaleController::class, \'cancelFull\'', $routes);
        self::assertStringContainsString('permission:pos.physical-sales.cancel-full', $routes);
        self::assertStringContainsString('pos.physical-sales.cancel-full', $rbac);
        self::assertStringContainsString("'reversal_date' => ['required', 'date_format:Y-m-d']", $request);
        self::assertStringContainsString("'reason' => ['required', 'string', 'min:10'", $request);
        self::assertStringContainsString('FiscalPeriod::query()', $request);
        self::assertStringContainsString("where('status', 'OPEN')", $request);
        self::assertStringContainsString("hasPermission('pos.physical-sales.cancel-full')", $view);
        self::assertStringContainsString("route('pos.physical-sales.cancel-full', \$sale)", $view);
        self::assertStringContainsString('physical-sale-cancel-full-modal', $view);
        self::assertStringContainsString('เอกสารรับคืนสินค้าและใบลดหนี้เต็มจำนวน', $view);
        self::assertStringContainsString('สรุปรายการที่จะกลับ', $view);
        self::assertStringContainsString('ภาษีขาย', $view);
        self::assertStringContainsString('ภาษีหัก ณ ที่จ่าย', $view);
        self::assertStringContainsString('ระบบจะตรวจสอบความพร้อมของการกลับ VAT', $view);
        self::assertStringContainsString('data-error-for="physical_sale"', $view);
        self::assertStringContainsString("route('pos.sales-returns.show', \$sale->cancellation_return_id)", $view);
        self::assertStringContainsString('$.post($cancelFullForm.attr(\'action\')', $view);
    }
}
