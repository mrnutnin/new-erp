<?php

namespace Tests\Unit;

use Tests\TestCase;

class SidebarSubmenuIndentationTest extends TestCase
{
    public function test_every_module_submenu_uses_the_same_visual_style(): void
    {
        $css = file_get_contents(base_path('public/css/app.css'));

        self::assertMatchesRegularExpression('/\\.app-sidebar \\[id\\$="-menu"\\] > \\.list-group-item \\{\\s*padding-left: 2\\.5rem !important;\\s*font-size: 1rem !important;\\s*font-weight: 600 !important;\\s*\\}/', $css);
        self::assertStringContainsString('.app-sidebar .list-group-item.ps-4[data-bs-toggle="collapse"] { padding-left: 1rem !important; }', $css);
        self::assertStringNotContainsString('[id^="pos-"][id$="-menu"]', $css);
    }
}
