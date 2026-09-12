<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\Wms\Models\CostRevaluationRun;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Platform\Middleware\EnsureProgramSelected;
use App\Modules\Platform\Middleware\EnsureWarehouseSelected;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CostRevaluationPermissionMySqlIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
    }

    #[DataProvider('protectedActionRoutes')]
    public function test_user_without_revaluation_action_permission_receives_forbidden(string $routeName, array $parameters): void
    {
        $user = User::factory()->create(['username' => 'revaluation-viewer-'.uniqid()]);

        if (isset($parameters['run'])) {
            $parameters['run'] = $this->protectedRunId();
        }

        $this->withoutMiddleware([
            EnsureProgramSelected::class,
            EnsureWarehouseSelected::class,
        ]);

        $this->actingAs($user)
            ->post(route($routeName, $parameters), [])
            ->assertForbidden();
    }

    public function test_admin_role_has_all_revaluation_lifecycle_permissions(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();

        foreach (self::revaluationPermissions() as $permission) {
            $this->assertTrue($admin->hasPermission($permission), "admin ต้องมี {$permission}");
        }
    }

    public function test_role_grant_is_scoped_to_the_granted_revaluation_action(): void
    {
        $role = Role::query()->create([
            'code' => 'revaluation-trigger-positive-'.uniqid(),
            'name' => 'Positive Revaluation Trigger Test',
            'description' => 'test-only',
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'wms.cost-revaluation.trigger')->value('id'));
        $user = User::factory()->create(['username' => 'revaluation-trigger-positive-'.uniqid()]);
        $user->roles()->attach($role->id);

        $this->assertTrue($user->hasPermission('wms.cost-revaluation.trigger'));
        $this->assertFalse($user->hasPermission('wms.cost-revaluation.approve'));
        $this->assertFalse($user->hasPermission('wms.cost-revaluation.post'));
        $this->assertFalse($user->hasPermission('wms.cost-revaluation.recover'));
        $this->assertFalse($user->hasPermission('wms.cost-revaluation.cancel'));
        $this->assertFalse($user->hasPermission('wms.cost-revaluation.emergency-rebuild'));
    }

    /** @return list<string> */
    private static function revaluationPermissions(): array
    {
        return [
            'wms.cost-revaluation.trigger',
            'wms.cost-revaluation.approve',
            'wms.cost-revaluation.post',
            'wms.cost-revaluation.recover',
            'wms.cost-revaluation.cancel',
            'wms.cost-revaluation.emergency-rebuild',
        ];
    }

    private function protectedRunId(): int
    {
        $existing = CostRevaluationRun::query()->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $allocation = CostAllocation::query()->where('status', 'POSTED')->firstOrFail();

        return (int) CostRevaluationRun::query()->create([
            'idempotency_key' => 'permission-test-'.uniqid(),
            'root_allocation_id' => $allocation->id,
            'status' => 'PENDING_APPROVAL',
            'proposed_unit_cost' => (string) $allocation->unit_cost,
            'posting_date' => $allocation->business_date?->format('Y-m-d'),
            'nodes_affected' => 0,
            'estimated_delta_value' => '0.00000000',
            'shadow_snapshot' => ['contract' => 'permission-test'],
            'requested_by' => User::query()->where('username', 'admin')->value('id'),
        ])->id;
    }

    public static function protectedActionRoutes(): array
    {
        return [
            'approve' => ['wms.stock-valuation.revaluation.approve', ['run' => 194]],
            'apply' => ['wms.stock-valuation.revaluation.apply', ['run' => 194]],
            'post journal' => ['wms.stock-valuation.revaluation.post-journal', ['run' => 194]],
            'complete' => ['wms.stock-valuation.revaluation.complete', ['run' => 194]],
            'resume' => ['wms.stock-valuation.revaluation.resume', ['run' => 194]],
            'cancel' => ['wms.stock-valuation.revaluation.cancel', ['run' => 194]],
            'emergency rebuild' => ['wms.stock-valuation.emergency-rebuild.dispatch', []],
        ];
    }
}
