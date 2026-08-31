<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\PromotionSnapshot;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PromotionSnapshotTest extends TestCase
{
    public function test_snapshot_identifies_the_promotion_source_and_fixed_price_rule(): void
    {
        $snapshot = PromotionSnapshot::fromSelection(
            ['id' => 4, 'code' => 'PROMO-AUG', 'currency' => 'THB', 'customer_group_code' => 'RETAIL'],
            ['id' => 9, 'item_id' => 11, 'uom_id' => 2, 'unit_price' => '99.0000', 'discount_percent' => null],
            new DateTimeImmutable('2026-08-30'),
            new DateTimeImmutable('2026-08-30T10:00:00+07:00'),
        );

        $this->assertSame('PROMOTION', $snapshot['source']);
        $this->assertSame(4, $snapshot['promotion_id']);
        $this->assertSame('99.0000', $snapshot['unit_price']);
        $this->assertNull($snapshot['discount_percent']);
    }

    public function test_percentage_promotion_calculates_its_own_effective_price_without_a_price_list(): void
    {
        $snapshot = PromotionSnapshot::fromSelection(
            ['id' => 4, 'code' => 'PROMO-AUG', 'currency' => 'THB'],
            ['id' => 9, 'item_id' => 11, 'unit_price' => null, 'base_unit_price' => '125.5000', 'discount_percent' => '2.5000'],
            new DateTimeImmutable('2026-08-30'),
        );

        $this->assertSame('122.3625', $snapshot['unit_price']);
        $this->assertSame('125.5000', $snapshot['base_unit_price']);
        $this->assertSame('2.5000', $snapshot['discount_percent']);
    }

    public function test_snapshot_rejects_ambiguous_promotion_rule(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PromotionSnapshot::fromSelection(
            ['id' => 4, 'code' => 'PROMO-AUG', 'currency' => 'THB'],
            ['id' => 9, 'item_id' => 11, 'unit_price' => '99.0000', 'base_unit_price' => '100.0000', 'discount_percent' => '5.0000'],
            new DateTimeImmutable('2026-08-30'),
        );
    }

    public function test_document_promotion_snapshots_one_bill_rule_and_calculates_percent(): void
    {
        $snapshot = PromotionSnapshot::documentFromSelection(
            ['id' => 8, 'code' => 'PROMO-BILL', 'currency' => 'THB', 'application_scope' => 'DOCUMENT', 'stackable' => true, 'bill_discount_percent' => '10.0000'],
            new DateTimeImmutable('2026-08-30'),
        );

        $this->assertSame('DOCUMENT', $snapshot['application_scope']);
        $this->assertTrue($snapshot['stackable']);
        $this->assertSame('50.00', PromotionSnapshot::documentDiscountAmount($snapshot, '500.00'));
    }

    public function test_document_promotion_rejects_both_bill_discount_rules(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PromotionSnapshot::documentFromSelection(
            ['id' => 8, 'code' => 'PROMO-BILL', 'currency' => 'THB', 'application_scope' => 'DOCUMENT', 'bill_discount_amount' => '20.0000', 'bill_discount_percent' => '10.0000'],
            new DateTimeImmutable('2026-08-30'),
        );
    }
}
