<?php

namespace Tests\Unit;

use App\Modules\Settings\Rules\UserAccessGuard;
use PHPUnit\Framework\TestCase;

class UserAccessGuardTest extends TestCase
{
    public function test_user_cannot_lock_their_own_settings_access(): void
    {
        $guard = new UserAccessGuard;

        $this->assertFalse($guard->canSetActive(true, false));
        $this->assertFalse($guard->canSetSettingsProgram(true, false));
        $this->assertFalse($guard->canSetAdminRole(true, true, false));

        $this->assertTrue($guard->canSetActive(false, false));
        $this->assertTrue($guard->canSetSettingsProgram(false, false));
        $this->assertTrue($guard->canSetAdminRole(true, false, false));
        $this->assertFalse($guard->canDelete(true, false));
        $this->assertFalse($guard->canDelete(false, true));
        $this->assertTrue($guard->canDelete(false, false));
    }
}
