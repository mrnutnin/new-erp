<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RolePermissionTabsUiContractTest extends TestCase
{
    public function test_role_permissions_are_grouped_into_readable_module_tabs(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Settings/Controllers/RoleController.php');
        $view = file_get_contents($root.'/app/Modules/Settings/Views/roles/form.blade.php');

        self::assertStringContainsString("'accounting' => 'บัญชี'", $controller);
        self::assertStringContainsString("'wms' => 'คลังสินค้า'", $controller);
        self::assertStringContainsString("Str::before(\$permission->code, '.')", $controller);
        self::assertStringContainsString("explode('.', \$permission->code)[1]", $controller);
        self::assertStringContainsString("preg_replace('/^ดู/u'", $controller);

        self::assertStringContainsString('data-bs-toggle="tab"', $view);
        self::assertStringContainsString("{{ \$group['label'] }}", $view);
        self::assertStringContainsString('รหัสกลุ่ม:', $view);
        self::assertStringContainsString('js-check-all', $view);
        self::assertStringContainsString('name="permission_ids[]"', $view);
    }
}
