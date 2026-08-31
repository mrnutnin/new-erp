<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\PriceListSnapshot;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PriceListSnapshotTest extends TestCase
{
    public function test_snapshot_preserves_price_source_and_effective_date(): void
    {
        $snapshot = PriceListSnapshot::fromSelection(
            ['id' => 4, 'code' => 'RETAIL', 'currency' => 'THB', 'customer_group_code' => 'RETAIL'],
            ['id' => 9, 'item_id' => 11, 'uom_id' => 2, 'unit_price' => '125.5000', 'discount_percent' => '2.5000'],
            new DateTimeImmutable('2026-08-25'),
            new DateTimeImmutable('2026-08-25T10:00:00+07:00'),
        );

        $this->assertSame(4, $snapshot['price_list_id']);
        $this->assertSame(9, $snapshot['price_list_item_id']);
        $this->assertSame('RETAIL', $snapshot['price_list_code']);
        $this->assertSame('RETAIL', $snapshot['customer_group_code']);
        $this->assertSame('125.5000', $snapshot['unit_price']);
        $this->assertSame('2026-08-25', $snapshot['effective_on']);
        $this->assertSame('2026-08-25T10:00:00+07:00', $snapshot['captured_at']);
    }

    public function test_snapshot_rejects_missing_source_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PriceListSnapshot::fromSelection(
            ['id' => 4, 'code' => 'RETAIL'],
            ['id' => 9, 'item_id' => 11, 'unit_price' => '125.5000'],
            new DateTimeImmutable('2026-08-25'),
        );
    }
}
