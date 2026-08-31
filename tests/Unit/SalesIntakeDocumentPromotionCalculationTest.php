<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\DocumentPromotionDiscountAllocator;
use App\Modules\Pos\Support\SalesIntakeCalculator;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

final class SalesIntakeDocumentPromotionCalculationTest extends TestCase
{
    public function test_document_promotion_is_allocated_after_line_discount_and_before_vat(): void
    {
        $allocation = DocumentPromotionDiscountAllocator::allocate([
            ['line_number' => 1, 'amount' => '90.00'],
            ['line_number' => 2, 'amount' => '100.00'],
        ], ['bill_discount_percent' => '10.00']);
        $discounts = collect($allocation['allocations'])->pluck('discount_amount', 'line_number');
        $calculation = (new SalesIntakeCalculator)->calculate([
            ['line_number' => 1, 'quantity' => '1', 'unit_price' => '100.00', 'discount_amount' => '10.00', 'promotion_discount_amount' => '10.00', 'tax_rate' => '7.00'],
            ['line_number' => 2, 'quantity' => '1', 'unit_price' => '100.00', 'discount_amount' => '0.00', 'promotion_discount_amount' => '0.00', 'tax_rate' => '7.00'],
        ], 'VAT_OUT', false, 2);

        foreach ($calculation['lines'] as &$line) {
            $line['discount_amount'] = BigDecimal::of($line['discount_amount'])->plus($discounts[$line['line_number']])->__toString();
        }
        unset($line);
        $calculation = (new SalesIntakeCalculator)->calculate($calculation['lines'], 'VAT_OUT', false, 2);

        self::assertSame('19.00', $allocation['discount_amount']);
        self::assertSame('171.00', $calculation['tax_base']);
        self::assertSame('11.97', $calculation['tax_amount']);
        self::assertSame('182.97', $calculation['grand_total']);
    }

    public function test_intake_controller_keeps_free_goods_valid_and_persists_document_promotion_allocations(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Controllers/SalesIntakeController.php');

        self::assertStringContainsString('if ($net->isPositive())', $controller);
        self::assertStringContainsString('DocumentPromotionDiscountAllocator::allocate($eligible, $promotion)', $controller);
        self::assertStringContainsString("'promotion_discount_amount' => \$documentPromotionDiscount", $controller);
        self::assertStringContainsString("'promotion_discount_amount' => \$line['promotion_discount_amount']", $controller);
    }
}
