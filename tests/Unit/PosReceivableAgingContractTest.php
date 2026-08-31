<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PosReceivableAgingContractTest extends TestCase
{
    public function test_pos_aging_uses_only_posted_iv_balances_as_of_the_selected_date(): void
    {
        $root = base_path();
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/ReceivableController.php');
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/receivables/aging.blade.php');

        self::assertStringContainsString('public function agingData', $controller);
        self::assertStringContainsString("where('sales.document_type', 'IV')->where('sales.status', 'POSTED')", $controller);
        self::assertStringContainsString('COALESCE(allocations.amount, 0) - COALESCE(advances.amount, 0)', $controller);
        self::assertStringContainsString("Route::get('/receivables/aging'", $routes);
        self::assertStringContainsString("name('receivables.aging.data')", $routes);
        self::assertStringContainsString('permission:pos.receivables.view', $routes);
        self::assertStringContainsString("route('pos.receivables.aging.data')", $view);
        self::assertStringContainsString('ยังไม่ครบกำหนด', $view);
        self::assertStringContainsString('มากกว่า 90 วัน', $view);
        self::assertStringContainsString("route('pos.receivables.index', ['party_id' => \$row->party_id])", $controller);
    }
}
