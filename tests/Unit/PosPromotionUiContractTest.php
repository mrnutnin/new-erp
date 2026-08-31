<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosPromotionUiContractTest extends TestCase
{
    public function test_promotion_master_has_pos_routes_rbac_ui_and_audit_contract(): void
    {
        $base = dirname(__DIR__, 2);
        $routes = file_get_contents($base.'/app/Modules/Pos/Routes/web.php');
        $rbac = file_get_contents($base.'/database/seeders/RbacSeeder.php');
        $sidebar = file_get_contents($base.'/app/Modules/Pos/Views/partials/sidebar.blade.php');
        $controller = file_get_contents($base.'/app/Modules/Pos/Controllers/PromotionController.php');
        $request = file_get_contents($base.'/app/Modules/Pos/Requests/SavePromotionRequest.php');
        $index = file_get_contents($base.'/app/Modules/Pos/Views/promotions/index.blade.php');
        $form = file_get_contents($base.'/app/Modules/Pos/Views/promotions/form.blade.php');
        $detail = file_get_contents($base.'/app/Modules/Pos/Views/promotions/show.blade.php');

        self::assertStringContainsString("Route::get('/promotions'", $routes);
        self::assertStringContainsString("Route::get('/promotions/data'", $routes);
        self::assertStringContainsString("Route::get('/promotions/{promotion}'", $routes);
        self::assertStringContainsString('permission:pos.promotions.view', $routes);
        self::assertStringContainsString('pos.promotions.create', $rbac);
        self::assertStringContainsString('pos.promotions.update', $rbac);
        self::assertStringContainsString('pos.promotions.delete', $rbac);
        self::assertStringContainsString("route('pos.promotions.index')", $sidebar);
        self::assertStringContainsString('promotions-table', $index);
        self::assertStringContainsString('erpExcelButton', $index);
        self::assertStringContainsString('promotion-filter', $index);
        self::assertStringContainsString('js-promotion-group', $form);
        self::assertStringContainsString('lines[__INDEX__][unit_price]', $form);
        self::assertStringContainsString('lines[__INDEX__][base_unit_price]', $form);
        self::assertStringContainsString('lines[__INDEX__][discount_percent]', $form);
        self::assertStringContainsString('หน่วย Stock', $form);
        self::assertStringContainsString('uom_text', $form);
        self::assertStringContainsString('application_scope', $form);
        self::assertStringContainsString('ท้ายบิล', $form);
        self::assertStringContainsString('stackable', $form);
        self::assertStringContainsString('bill_discount_amount', $form);
        self::assertStringContainsString('bill_discount_percent', $form);
        self::assertStringContainsString('ระบุราคาโปรโมชั่น หรือราคาตั้งต้นพร้อมส่วนลดอย่างใดอย่างหนึ่งเท่านั้น', $request);
        self::assertStringContainsString("Rule::in(['LINE', 'DOCUMENT'])", $request);
        self::assertStringContainsString('โปรโมชั่นท้ายบิลต้องระบุยอดลดหรือส่วนลดเปอร์เซ็นต์เพียงอย่างเดียว', $request);
        self::assertStringContainsString('โปรโมชั่นท้ายบิลไม่สามารถกำหนดเงื่อนไขสินค้าได้', $request);
        self::assertStringContainsString('โปรโมชั่นต่อรายการต้องมีเงื่อนไขสินค้าอย่างน้อยหนึ่งรายการ', $request);
        self::assertStringContainsString('application_scope', $controller);
        self::assertStringContainsString("select('pos_promotions.*')->withCount('items')", $controller);
        self::assertStringContainsString("validated('lines', [])", $controller);
        self::assertStringContainsString("'uom_id' => \$item->base_uom_id", $controller);
        self::assertStringContainsString('AuditLog::query()', $controller);
        self::assertStringContainsString('ประวัติการเปลี่ยนแปลง', $detail);
        self::assertStringContainsString('Price List', $detail);
    }
}
