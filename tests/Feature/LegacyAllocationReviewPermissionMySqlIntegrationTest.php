<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Platform\Middleware\EnsureWarehouseSelected;
use App\Modules\Platform\Middleware\EnsureProgramSelected;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class LegacyAllocationReviewPermissionMySqlIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
    }

    public function test_view_only_user_cannot_post_legacy_review_decision(): void
    {
        $view = Permission::query()->where('code', 'wms.cost-allocation-reviews.view')->firstOrFail();
        $approve = Permission::query()->where('code', 'wms.cost-allocation-reviews.approve')->firstOrFail();
        $role = Role::query()->create(['code' => 'legacy-review-viewer-'.uniqid(), 'name' => 'Legacy Review Viewer', 'is_active' => true]);
        $role->permissions()->attach($view->id);
        $user = User::factory()->create(['username' => 'legacy-review-viewer-'.uniqid()]);
        $user->roles()->attach($role->id);

        $this->withoutMiddleware([
            EnsureProgramSelected::class,
            EnsureWarehouseSelected::class,
        ]);

        $response = $this->actingAs($user)
            ->post(route('wms.legacy-allocation-reviews.approve-no-action', ['review' => 5]), [
                'reason' => 'Accounting ตรวจสอบแล้วและไม่ต้องแก้ไข Stock หรือ Cost',
            ]);

        $response->assertForbidden();

        $this->assertTrue($approve->exists());
    }
}
