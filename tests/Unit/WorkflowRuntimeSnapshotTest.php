<?php

namespace Tests\Unit;

use App\Models\User;
use App\Modules\Platform\Services\WorkflowRuntimeResolver;
use App\Modules\Platform\Services\WorkflowRuntimeSnapshot;
use App\Modules\Settings\Services\GlobalSettings;
use Mockery;
use PHPUnit\Framework\TestCase;

class WorkflowRuntimeSnapshotTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_snapshot_is_a_small_serializable_contract(): void
    {
        $snapshot = new WorkflowRuntimeSnapshot(
            'finance',
            [['code' => 'finance.bank-accounts', 'status' => 'READY', 'missing_count' => 0]],
            [['code' => 'finance.vouchers', 'count' => 2]],
        );

        $this->assertSame([
            'module' => 'finance',
            'readiness' => [['code' => 'finance.bank-accounts', 'status' => 'READY', 'missing_count' => 0]],
            'pending' => [['code' => 'finance.vouchers', 'count' => 2]],
        ], $snapshot->toArray());
    }

    public function test_unknown_module_has_no_runtime_queries_or_counters(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $user = Mockery::mock(User::class);

        $snapshot = (new WorkflowRuntimeResolver($settings))->snapshot('unknown', $user, 999);

        $this->assertSame(['module' => 'unknown', 'readiness' => [], 'pending' => []], $snapshot->toArray());
    }
}
