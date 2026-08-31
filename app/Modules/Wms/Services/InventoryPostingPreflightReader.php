<?php

namespace App\Modules\Wms\Services;

interface InventoryPostingPreflightReader
{
    /** @return array<string, mixed> */
    public function summary(int $warehouseId): array;
}
