<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\SalesDiscountApproval;
use PHPUnit\Framework\TestCase;

final class SalesDiscountApprovalTest extends TestCase
{
    public function test_it_excludes_price_list_discount_from_the_manual_discount_threshold(): void
    {
        $assessment = SalesDiscountApproval::assess([[
            'quantity' => '2.0000',
            'unit_price' => '100.0000',
            'discount_amount' => '20.00',
            'price_snapshot' => ['unit_price' => '100.0000', 'discount_percent' => '5.0000'],
        ]], '4.00');

        self::assertSame('10.00', $assessment['price_list_discount_amount']);
        self::assertSame('10.00', $assessment['manual_discount_amount']);
        self::assertSame('5.0000', $assessment['manual_discount_percent']);
        self::assertTrue($assessment['requires_reason']);
    }

    public function test_it_does_not_require_a_reason_when_only_price_list_discount_applies(): void
    {
        $assessment = SalesDiscountApproval::assess([[
            'quantity' => '2.0000',
            'unit_price' => '100.0000',
            'discount_amount' => '10.00',
            'price_snapshot' => ['unit_price' => '100.0000', 'discount_percent' => '5.0000'],
        ]], '0.00');

        self::assertSame('0.00', $assessment['manual_discount_amount']);
        self::assertFalse($assessment['requires_reason']);
    }

    public function test_it_treats_a_promotion_percentage_discount_as_a_standard_term(): void
    {
        $assessment = SalesDiscountApproval::assess([[
            'quantity' => '2.0000',
            'unit_price' => '100.0000',
            'discount_amount' => '20.00',
            'price_snapshot' => [
                'source' => 'PROMOTION',
                'unit_price' => '90.0000',
                'base_unit_price' => '100.0000',
                'discount_percent' => '10.0000',
            ],
        ]], '0.00');

        self::assertSame('20.00', $assessment['promotion_discount_amount']);
        self::assertSame('0.00', $assessment['manual_discount_amount']);
        self::assertFalse($assessment['requires_reason']);
    }
}
