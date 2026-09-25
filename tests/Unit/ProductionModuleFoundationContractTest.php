<?php

namespace Tests\Unit;

use Tests\TestCase;

final class ProductionModuleFoundationContractTest extends TestCase
{
    public function test_optional_production_module_shell_is_registered_and_guarded(): void
    {
        $root = dirname(__DIR__, 2);
        $providers = file_get_contents($root.'/bootstrap/providers.php');
        $routes = file_get_contents($root.'/app/Modules/Production/Routes/web.php');
        $wmsRoutes = file_get_contents($root.'/app/Modules/Wms/Routes/web.php');

        self::assertFileExists($root.'/app/Modules/Production/Providers/ProductionServiceProvider.php');
        self::assertFileExists($root.'/app/Modules/Production/Controllers/EntryController.php');
        self::assertFileExists($root.'/app/Modules/Production/Views/dashboard.blade.php');
        self::assertStringContainsString('ProductionServiceProvider::class', $providers);
        self::assertStringContainsString("'program:production'", $routes);
        self::assertStringContainsString("'capability:production'", $routes);
        self::assertStringContainsString("'permission:production.dashboard.view'", $routes);
        self::assertStringNotContainsString('permission:wms.', $routes);
        self::assertStringNotContainsString('permission:accounting.', $routes);
        self::assertStringNotContainsString('capability:production', $wmsRoutes);
    }

    public function test_production_program_permissions_and_local_workflow_contract_are_seeded(): void
    {
        $root = dirname(__DIR__, 2);
        $databaseSeeder = file_get_contents($root.'/database/seeders/DatabaseSeeder.php');
        $defaults = file_get_contents($root.'/app/Modules/Installer/Services/SystemDefaultOrchestrator.php');
        $rbac = file_get_contents($root.'/database/seeders/RbacSeeder.php');
        $roles = file_get_contents($root.'/app/Modules/Settings/Controllers/RoleController.php');
        $dashboard = file_get_contents($root.'/app/Modules/Production/Views/dashboard.blade.php');
        $routes = file_get_contents($root.'/app/Modules/Production/Routes/web.php');
        $sidebar = file_get_contents($root.'/app/Modules/Production/Views/partials/sidebar.blade.php');
        $workflow = file_get_contents($root.'/app/Modules/Production/Views/workflow/index.blade.php');
        $show = file_get_contents($root.'/app/Modules/Production/Views/orders/show.blade.php');
        $checklist = file_get_contents($root.'/PRODUCTION_MVP_CHECKLIST.md');

        self::assertStringContainsString("'code' => 'production'", $databaseSeeder);
        self::assertStringContainsString("'entry_route' => 'production.index'", $databaseSeeder);
        self::assertStringContainsString("'code' => 'production'", $defaults);
        self::assertStringContainsString("'core.programs' => '1.2'", $defaults);
        self::assertStringContainsString("'core.rbac' => '3.0'", $defaults);
        self::assertStringContainsString("'production.dashboard.view' =>", $rbac);
        self::assertStringContainsString("'production.orders.delete' =>", $rbac);
        self::assertStringContainsString("'production.execution.post' =>", $rbac);
        self::assertStringContainsString("'production.shop_floor.use' =>", $rbac);
        self::assertStringContainsString("'shop_floor_operator' =>", $defaults);
        self::assertStringContainsString('$adminRole->permissions()->sync(Permission::query()->pluck(\'id\'));', $rbac);
        self::assertStringContainsString("\$code === 'production.shop_floor.use'", $defaults);
        self::assertStringContainsString("'production' => 'การผลิต'", $roles);
        self::assertStringContainsString('Made to Order', $dashboard);
        self::assertStringContainsString('production.*', $dashboard);
        self::assertStringContainsString('production.execution.approve', $routes);
        self::assertStringContainsString('production.execution.reverse', $routes);
        self::assertStringContainsString("route('production.workflow.index')", $sidebar);
        self::assertStringContainsString("Route::get('/workflow', [WorkflowController::class, 'index'])", $routes);
        self::assertStringContainsString('Platform::workflow._workflow-card', $workflow);
        self::assertStringContainsString('permission:production.orders.gl.view', $routes);
        self::assertStringNotContainsString('permission:wms.', $routes);
        self::assertStringNotContainsString('permission:accounting.', $routes);
        self::assertStringNotContainsString("hasPermission('wms.", $show);
        self::assertStringNotContainsString("hasPermission('accounting.", $show);
        self::assertStringContainsString("hasPermission('production.execution.reverse')", $show);
        self::assertStringContainsString('- [x] Permission contract: Production workflow ไม่ต้องใช้ `wms.*`/`accounting.*`', $checklist);
        self::assertStringContainsString('- [x] Approve/reverse/GL/cost scoped permissions ในหน้า WO', $checklist);
    }

    public function test_every_production_permission_route_uses_seeded_production_capability(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/app/Modules/Production/Routes/web.php');
        $rbac = file_get_contents($root.'/database/seeders/RbacSeeder.php');
        preg_match_all("/middleware\\('permission:([^']+)'\\)/", $routes, $matches);

        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $permissionGroup) {
            foreach (explode('|', $permissionGroup) as $permission) {
                self::assertStringStartsWith('production.', $permission);
                self::assertStringContainsString("'{$permission}' =>", $rbac);
            }
        }
    }

    public function test_production_dashboard_shows_operational_metrics(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Production/Controllers/EntryController.php');
        $dashboard = file_get_contents($root.'/app/Modules/Production/Views/dashboard.blade.php');
        $checklist = file_get_contents($root.'/PRODUCTION_MVP_CHECKLIST.md');

        self::assertStringContainsString("'draft' =>", $controller);
        self::assertStringContainsString("'in_progress' =>", $controller);
        self::assertStringContainsString("'due_soon' =>", $controller);
        self::assertStringContainsString("'overdue' =>", $controller);
        self::assertStringContainsString("'shortage' =>", $controller);
        self::assertStringContainsString("'ready_to_receive' =>", $controller);
        self::assertStringContainsString('materialReadiness($order)', $controller);
        self::assertStringContainsString('$metrics', $dashboard);
        self::assertStringContainsString('พร้อมรับผลิตเสร็จ', $dashboard);
        self::assertStringContainsString('- [x] Production Dashboard metrics', $checklist);
    }
}
