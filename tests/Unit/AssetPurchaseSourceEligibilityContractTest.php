<?php

namespace Tests\Unit;

use App\Modules\Asset\Services\PurchaseAssetSourceEligibilityService;
use PHPUnit\Framework\TestCase;

final class AssetPurchaseSourceEligibilityContractTest extends TestCase
{
    public function test_purchase_source_identity_is_stable(): void
    {
        self::assertSame('PURCHASE_DOCUMENT', PurchaseAssetSourceEligibilityService::SOURCE_TYPE);
    }

    public function test_lookup_is_branch_scoped_and_returns_allocation_metadata(): void
    {
        $source = file_get_contents((new \ReflectionClass(PurchaseAssetSourceEligibilityService::class))->getFileName());

        self::assertStringContainsString("->where('purchase_documents.branch_id', \$branchId)", $source);
        self::assertStringContainsString("->where('purchase_documents.document_type', 'INVOICE')", $source);
        self::assertStringContainsString("->whereIn('purchase_documents.status', ['APPROVED', 'POSTED'])", $source);
        self::assertStringContainsString("->where('wms_items.is_asset_capitalizable', true)", $source);
        self::assertStringContainsString("'wms_items.default_asset_category_id as default_asset_category_id'", $source);
        self::assertStringContainsString("->leftJoin('wms_items', 'wms_items.id', '=', 'purchase_document_lines.item_id')", $source);
        self::assertStringContainsString("'purchase_documents.id as source_document_id'", $source);
        self::assertStringContainsString("'purchase_document_lines.id as source_line_id'", $source);
        self::assertStringContainsString("'purchase_document_lines.quantity as source_quantity'", $source);
        self::assertStringContainsString("'purchase_document_lines.net_amount as source_net_amount'", $source);
        self::assertStringNotContainsString('whereNotExists', $source);
    }
}
