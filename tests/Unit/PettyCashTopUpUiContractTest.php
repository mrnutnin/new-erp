<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PettyCashTopUpUiContractTest extends TestCase
{
    public function test_top_up_ui_keeps_datatable_ajax_and_workflow_contracts(): void
    {
        $base = dirname(__DIR__, 2).'/app/Modules/Finance/Views/petty-cash/top-ups/';
        $index = file_get_contents($base.'index.blade.php');
        $form = file_get_contents($base.'form.blade.php');
        $show = file_get_contents($base.'show.blade.php');

        self::assertStringContainsString('window.erpDataTableDefaults', $index);
        self::assertStringContainsString('window.erpExcelButton', $index);
        self::assertStringContainsString("route('finance.petty-cash-top-ups.data')", $index);
        self::assertStringContainsString('top-up-filter-title', $index);
        self::assertStringContainsString('top-up-filter-reset', $index);
        self::assertStringContainsString('window.erpAjaxForm', $form);
        self::assertStringContainsString('sourceBankAccountOptions', $form);
        self::assertStringContainsString("route('finance.petty-cash-top-ups.'.\$action", $show);
        self::assertStringContainsString('js-reason', $show);
    }

    public function test_top_up_delivery_has_dedicated_routes_permissions_and_controller(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Finance/Routes/web.php');
        $rbac = file_get_contents(dirname(__DIR__, 2).'/database/seeders/RbacSeeder.php');
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Finance/Controllers/PettyCashTopUpController.php');

        self::assertStringContainsString('/petty-cash/top-ups', $routes);
        self::assertStringContainsString('finance.petty-cash-top-ups.reverse', $routes);
        self::assertStringContainsString('finance.petty-cash-top-ups.view', $rbac);
        self::assertStringContainsString('PettyCashTopUpService', $controller);
        self::assertStringContainsString('DataTables::eloquent', $controller);
        self::assertStringContainsString("where('warehouse_id'", $controller);
        self::assertStringContainsString("'redirect' => route('finance.petty-cash-top-ups.show'", $controller);
    }
}
