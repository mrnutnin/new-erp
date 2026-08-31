<?php

namespace Tests\Unit;

use App\Modules\Settings\Rules\RoleGuard;
use PHPUnit\Framework\TestCase;

class RoleGuardTest extends TestCase
{
    public function test_admin_role_code_and_active_status_are_protected(): void
    {
        $guard = new RoleGuard;

        $this->assertFalse($guard->canChangeCode('admin', 'administrator'));
        $this->assertTrue($guard->canChangeCode('admin', 'admin'));
        $this->assertFalse($guard->canSetActive('admin', false));
        $this->assertTrue($guard->canSetActive('admin', true));
        $this->assertTrue($guard->canChangeCode('sales', 'sales-manager'));
        $this->assertTrue($guard->canSetActive('sales', false));
        $this->assertFalse($guard->canDelete('admin', 0));
        $this->assertFalse($guard->canDelete('sales', 1));
        $this->assertTrue($guard->canDelete('sales', 0));
    }
}
