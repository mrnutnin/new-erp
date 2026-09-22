<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShopFloorResponsiveAccessibilityContractTest extends TestCase
{
    public function test_planning_board_keeps_mobile_filter_and_timeline_contract(): void
    {
        $planning = file_get_contents(__DIR__.'/../../app/Modules/Production/Views/orders/planning.blade.php');
        $css = file_get_contents(__DIR__.'/../../public/css/app.css');
        self::assertStringContainsString('planning-timeline', $planning);
        self::assertStringContainsString('planning-responsible', $planning);
        self::assertStringContainsString('planning-product', $planning);
        self::assertStringContainsString('planning-overdue', $planning);
        self::assertStringContainsString('@media (max-width: 767.98px)', $css);
        self::assertStringContainsString('planning-timeline-track', $css);
    }

    public function test_shop_floor_views_keep_touch_and_accessibility_contract(): void
    {
        $index = file_get_contents(__DIR__.'/../../app/Modules/Production/Views/shop-floor/index.blade.php');
        $show = file_get_contents(__DIR__.'/../../app/Modules/Production/Views/shop-floor/show.blade.php');

        self::assertStringContainsString('btn-lg', $index);
        self::assertStringContainsString('inputmode="search"', $index);
        self::assertStringContainsString('role="progressbar"', $index);
        self::assertStringContainsString('aria-live="polite"', $index);
        self::assertStringContainsString('role="status"', $index);
        self::assertStringContainsString('btn-lg', $show);
        self::assertStringContainsString('role="progressbar"', $show);
        self::assertStringContainsString('aria-label=', $show);
        self::assertStringContainsString('role="status"', $show);
        self::assertStringContainsString('aria-live="polite"', $show);
        self::assertStringContainsString('shop-floor-network-status', $show);
        self::assertStringContainsString('navigator.onLine', $show);
        self::assertStringContainsString("xhr.status===0", file_get_contents(__DIR__.'/../../app/Modules/Production/Views/shop-floor/index.blade.php'));
    }
}
