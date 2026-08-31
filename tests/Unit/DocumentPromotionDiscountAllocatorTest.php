<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\DocumentPromotionDiscountAllocator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DocumentPromotionDiscountAllocatorTest extends TestCase
{
    public function test_it_allocates_a_fixed_document_discount_proportionally_and_keeps_the_cent_residual_deterministic(): void
    {
        $result = DocumentPromotionDiscountAllocator::allocate([
            ['line_number' => 3, 'amount' => '33.33'],
            ['line_number' => 1, 'amount' => '33.33'],
            ['line_number' => 2, 'amount' => '33.34'],
        ], ['bill_discount_amount' => '10.00']);

        self::assertSame('10.00', $result['discount_amount']);
        self::assertSame([
            ['line_number' => 1, 'discount_amount' => '3.33'],
            ['line_number' => 2, 'discount_amount' => '3.34'],
            ['line_number' => 3, 'discount_amount' => '3.33'],
        ], $result['allocations']);
    }

    public function test_it_calculates_a_percentage_document_discount_without_a_price_list_dependency(): void
    {
        $result = DocumentPromotionDiscountAllocator::allocate([
            ['line_number' => 2, 'amount' => '80.00'],
            ['line_number' => 1, 'amount' => '120.00'],
        ], ['bill_discount_percent' => '12.50']);

        self::assertSame('25.00', $result['discount_amount']);
        self::assertSame([
            ['line_number' => 1, 'discount_amount' => '15.00'],
            ['line_number' => 2, 'discount_amount' => '10.00'],
        ], $result['allocations']);
    }

    public function test_it_rejects_a_document_discount_over_eligible_lines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('เกินยอดรายการ');

        DocumentPromotionDiscountAllocator::allocate([
            ['line_number' => 1, 'amount' => '9.99'],
        ], ['bill_discount_amount' => '10.00']);
    }
}
