<?php

namespace App\Modules\Settings\Rules;

class RoleGuard
{
    public function canChangeCode(string $currentCode, string $newCode): bool
    {
        return $currentCode !== 'admin' || $newCode === 'admin';
    }

    public function canSetActive(string $code, bool $isActive): bool
    {
        return $code !== 'admin' || $isActive;
    }

    public function canDelete(string $code, int $userCount): bool
    {
        return $code !== 'admin' && $userCount === 0;
    }
}
