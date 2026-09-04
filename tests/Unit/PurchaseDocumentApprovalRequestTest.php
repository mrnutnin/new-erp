<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Requests\ChangePurchaseDocumentStatusRequest;
use PHPUnit\Framework\TestCase;

final class PurchaseDocumentApprovalRequestTest extends TestCase
{
    public function test_approval_reason_is_optional_for_both_purchase_route_prefixes(): void
    {
        $source = file_get_contents((new \ReflectionClass(ChangePurchaseDocumentStatusRequest::class))->getFileName());

        self::assertStringContainsString("'purchasing.purchase-documents.approve'", $source);
        self::assertStringContainsString('$isApprove ? \'nullable\' : \'required\'', $source);
    }
}
