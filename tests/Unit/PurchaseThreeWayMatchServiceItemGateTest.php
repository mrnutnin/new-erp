<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Support\PurchaseThreeWayMatchGate;
use PHPUnit\Framework\TestCase;

final class PurchaseThreeWayMatchServiceItemGateTest extends TestCase
{
    public function test_three_way_match_only_applies_to_goods_not_asset_services(): void
    {
        $source = file_get_contents((new \ReflectionClass(PurchaseThreeWayMatchGate::class))->getFileName());

        self::assertStringContainsString("'lines.item:id,item_type'", $source);
        self::assertStringContainsString('$line->item?->item_type === \'GOODS\'', $source);
    }
}
