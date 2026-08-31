<?php

namespace Tests\Unit;

use App\Modules\Platform\Support\ContextSelection;
use PHPUnit\Framework\TestCase;

class ContextSelectionTest extends TestCase
{
    public function test_it_routes_through_program_branch_and_dashboard_in_order(): void
    {
        $selection = new ContextSelection;

        $this->assertSame('programs.index', $selection->nextRoute(null, true, null, 'dashboard'));
        $this->assertSame('branches.index', $selection->nextRoute(1, true, null, 'dashboard'));
        $this->assertSame('dashboard', $selection->nextRoute(1, true, 1, 'dashboard'));
        $this->assertSame('accounting.index', $selection->nextRoute(1, true, 1, 'accounting.index'));
        $this->assertSame('settings.index', $selection->nextRoute(1, false, null, 'settings.index'));
    }
}
