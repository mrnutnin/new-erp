<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AppSidebarCollapseContractTest extends TestCase
{
    public function test_shared_layout_has_responsive_collapsible_sidebar_navigation(): void
    {
        $layout = file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/app.blade.php');
        $css = file_get_contents(dirname(__DIR__, 2).'/public/css/app.css');

        self::assertStringContainsString('id="app-sidebar-toggle"', $layout);
        self::assertStringContainsString('id="app-sidebar-mobile-toggle"', $layout);
        self::assertStringContainsString('app-sidebar-backdrop', $layout);
        self::assertStringContainsString("'erp.sidebar.collapsed'", $layout);
        self::assertStringContainsString('aria-expanded', $layout);
        self::assertStringContainsString('positionFlyout', $layout);
        self::assertStringContainsString('closeFlyouts', $layout);
        self::assertStringContainsString('collapseButtons.forEach', $layout);
        self::assertStringContainsString('stopImmediatePropagation', $layout);
        self::assertStringContainsString("localStorage.setItem(key, '0')", $layout);
        self::assertStringContainsString('.app-sidebar-backdrop {', $css);
        self::assertStringContainsString('display: none;', $css);
        self::assertStringContainsString('.app-sidebar-collapsed', $css);
        self::assertStringContainsString('app-sidebar-flyout-open', $css);
        self::assertStringContainsString('[data-bs-toggle="collapse"]:focus + .collapse', $css);
        self::assertStringContainsString('.app-sidebar-mobile-open', $css);
        self::assertStringContainsString('transform: translateX(-105%)', $css);
    }
}
