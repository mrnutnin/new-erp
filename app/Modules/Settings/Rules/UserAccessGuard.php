<?php

namespace App\Modules\Settings\Rules;

final class UserAccessGuard
{
    public function canSetActive(bool $isSelf, bool $isActive): bool
    {
        return ! $isSelf || $isActive;
    }

    public function canSetSettingsProgram(bool $isSelf, bool $hasSettingsProgram): bool
    {
        return ! $isSelf || $hasSettingsProgram;
    }

    public function canSetAdminRole(bool $isSelf, bool $currentlyAdmin, bool $willBeAdmin): bool
    {
        return ! $isSelf || ! $currentlyAdmin || $willBeAdmin;
    }

    public function canDelete(bool $isSelf, bool $isSystemAdmin): bool
    {
        return ! $isSelf && ! $isSystemAdmin;
    }
}
