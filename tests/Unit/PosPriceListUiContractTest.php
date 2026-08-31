<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosPriceListUiContractTest extends TestCase
{
    public function test_price_list_uses_pos_filters_badges_and_navigation_labels(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/PriceListController.php');
        $index = file_get_contents($root.'/app/Modules/Pos/Views/price-lists/index.blade.php');
        $form = file_get_contents($root.'/app/Modules/Pos/Views/price-lists/form.blade.php');

        self::assertStringContainsString("when(\$request->filled('customer_group_code')", $controller);
        self::assertStringContainsString("when(\$request->filled('is_active')", $controller);
        self::assertStringContainsString('id="price-list-filter"', $index);
        self::assertStringContainsString('app-badge-success', $index);
        self::assertStringContainsString('app-badge-soft', $index);
        self::assertStringContainsString('ย้อนกลับ', $form);
        self::assertStringContainsString('text-danger', $form);
    }

    public function test_price_list_menu_and_permissions_use_thai_pos_labels(): void
    {
        $root = dirname(__DIR__, 2);
        $sidebar = file_get_contents($root.'/app/Modules/Pos/Views/partials/sidebar.blade.php');
        $rbac = file_get_contents($root.'/database/seeders/RbacSeeder.php');

        self::assertStringContainsString('data-bs-target="#pos-pricing-menu"', $sidebar);
        self::assertStringContainsString('ราคา &amp; โปรโมชั่น', $sidebar);
        self::assertStringContainsString('data-bs-target="#pos-commission-menu"', $sidebar);
        self::assertStringContainsString('>รายการราคา</a>', $sidebar);
        self::assertStringContainsString('>โปรโมชั่น</a>', $sidebar);
        self::assertStringContainsString('>คอมมิชชั่นขาย</a>', $sidebar);
        self::assertStringContainsString('>ตั้งค่าคอมมิชชั่นขาย</a>', $sidebar);
        self::assertStringContainsString("'pos.price-lists.view' => 'ดูรายการราคา'", $rbac);
        self::assertStringContainsString("'pos.price-lists.create' => 'สร้างรายการราคา'", $rbac);
    }
}
