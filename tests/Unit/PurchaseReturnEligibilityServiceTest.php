<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Services\PurchaseReturnEligibilityService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseReturnEligibilityServiceTest extends TestCase
{
    public function test_remaining_quantity_is_decimal_safe_and_rejects_over_return(): void
    {
        $service = new PurchaseReturnEligibilityService;

        $this->assertSame('6.50000000', $service->remainingQuantity('10.00000000', '3.50000000')->__toString());
        $service->remainingQuantity('10', '10');

        $this->expectException(ValidationException::class);
        $service->remainingQuantity('10', '10.00000001');
    }

    public function test_zero_and_over_remaining_requested_quantity_are_rejected(): void
    {
        $service = new PurchaseReturnEligibilityService;

        $this->expectException(ValidationException::class);
        $service->assertQuantityAllowed('0.00000000', '1.00000000');
    }
}
