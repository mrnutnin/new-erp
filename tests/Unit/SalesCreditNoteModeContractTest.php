<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesCreditNoteModeContractTest extends TestCase
{
    public function test_sales_credit_note_and_sales_return_have_explicit_non_overlapping_modes(): void
    {
        $creditNote = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Services/SalesDocumentPostingService.php');
        $salesReturn = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Services/SalesReturnPostingService.php');
        $journalPosting = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Accounting/Services/JournalPostingService.php');

        self::assertStringContainsString("\$postingMetadata['credit_note_mode'] = 'NON_RETURN'", $creditNote);
        self::assertStringContainsString('หากมีสินค้ารับคืนให้สร้าง Sales Return', $creditNote);
        self::assertStringNotContainsString('StockMovementService', $creditNote);
        self::assertStringContainsString("'credit_note_mode' => 'RETURN'", $salesReturn);
        self::assertStringContainsString('SalesReturnInventoryPostingService', $salesReturn);
        self::assertStringContainsString('posting_metadata.credit_note_mode', $journalPosting);
    }
}
