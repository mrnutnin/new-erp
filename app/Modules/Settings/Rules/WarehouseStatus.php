<?php

namespace App\Modules\Settings\Rules;

final class WarehouseStatus
{
    public static function canDelete(bool $isActive): bool
    {
        return ! $isActive;
    }
}
