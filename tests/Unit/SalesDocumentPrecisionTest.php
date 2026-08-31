<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\SalesDocumentPrecision;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SalesDocumentPrecisionTest extends TestCase
{
    public function test_two_decimal_storage_is_supported(): void
    {
        SalesDocumentPrecision::assertStorageCompatible(2);
        $this->addToAssertionCount(1);
    }

    public function test_higher_precision_is_rejected_before_truncation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SalesDocumentPrecision::assertStorageCompatible(3);
    }
}
