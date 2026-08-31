<?php

namespace App\Modules\Settings\Rules;

final class BranchStatus
{
    public static function canDeactivate(int $activeWarehouseCount): bool
    {
        return $activeWarehouseCount === 0;
    }

    public static function canDelete(int $warehouseCount): bool
    {
        return $warehouseCount === 0;
    }
}
