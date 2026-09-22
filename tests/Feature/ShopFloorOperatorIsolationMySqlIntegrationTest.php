<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Platform\Middleware\EnsureProgramSelected;
use App\Modules\Platform\Middleware\EnsureWarehouseSelected;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class ShopFloorOperatorIsolationMySqlIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
    }

    public function test_shop_floor_operator_cannot_open_wms_or_accounting_menus(): void
    {
        $permissions = Permission::query()->whereIn('code', [
            'production.shop_floor.use',
            'wms.dashboard.view',
            'accounting.accounts.view',
        ])->get()->keyBy('code');
        $role = Role::query()->create(['code' => 'shop-floor-isolation-'.uniqid(), 'name' => 'Shop Floor Isolation Test', 'is_active' => true]);
        $role->permissions()->attach($permissions['production.shop_floor.use']->id);
        $user = User::factory()->create(['username' => 'shop-floor-isolation-'.uniqid()]);
        $user->roles()->attach($role->id);

        $this->withoutMiddleware([EnsureProgramSelected::class, EnsureWarehouseSelected::class]);
        $this->actingAs($user)->get('/wms')->assertForbidden();
        $this->actingAs($user)->get('/accounting/accounts')->assertForbidden();
    }
}
