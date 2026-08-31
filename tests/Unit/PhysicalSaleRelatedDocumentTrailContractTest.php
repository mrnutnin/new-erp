<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleRelatedDocumentTrailContractTest extends TestCase
{
    public function test_physical_sale_trail_includes_receipts_and_combined_return_credit_note_documents(): void
    {
        $base = dirname(__DIR__, 2);
        $controller = file_get_contents($base.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');
        $trail = file_get_contents($base.'/app/Modules/Pos/Views/partials/document-trail.blade.php');

        self::assertStringContainsString("\$flowDocuments['receipts'] = \$receipts", $controller);
        self::assertStringContainsString("\$flowDocuments['sales_returns'] = SalesReturn::query()", $controller);
        self::assertStringContainsString("'receipts' => ['label' => 'รับชำระหนี้'", $trail);
        self::assertStringContainsString("'sales_returns' => ['label' => 'รับคืนสินค้า / ลดหนี้'", $trail);
        self::assertStringContainsString("'route' => 'pos.receipts.show'", $trail);
        self::assertStringContainsString("'permission' => 'pos.receipts.view'", $trail);
    }
}
