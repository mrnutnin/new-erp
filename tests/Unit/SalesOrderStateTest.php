<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\SalesOrderState;
use DomainException;
use PHPUnit\Framework\TestCase;

class SalesOrderStateTest extends TestCase
{
    public function test_only_draft_order_can_be_confirmed(): void
    {
        $this->assertSame('CONFIRMED', SalesOrderState::confirm('DRAFT'));

        $this->expectException(DomainException::class);
        SalesOrderState::confirm('CONFIRMED');
    }

    public function test_only_unfulfilled_order_can_be_cancelled(): void
    {
        $this->assertSame('CANCELLED', SalesOrderState::cancel('DRAFT'));
        $this->assertSame('CANCELLED', SalesOrderState::cancel('CONFIRMED'));

        $this->expectException(DomainException::class);
        SalesOrderState::cancel('CANCELLED');
    }
}
