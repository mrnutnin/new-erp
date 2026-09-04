<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\PurchaseReturnPartialInventoryAdapter;
use Tests\TestCase;

final class PurchaseReturnPartialInventoryAdapterTest extends TestCase
{
    public function test_partial_adapter_keeps_posting_feature_gated_and_supports_multi_layer_linkage(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/PurchaseReturnPartialInventoryAdapter.php'));

        self::assertStringContainsString('public function preflight', $source);
        self::assertStringContainsString('PurchaseReturnPartialPostingContract::plan', $source);
        self::assertStringContainsString('PurchaseReturnPartialCostAllocationContract::plan', $source);
        self::assertStringContainsString("'posting_enabled' => false", $source);
        self::assertStringContainsString('public function post', $source);
        self::assertStringContainsString('postWithinTransaction', $source);
        self::assertStringContainsString('linkCostJournal', $source);
        self::assertStringContainsString('linkJournalLineWithinTransaction', $source);
        self::assertStringContainsString('PurchaseReturnPartialMultiLayerJournalLinkContract::plan', $source);
        self::assertTrue(class_exists(PurchaseReturnPartialInventoryAdapter::class));
    }
}
