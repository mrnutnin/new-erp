<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;

final class ShopFloorResponsiveAccessibilityContractTest extends TestCase
{
    public function test_shop_floor_blade_compiles_to_valid_php(): void
    {
        $compiler = new BladeCompiler(new Filesystem(), sys_get_temp_dir());
        foreach (['index', 'show', '_next-action', '_pager'] as $page) {
            $file = tempnam(sys_get_temp_dir(), 'shop-floor-');
            try {
                file_put_contents($file, $compiler->compileString(file_get_contents(__DIR__."/../../app/Modules/Production/Views/shop-floor/{$page}.blade.php")));
                exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $status);
                self::assertSame(0, $status, "{$page}: ".implode("\n", $output));
            } finally {
                unlink($file);
            }
        }
    }

    public function test_production_actions_follow_page_roles(): void
    {
        $root = __DIR__.'/../../app/Modules/Production/Views/';
        $order = file_get_contents($root.'orders/show.blade.php');
        $bomForm = file_get_contents($root.'boms/form.blade.php');
        $bomIndex = file_get_contents($root.'boms/index.blade.php');
        $issuesIndex = file_get_contents($root.'shop-floor/issues.blade.php');
        $movement = file_get_contents($root.'orders/material-movements.blade.php');

        self::assertStringContainsString("'IN_PROGRESS'=>'app-status-warning'", $order);
        self::assertLessThan(strpos($order, 'js-wo-delete'), strpos($order, 'js-finished-receipt'));
        self::assertLessThan(strpos($bomForm, 'type="submit"'), strpos($bomForm, 'id="bom-operations"'));
        self::assertStringNotContainsString('row.deactivate_url', $bomIndex);
        self::assertStringNotContainsString('js-resolve', $issuesIndex);
        self::assertStringContainsString("+'?issue_id='+encodeURIComponent(r.id)+'#issues\"", $issuesIndex);
        self::assertStringContainsString("labels={DRAFT:'ร่าง'", $movement);
        foreach (['reports', 'cost-reports', 'material-movements'] as $page) {
            $html = file_get_contents($root.'orders/'.$page.'.blade.php');
            self::assertSame(1, substr_count($html, 'ล้างตัวกรอง'));
            self::assertLessThan(strpos($html, '<form'), strpos($html, 'ล้างตัวกรอง'));
        }
    }

    public function test_shop_floor_script_has_valid_javascript(): void
    {
        exec('node --check '.escapeshellarg(__DIR__.'/../../public/js/production-shop-floor.js').' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
    }

    public function test_routing_is_optional_but_stock_issue_is_required_before_start(): void
    {
        $show = file_get_contents(__DIR__.'/../../app/Modules/Production/Views/shop-floor/show.blade.php');
        $index = file_get_contents(__DIR__.'/../../app/Modules/Production/Views/shop-floor/index.blade.php');
        $orders = file_get_contents(__DIR__.'/../../app/Modules/Production/Services/ProductionOrderService.php');
        $issues = file_get_contents(__DIR__.'/../../app/Modules/Wms/Services/IssueReturnService.php');

        self::assertStringContainsString('ไม่จำเป็นต้องมี Routing', $show);
        self::assertStringContainsString('WO นี้ไม่มี Routing · สามารถเบิกวัตถุดิบ เริ่มผลิต และรับผลิตได้ตามปกติ', $show);
        $workflow = file_get_contents(__DIR__.'/../../app/Modules/Production/Views/shop-floor/_next-action.blade.php');
        self::assertStringContainsString("in_array(\$order->status, ['RELEASED', 'IN_PROGRESS']", $workflow);
        self::assertStringContainsString("route('production.orders.finished-receipt', \$order)", $workflow);
        self::assertStringContainsString("\$issueStatus !== 'POSTED'", $index);
        self::assertStringNotContainsString("firstOrCreate(['sequence' => 1], ['name' => 'ผลิต'", $orders);
        self::assertStringContainsString("where('status', 'POSTED')->exists()", $orders);
        self::assertStringContainsString("whereIn('status', ['RELEASED', 'IN_PROGRESS'])", $issues);
        self::assertStringContainsString("whereNull('started_at')->whereNull('held_at')", $issues);
    }

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
        self::assertStringContainsString('range(0, 23)', $planning);
        self::assertStringContainsString('planning-day-scroll', $planning);
        self::assertStringContainsString('เวลาทับกัน', $planning);
        self::assertStringContainsString('ยังไม่ระบุเวลาเริ่ม–จบ', $planning);
        self::assertStringContainsString('grid-template-columns: repeat(24', $css);
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
        $workflow = file_get_contents(__DIR__.'/../../app/Modules/Production/Views/shop-floor/_next-action.blade.php');
        $script = file_get_contents(__DIR__.'/../../public/js/production-shop-floor.js');
        self::assertStringContainsString('btn-lg', $workflow);
        self::assertStringContainsString('role="progressbar"', $show);
        self::assertStringContainsString('aria-label=', $show);
        self::assertStringContainsString('role="status"', $show);
        self::assertStringContainsString('aria-live="polite"', $show);
        self::assertStringContainsString('shop-floor-network-status', $show);
        self::assertStringContainsString('sf-board-header', $index);
        self::assertStringContainsString('sf-queue', $index);
        self::assertStringContainsString('sf-job-next', $index);
        self::assertStringNotContainsString('shop-floor-action-modal', $index);
        self::assertStringContainsString('aria-current="page"', $index);
        self::assertStringContainsString('sf-detail-grid', $show);
        self::assertStringContainsString('sf-steps', $show);
        self::assertStringContainsString('sf-next', $workflow);
        self::assertStringContainsString('sf-detail-strip', $show);
        self::assertStringContainsString('shop-floor-next-action', $workflow);
        self::assertStringContainsString('bomRevision->bom?->code', $show);
        self::assertStringContainsString('$operation->notes', $show);
        self::assertStringContainsString("@forelse(\$operations as \$operation)", $show);
        self::assertStringContainsString("'bomRevision.bom:id,code'", file_get_contents(__DIR__.'/../../app/Modules/Production/Controllers/OrderController.php'));
        $css = file_get_contents(__DIR__.'/../../public/css/app.css');
        self::assertStringContainsString('@media (max-width: 991.98px)', $css);
        self::assertStringContainsString('--app-selection-background:', $css);
        self::assertStringContainsString('.auth-page,', $css);
        self::assertStringContainsString('.shop-floor-board {', $css);
        self::assertGreaterThanOrEqual(2, substr_count($css, 'background: var(--app-selection-background);'));
        self::assertStringNotContainsString("value=\"'+(name||'')+", $show);
        self::assertStringContainsString('navigator.onLine', $script);
        self::assertStringContainsString('role="tablist"', $show);
        foreach (['materials', 'routing', 'issues', 'info'] as $tab) {
            self::assertStringContainsString('aria-controls="'.$tab.'"', $show);
            self::assertStringContainsString('id="'.$tab.'" role="tabpanel"', $show);
        }
        self::assertStringContainsString('100dvh', $css);
        self::assertStringNotContainsString('min-height: 100vh', substr($css, strpos($css, '/* Tablet shop floor:')));
        self::assertStringContainsString('focus-visible', $css);
        self::assertStringContainsString("'status' => ['nullable', 'in:RELEASED,IN_PROGRESS']", file_get_contents(__DIR__.'/../../app/Modules/Production/Controllers/OrderController.php'));
    }
}
