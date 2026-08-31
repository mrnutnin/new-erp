<?php

namespace Tests\Unit;

use App\Modules\Settings\Rules\BranchStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BranchStatusTest extends TestCase
{
    #[Test]
    public function a_branch_can_only_be_deactivated_after_all_warehouses_are_inactive(): void
    {
        $this->assertTrue(BranchStatus::canDeactivate(0));
        $this->assertFalse(BranchStatus::canDeactivate(1));
        $this->assertTrue(BranchStatus::canDelete(0));
        $this->assertFalse(BranchStatus::canDelete(1));
    }
}
