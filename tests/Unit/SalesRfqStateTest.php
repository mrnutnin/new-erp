<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\SalesRfqState;
use DomainException;
use PHPUnit\Framework\TestCase;

class SalesRfqStateTest extends TestCase
{
    public function test_rfq_lifecycle_is_explicit(): void
    {
        self::assertSame('APPROVED', SalesRfqState::approve('WAIT'));
        self::assertSame('REJECTED', SalesRfqState::reject('WAIT'));
        self::assertSame('CANCELLED', SalesRfqState::cancel('WAIT'));
        self::assertFalse(SalesRfqState::editable('WAIT'));
    }

    public function test_rfq_rejects_invalid_transitions(): void
    {
        $this->expectException(DomainException::class);
        SalesRfqState::approve('APPROVED');
    }
}
