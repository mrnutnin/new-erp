<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesReturnPostUiContractTest extends TestCase
{
    public function test_draft_sales_return_post_route_permission_and_ui_are_declared(): void
    {
        $base = dirname(__DIR__, 2);
        $routes = file_get_contents($base.'/app/Modules/Pos/Routes/web.php');
        $controller = file_get_contents($base.'/app/Modules/Pos/Controllers/SalesReturnController.php');
        $rbac = file_get_contents($base.'/database/seeders/RbacSeeder.php');
        $show = file_get_contents($base.'/app/Modules/Pos/Views/sales-returns/show.blade.php');
        $index = file_get_contents($base.'/app/Modules/Pos/Views/sales-returns/index.blade.php');

        self::assertStringContainsString("Route::post('/sales-returns/{salesReturn}/post'", $routes);
        self::assertStringContainsString('permission:pos.sales-returns.post', $routes);
        self::assertStringContainsString('pos.sales-returns.post', $rbac);
        self::assertStringNotContainsString("'post_url'", $controller);
        self::assertStringContainsString('SalesReturnPostingService', $controller);
        self::assertStringContainsString('$posting->post($salesReturn, $values[\'posting_date\']', $controller);
        self::assertStringContainsString("'stock_uom_id' => \$line->stock_uom_id", $controller);
        self::assertStringContainsString("'stock_quantity' => \$stockQuantity", $controller);
        self::assertStringContainsString("'uom_factor' => \$line->uom_factor", $controller);
        self::assertStringContainsString("'conversion_snapshot' => \$line->conversion_snapshot", $controller);
        self::assertStringContainsString('js-sales-return-post', $show);
        self::assertStringContainsString("route('pos.sales-returns.post', \$returnDocument)", $show);
        self::assertStringContainsString('posting_date', $show);
        self::assertStringContainsString('ตรวจสอบเอกสารต้นทาง ปริมาณคืน และวันที่ Post ก่อน Post เอกสาร เพื่อคืน Stock และ GL', $show);
        self::assertStringContainsString('เอกสารที่เกี่ยวข้อง', $show);
        self::assertStringNotContainsString('js-return-post', $index);
        self::assertStringContainsString('เปิดรายละเอียดเพื่อ Post หรือยกเลิกเอกสารร่าง', $index);
    }
}
