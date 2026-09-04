<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PurchaseCreditNoteModeContractTest extends TestCase
{
    public function test_non_return_credit_note_is_financial_only_and_return_mode_is_required_for_inventory_reversal(): void
    {
        $posting = file_get_contents(base_path('app/Modules/Wms/Services/PurchaseDocumentPostingService.php'));
        $reversal = file_get_contents(base_path('app/Modules/Wms/Services/CreditPurchaseInventoryReversalAdapter.php'));

        self::assertStringContainsString("['RETURN', 'NON_RETURN']", $posting);
        self::assertStringContainsString("credit_note_mode !== 'RETURN'", $reversal);
    }
}
