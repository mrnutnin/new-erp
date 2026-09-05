<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PettyCashBackendContractTest extends TestCase
{
    public function test_finance_petty_cash_routes_are_permission_gated(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Finance/Routes/web.php'));

        foreach (['data', '{voucher}/submit', '{voucher}/approve', '{voucher}/void', '{voucher}/post', '{voucher}/reverse'] as $path) {
            self::assertStringContainsString("/petty-cash/{$path}", $routes);
        }
        foreach (['view', 'create', 'update', 'submit', 'approve', 'post', 'void', 'reverse', 'manage-funds'] as $permission) {
            self::assertStringContainsString("finance.petty-cash.{$permission}", $routes);
        }
    }

    public function test_controller_uses_request_validation_service_and_warehouse_scope(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Finance/Controllers/PettyCashController.php'));

        foreach (['SavePettyCashVoucherRequest', 'PettyCashActionRequest', 'PettyCashVoucherService', 'DataTables::eloquent', "where('warehouse_id', \$request->attributes->get('selectedWarehouse')->id)", 'scopeVoucher', 'PETTY_CASH'] as $needle) {
            self::assertStringContainsString($needle, $controller);
        }
        $fundController = file_get_contents(base_path('app/Modules/Finance/Controllers/PettyCashFundController.php'));
        foreach (['SavePettyCashFundRequest', 'AuditLog::query()', "where('subject_type'", 'วงเงิน', 'whereIn(\'status\'', 'DataTables::eloquent', 'topUps()->withTrashed()->exists()', 'finance.petty_cash_fund.deleted'] as $needle) {
            self::assertStringContainsString($needle, $fundController);
        }
    }

    public function test_rbac_seeder_registers_all_petty_cash_permissions(): void
    {
        $seeder = file_get_contents(base_path('database/seeders/RbacSeeder.php'));

        foreach (['view', 'create', 'update', 'submit', 'approve', 'post', 'void', 'reverse', 'manage-funds'] as $permission) {
            self::assertStringContainsString("finance.petty-cash.{$permission}", $seeder);
        }
    }
}
