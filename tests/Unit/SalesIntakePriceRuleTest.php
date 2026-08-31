<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\SalesIntakePriceRule;
use PHPUnit\Framework\TestCase;

class SalesIntakePriceRuleTest extends TestCase
{
    public function test_only_a_price_below_standard_requires_rfq(): void
    {
        self::assertTrue(SalesIntakePriceRule::requiresRfq('99.9900', '100.0000'));
        self::assertFalse(SalesIntakePriceRule::requiresRfq('100.0000', '100.0000'));
        self::assertFalse(SalesIntakePriceRule::requiresRfq(null, '100.0000'));
    }
}
