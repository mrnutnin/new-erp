<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosReceivableUiContractTest extends TestCase
{
    public function test_pos_receivables_are_scoped_to_posted_iv_and_link_to_receipts(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/ReceivableController.php');
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/receivables/index.blade.php');
        $statement = file_get_contents($root.'/app/Modules/Pos/Views/receivables/show.blade.php');

        self::assertStringContainsString("where('sales.document_type', 'IV')->where('sales.status', 'POSTED')", $controller);
        self::assertStringContainsString('COALESCE(allocations.amount, 0) + COALESCE(advances.amount, 0)', $controller);
        self::assertStringContainsString("route('pos.receipts.create', ['open_item_id' => \$row->id])", $controller);
        self::assertStringContainsString("route('pos.receivables.show', ['openItem' => \$row->id])", $controller);
        self::assertStringContainsString("Route::get('/receivables'", $routes);
        self::assertStringContainsString("Route::get('/receivables/{openItem}'", $routes);
        self::assertStringContainsString('permission:pos.receivables.view', $routes);
        self::assertStringContainsString("route('pos.receivables.data')", $view);
        self::assertStringContainsString('รับชำระหนี้', $view);
        self::assertStringContainsString('r.show_url', $view);
        self::assertStringContainsString('Statement ลูกหนี้', $statement);
        self::assertStringContainsString('ประวัติรับชำระและใบลดหนี้', $statement);
        self::assertStringContainsString('ประวัติใช้เงินรับล่วงหน้า', $statement);
    }
}
