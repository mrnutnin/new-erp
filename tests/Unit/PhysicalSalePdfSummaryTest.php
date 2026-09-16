<?php

namespace Tests\Unit;

use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Support\PhysicalSalePdfSummary;
use Tests\TestCase;

final class PhysicalSalePdfSummaryTest extends TestCase
{
    public function test_multiple_deposits_and_payments_are_added_without_reversed_applications(): void
    {
        $sale = new PhysicalSale(['total_amount' => '1070.00', 'withholding_amount' => '30.00']);
        $sale->setRelation('advanceDepositApplications', collect([
            (object) ['amount' => '100.10', 'reversed_at' => null],
            (object) ['amount' => '199.90', 'reversed_at' => null],
            (object) ['amount' => '500.00', 'reversed_at' => '2026-09-01'],
        ]));
        $sale->setRelation('tenders', collect([(object) ['amount' => '240.25'], (object) ['amount' => '499.75']]));
        $summary = PhysicalSalePdfSummary::build($sale);
        self::assertCount(2, $summary['deposits']);
        self::assertSame('300.00', $summary['deposit_total']);
        self::assertSame('740.00', $summary['net']);
        self::assertSame('740.00', $summary['received']);
        self::assertSame('0.00', $summary['remaining']);
    }
}
