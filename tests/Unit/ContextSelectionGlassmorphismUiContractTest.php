<?php

namespace Tests\Unit;

use Tests\TestCase;

class ContextSelectionGlassmorphismUiContractTest extends TestCase
{
    public function test_program_and_branch_selection_use_the_shared_glass_layout(): void
    {
        $root = dirname(__DIR__, 2);
        $program = file_get_contents($root.'/app/Modules/Platform/Views/context/select-program.blade.php');
        $branch = file_get_contents($root.'/app/Modules/Platform/Views/context/select-branch.blade.php');
        $css = file_get_contents($root.'/public/css/app.css');

        foreach ([$program, $branch] as $view) {
            self::assertStringContainsString("@section('body-class', 'selection-page')", $view);
        }
        self::assertStringContainsString('selection-heading', $program);
        self::assertStringNotContainsString('program-card card h-100 w-100 text-start border-0 shadow-sm', $program);
        self::assertStringContainsString('context-card--glass', $branch);
        self::assertStringContainsString('btn-app-primary', $branch);
        self::assertStringContainsString('.selection-heading,', $css);
        self::assertStringContainsString('.selection-page { display: block; }', $css);
        self::assertStringContainsString('linear-gradient(135deg, rgba(255, 255, 255, .28)', $css);
        self::assertStringContainsString('backdrop-filter: blur(1.5rem)', $css);
    }
}
