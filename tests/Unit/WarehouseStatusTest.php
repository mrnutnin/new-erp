<?php

namespace Tests\Unit;

use App\Modules\Settings\Rules\WarehouseStatus;
use PHPUnit\Framework\TestCase;

class WarehouseStatusTest extends TestCase
{
    public function test_only_an_inactive_warehouse_can_be_deleted(): void
    {
        $this->assertFalse(WarehouseStatus::canDelete(true));
        $this->assertTrue(WarehouseStatus::canDelete(false));
    }
}
