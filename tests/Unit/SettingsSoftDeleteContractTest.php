<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TestCase;

final class SettingsSoftDeleteContractTest extends TestCase
{
    public function test_settings_delete_routes_use_dedicated_permissions(): void
    {
        foreach ([
            'settings.users.destroy' => 'settings.users.delete',
            'settings.branches.destroy' => 'settings.branches.delete',
            'settings.warehouses.destroy' => 'settings.warehouses.delete',
            'settings.roles.destroy' => 'settings.roles.delete',
        ] as $name => $permission) {
            $route = app('router')->getRoutes()->getByName($name);

            self::assertNotNull($route, $name);
            self::assertContains('DELETE', $route->methods(), $name);
            self::assertContains("permission:{$permission}", $route->gatherMiddleware(), $name);
        }
    }

    public function test_all_settings_delete_roots_use_soft_deletes(): void
    {
        foreach ([Branch::class, Warehouse::class, User::class, Role::class] as $model) {
            self::assertContains(SoftDeletes::class, class_uses_recursive($model), $model);
        }
    }

    public function test_installer_requires_soft_delete_schema_for_settings_delete_roots(): void
    {
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));

        foreach (['branches', 'warehouses', 'users', 'roles'] as $table) {
            self::assertMatchesRegularExpression("/'{$table}'\\s*=>\\s*\\[[^\\]]*'deleted_at'/", $installer);
        }
    }

    public function test_company_setting_is_versioned_and_has_no_delete_endpoint(): void
    {
        self::assertNull(app('router')->getRoutes()->getByName('settings.company.destroy'));

        $controller = file_get_contents(base_path('app/Modules/Settings/Controllers/CompanySettingController.php'));
        self::assertStringNotContainsString('public function destroy(', $controller);
        self::assertStringContainsString('company_setting_versions', $controller);
    }
}
