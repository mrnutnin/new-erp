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

    public function test_program_selection_displays_the_company_brand_next_to_the_heading(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Platform/Controllers/ContextController.php');
        $view = file_get_contents($root.'/app/Modules/Platform/Views/context/select-program.blade.php');

        $this->assertStringContainsString('GlobalSettings $globalSettings', $controller);
        $this->assertStringContainsString("'companySetting' => \$globalSettings->current()", $controller);
        $this->assertStringContainsString("'companyLogoDataUri' => \$globalSettings->logoDataUri()", $controller);
        $this->assertStringContainsString('program-selection-brand', $view);
        $this->assertStringContainsString('$companyLogoDataUri', $view);
        $this->assertStringContainsString('$companySetting->company_name', $view);
        $this->assertStringNotContainsString("route('settings.company.logo')", $view);
    }
}
