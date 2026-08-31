<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EmployeeSalesTargetContractTest extends TestCase
{
    public function test_targets_are_branch_scoped_active_employee_period_records(): void
    {
        $base = dirname(__DIR__, 2);
        $migration = file_get_contents($base.'/database/migrations/2026_08_31_150000_create_pos_employee_sales_targets_table.php');
        $controller = file_get_contents($base.'/app/Modules/Pos/Controllers/EmployeeSalesTargetController.php');
        $routes = file_get_contents($base.'/app/Modules/Pos/Routes/web.php');
        $rbac = file_get_contents($base.'/database/seeders/RbacSeeder.php');

        self::assertStringContainsString("Schema::create('pos_employee_sales_targets'", $migration);
        self::assertStringContainsString("foreignId('branch_id')", $migration);
        self::assertStringContainsString("foreignId('user_id')", $migration);
        self::assertStringContainsString("date('period_start')", $migration);
        self::assertStringContainsString("date('period_end')", $migration);
        self::assertStringContainsString("decimal('sales_target', 18, 2)", $migration);
        self::assertStringContainsString("decimal('gross_profit_target', 18, 2)", $migration);
        self::assertStringContainsString("where('branch_id', \$this->branchId(\$request))", $controller);
        self::assertStringContainsString('employeesForBranch', $controller);
        self::assertStringContainsString("where('period_start', '<=', \$end)->where('period_end', '>=', \$start)", $controller);
        self::assertStringContainsString("Route::get('/employee-sales-targets'", $routes);
        self::assertStringContainsString('pos.employee-sales-targets.view', $rbac);
    }
}
