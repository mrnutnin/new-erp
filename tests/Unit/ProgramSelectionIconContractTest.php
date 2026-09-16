<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProgramSelectionIconContractTest extends TestCase
{
    public function test_program_selection_has_a_semantic_icon_for_each_current_module(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Platform/Views/context/select-program.blade.php');

        foreach (['bx-bar-chart-alt-2', 'bx-cog', 'bx-cart-alt', 'bx-package', 'bx-store-alt', 'bx-wallet', 'bx-calculator', 'bx-building-house'] as $icon) {
            $this->assertStringContainsString($icon, $view);
        }

        $this->assertStringContainsString("?? 'bx-grid-alt'", $view);
    }
}
