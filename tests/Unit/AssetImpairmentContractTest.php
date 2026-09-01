<?php

namespace Tests\Unit;

use App\Modules\Asset\Services\AssetImpairmentService;
use PHPUnit\Framework\TestCase;

final class AssetImpairmentContractTest extends TestCase
{
    public function test_impairment_uses_recoverable_amount_as_future_basis(): void
    {
        $result = (new AssetImpairmentService)->assess(100000, 72000);
        self::assertSame(28000.0, $result['impairment_amount']);
        self::assertSame(72000.0, $result['future_depreciation_basis']);
    }

    public function test_recoverable_amount_above_carrying_amount_has_no_impairment(): void
    {
        self::assertSame(0.0, (new AssetImpairmentService)->assess(100000, 120000)['impairment_amount']);
    }
}
