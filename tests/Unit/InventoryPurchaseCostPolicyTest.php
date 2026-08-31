<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\InventoryPurchaseCostPolicy;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryPurchaseCostPolicyTest extends TestCase
{
    public function test_resolves_decimal_safe_unit_cost_and_immutable_value(): void
    {
        $result = InventoryPurchaseCostPolicy::resolve('100.00', '3.00000000');

        $this->assertSame('33.33333333', $result['unit_cost']);
        $this->assertSame('100.00', $result['value']);
        $this->assertSame(InventoryPurchaseCostPolicy::VERSION, $result['policy_version']);
    }

    public function test_rejects_zero_negative_or_non_none_vat_inputs(): void
    {
        foreach ([['0.00', '1.00', 'NONE_VAT'], ['10.00', '0.00', 'NONE_VAT'], ['10.00', '1.00', 'VAT_IN']] as $input) {
            try {
                InventoryPurchaseCostPolicy::resolve(...$input);
                $this->fail('Expected cost policy rejection');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
