<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\WmsDecimal;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

final class WmsDecimalFormatTest extends TestCase
{
    public function test_it_formats_strings_and_big_decimals_without_float_conversion(): void
    {
        self::assertSame('9,999,999,999,999,999.13', WmsDecimal::format('9999999999999999.125', 2));
        self::assertSame('-1,234.568', WmsDecimal::format(BigDecimal::of('-1234.5678'), 3));
        self::assertSame('0', WmsDecimal::format('0', 0));
        self::assertSame('-', WmsDecimal::format(null, 2));
    }
}
