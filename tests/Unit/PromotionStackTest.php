<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\PromotionStack;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PromotionStackTest extends TestCase
{
    public function test_multiple_promotions_require_every_promotion_to_be_stackable(): void
    {
        self::assertTrue(PromotionStack::isValid([
            ['promotion_id' => 1, 'stackable' => true],
            ['promotion_id' => 2, 'stackable' => true],
        ]));
        self::assertFalse(PromotionStack::isValid([
            ['promotion_id' => 1, 'stackable' => true],
            ['promotion_id' => 2, 'stackable' => false],
        ]));
    }

    public function test_single_promotion_is_valid_even_when_not_stackable(): void
    {
        self::assertTrue(PromotionStack::isValid([['promotion_id' => 1, 'stackable' => false]]));
    }

    public function test_invalid_stack_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PromotionStack::assertValid([
            ['promotion_id' => 1, 'stackable' => false],
            ['promotion_id' => 2, 'stackable' => true],
        ]);
    }
}
