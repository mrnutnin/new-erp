<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Services\PurchaseReturnPostingService;
use Tests\TestCase;

final class PurchaseReturnPostingServiceContractTest extends TestCase
{
    public function test_service_contains_atomic_return_posting_sequence(): void
    {
        $source = file_get_contents(base_path('app/Modules/Purchasing/Services/PurchaseReturnPostingService.php'));

        self::assertStringContainsString('DB::transaction', $source);
        self::assertStringContainsString("'status' => 'POSTED'", $source);
        self::assertStringContainsString('CreditPurchaseInventoryReversalAdapter', $source);
        self::assertTrue(class_exists(PurchaseReturnPostingService::class));
    }
}
