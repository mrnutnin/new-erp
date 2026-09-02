<?php

namespace Tests\Unit;

use App\Modules\Platform\Services\WorkflowStepPresenter;
use Mockery;
use Tests\TestCase;

class WorkflowStepPresenterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_missing_permission_is_distinguished_from_not_ready(): void
    {
        $user = Mockery::mock();
        $user->shouldReceive('hasPermission')->with('settings.company.view')->andReturn(false);

        $step = WorkflowStepPresenter::present([
            'permission' => 'settings.company.view',
            'route' => 'settings.company.edit',
        ], $user);

        $this->assertSame('NO_PERMISSION', $step['status_code']);
        $this->assertSame('ไม่มีสิทธิ์', $step['status']);
        $this->assertNull($step['url']);
    }

    public function test_pending_ready_route_uses_pastel_pending_status(): void
    {
        $user = Mockery::mock();
        $user->shouldReceive('hasPermission')->with('settings.company.view')->andReturn(true);

        $step = WorkflowStepPresenter::present([
            'permission' => 'settings.company.view',
            'route' => 'settings.company.edit',
            'pending_count' => 2,
            'pending_label' => 'รายการตั้งค่าค้าง',
        ], $user);

        $this->assertSame('PENDING', $step['status_code']);
        $this->assertSame('มีงานค้าง · 2 รายการ', $step['status']);
        $this->assertSame('app-status-info', $step['status_badge_class']);
        $this->assertStringContainsString('รายการตั้งค่าค้าง', $step['block_reason']);
        $this->assertNotNull($step['url']);
    }

    public function test_not_ready_step_keeps_the_safe_recovery_link_data(): void
    {
        $user = Mockery::mock();
        $user->shouldReceive('hasPermission')->with('settings.company.view')->andReturn(true);

        $step = WorkflowStepPresenter::present([
            'permission' => 'settings.company.view',
            'route' => 'settings.company.edit',
            'runtime_not_ready' => true,
            'block_reason' => 'ยังไม่ได้ตั้งค่า Account Mapping',
            'recovery_url' => '/accounting/account-mappings?event_code=asset.capitalization',
            'recovery_label' => 'ตั้งค่าการลงบัญชี',
        ], $user);

        $this->assertSame('NOT_READY', $step['status_code']);
        $this->assertNull($step['url']);
        $this->assertSame('/accounting/account-mappings?event_code=asset.capitalization', $step['recovery_url']);
        $this->assertSame('ตั้งค่าการลงบัญชี', $step['recovery_label']);
    }

    public function test_mapping_warning_keeps_the_document_route_available_for_source_override_preflight(): void
    {
        $user = Mockery::mock();
        $user->shouldReceive('hasPermission')->with('settings.company.view')->andReturn(true);

        $step = WorkflowStepPresenter::present([
            'permission' => 'settings.company.view',
            'route' => 'settings.company.edit',
            'configuration_warning' => true,
            'block_reason' => 'ยังไม่ได้ตั้งค่า Account Mapping เริ่มต้น',
        ], $user);

        $this->assertSame('CONFIGURATION_WARNING', $step['status_code']);
        $this->assertSame('ตรวจ Mapping', $step['status']);
        $this->assertNotNull($step['url']);
        $this->assertSame('ยังไม่ได้ตั้งค่า Account Mapping เริ่มต้น', $step['block_reason']);
    }
}
