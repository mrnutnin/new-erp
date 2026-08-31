<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PricingResolverContractTest extends TestCase
{
    public function test_direct_invoice_pricing_uses_only_price_lists(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Services/PricingResolver.php');

        self::assertStringContainsString('$this->priceLists->resolve', $source);
        self::assertStringNotContainsString('$this->promotions->resolve', $source);
        self::assertStringContainsString("if ((\$snapshot['source'] ?? null) === 'PROMOTION'", $source);
    }
}
