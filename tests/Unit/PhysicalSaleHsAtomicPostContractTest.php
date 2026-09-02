<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleHsAtomicPostContractTest extends TestCase
{
    public function test_hs_requires_at_least_one_tender_before_posting(): void
    {
        $root = dirname(__DIR__, 2);
        $request = file_get_contents($root.'/app/Modules/Pos/Requests/PostPhysicalSaleRequest.php');

        self::assertStringContainsString("'tenders' => ['nullable', 'array', 'min:1', 'max:20']", $request);
        self::assertStringContainsString("\$sale->document_type === 'HS' && (float) \$sale->total_amount > 0 && \$this->input('tenders') === null", $request);
        self::assertStringContainsString("'ขายสดต้องระบุช่องทางรับเงินก่อนยืนยันขาย'", $request);
    }

    public function test_hs_post_keeps_tenders_in_the_same_atomic_controller_transaction(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');

        self::assertStringContainsString('DB::transaction(function () use ($request, $physicalSale, $posting, $warehouse)', $controller);
        self::assertStringContainsString("\$sale = \$posting->post(\$physicalSale, \$request->validated('posting_date'), \$warehouse, \$request->user(), \$request, \$request->validated('tenders', []));", $controller);
        self::assertStringNotContainsString('PhysicalSaleReceiptController', $controller);
        self::assertStringContainsString('}, 3);', $controller);
    }
}
