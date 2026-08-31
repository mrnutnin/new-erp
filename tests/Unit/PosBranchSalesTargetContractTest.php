<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosBranchSalesTargetContractTest extends TestCase
{
    public function test_branch_sales_targets_are_scoped_audited_and_permission_gated(): void
    {
        $base = dirname(__DIR__, 2);
        $migration = file_get_contents($base.'/database/migrations/2026_08_31_100000_create_pos_branch_sales_targets_table.php');
        $model = file_get_contents($base.'/app/Modules/Pos/Models/BranchSalesTarget.php');
        $controller = file_get_contents($base.'/app/Modules/Pos/Controllers/BranchSalesTargetController.php');
        $routes = file_get_contents($base.'/app/Modules/Pos/Routes/web.php');
        $rbac = file_get_contents($base.'/database/seeders/RbacSeeder.php');
        $sidebar = file_get_contents($base.'/app/Modules/Pos/Views/partials/sidebar.blade.php');
        $index = file_get_contents($base.'/app/Modules/Pos/Views/branch-sales-targets/index.blade.php');

        self::assertStringContainsString('Schema::create(\'pos_branch_sales_targets\'', $migration);
        self::assertStringContainsString('foreignId(\'branch_id\')', $migration);
        self::assertStringContainsString('pos_branch_sales_targets_active_period_unique', $migration);
        self::assertStringContainsString('use SoftDeletes', $model);
        self::assertStringContainsString('Route::get(\'/branch-sales-targets\'', $routes);
        self::assertStringContainsString('permission:pos.branch-sales-targets.view', $routes);
        self::assertStringContainsString('pos.branch-sales-targets.create', $rbac);
        self::assertStringContainsString('pos.branch-sales-targets.delete', $rbac);
        self::assertStringContainsString('route(\'pos.branch-sales-targets.index\')', $sidebar);
        self::assertStringContainsString('selectedBranch', $controller);
        self::assertStringContainsString('assertBranch', $controller);
        self::assertStringContainsString("whereDate('period_start', '<=', \$data['period_end'])", $controller);
        self::assertStringContainsString("whereDate('period_end', '>=', \$data['period_start'])", $controller);
        self::assertStringContainsString("where('id', '<>', \$target->id)", $controller);
        self::assertStringContainsString('AuditLogger', $controller);
        self::assertStringContainsString('erpExcelButton', $index);
    }
}
