<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PromotionResolverContractTest extends TestCase
{
    public function test_resolver_selects_one_effective_promotion_by_priority_then_newest_id(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Services/PromotionResolver.php');

        $this->assertStringContainsString("->where('pos_promotions.is_active', true)", $source);
        $this->assertStringContainsString("->where('pos_promotions.application_scope', 'LINE')", $source);
        $this->assertStringContainsString("->where('pos_promotion_items.minimum_quantity', '<=', \$quantity)", $source);
        $this->assertStringContainsString("->orderByDesc('pos_promotions.priority')", $source);
        $this->assertStringContainsString("->orderByDesc('pos_promotions.id')", $source);
        $this->assertStringContainsString('PromotionSnapshot::fromSelection', $source);
        $this->assertStringContainsString('resolveAll', $source);
        $this->assertStringContainsString("->where('pos_promotions.id', \$promotionId)", $source);
        $this->assertStringContainsString('resolveDocument', $source);
        $this->assertStringContainsString('resolveDocumentAll', $source);
        $this->assertStringContainsString("->where('application_scope', 'DOCUMENT')", $source);
        $this->assertStringContainsString('PromotionSnapshot::documentFromSelection', $source);
    }
}
