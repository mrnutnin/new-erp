<?php

namespace Tests\Unit;

use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Services\StockCostLayerService;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class StockCostLayerReceiptValueTest extends TestCase
{
    public function test_derived_receipt_value_is_rounded_to_inventory_precision(): void
    {
        $service = (new ReflectionClass(StockCostLayerService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(StockCostLayerService::class, 'trustedReceiptValue');

        $value = $method->invoke($service, new StockMovement(), BigDecimal::of('2.00000000'), '1.00000000');

        self::assertSame('2.00000000', $value->__toString());
    }
}
