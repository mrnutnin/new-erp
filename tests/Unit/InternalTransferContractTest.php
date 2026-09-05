<?php

namespace Tests\Unit;

use App\Modules\Finance\Support\InternalTransferContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class InternalTransferContractTest extends TestCase
{
    public function test_it_allows_the_standard_transfer_lifecycle(): void
    {
        self::assertSame('SUBMITTED', InternalTransferContract::transition('DRAFT', 'SUBMIT'));
        self::assertSame('APPROVED', InternalTransferContract::transition('SUBMITTED', 'APPROVE'));
        self::assertSame('POSTED', InternalTransferContract::transition('APPROVED', 'POST'));
        self::assertSame('REVERSED', InternalTransferContract::transition('POSTED', 'REVERSE'));
    }

    public function test_it_rejects_invalid_transfer_transitions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InternalTransferContract::transition('DRAFT', 'POST');
    }
}
