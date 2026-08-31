<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesReturnCancellationContractTest extends TestCase
{
    public function test_only_draft_sales_returns_can_be_cancelled_with_an_audit_trail(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReturnController.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/sales-returns/show.blade.php');

        self::assertStringContainsString("Route::post('/sales-returns/{salesReturn}/cancel'", $routes);
        self::assertStringContainsString("permission:pos.sales-returns.cancel", $routes);
        self::assertStringContainsString("if (\$document->status !== 'DRAFT')", $controller);
        self::assertStringContainsString("'status' => 'VOID'", $controller);
        self::assertStringContainsString('pos.sales-return.voided', $controller);
        self::assertStringContainsString('js-sales-return-cancel', $view);
    }
}
