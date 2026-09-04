<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PurchaseDocumentVoidReceiptInvariantTest extends TestCase
{
    public function test_purchase_document_void_is_blocked_after_receipt_allocation(): void
    {
        $source = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseDocumentController.php'));

        $this->assertStringContainsString("\$transition === 'void'", $source);
        $this->assertStringContainsString('$document->lines->contains(fn ($line): bool => $line->receiptAllocations->isNotEmpty())', $source);
        $this->assertStringContainsString('ยกเลิกใบตั้งหนี้ไม่ได้เมื่อมีการเชื่อมรับสินค้าแล้ว', $source);
    }
}
