<?php

namespace Tests\Unit;

use App\Modules\Accounting\Support\FiscalPeriodState;
use DomainException;
use PHPUnit\Framework\TestCase;

class FiscalPeriodStateTest extends TestCase
{
    public function test_it_enforces_soft_close_and_reopen_transitions(): void
    {
        $this->assertSame('SOFT_CLOSE', FiscalPeriodState::softClose('OPEN'));
        $this->assertSame('OPEN', FiscalPeriodState::reopen('SOFT_CLOSE'));
        $this->assertSame('OPEN', FiscalPeriodState::reopen('LOCKED'));
        $this->assertSame('LOCKED', FiscalPeriodState::lock('SOFT_CLOSE'));

        $this->expectException(DomainException::class);
        FiscalPeriodState::softClose('SOFT_CLOSE');
    }

    public function test_it_rejects_reopening_an_open_period(): void
    {
        $this->expectException(DomainException::class);
        FiscalPeriodState::reopen('OPEN');
    }

    public function test_it_rejects_locking_before_soft_close(): void
    {
        $this->expectException(DomainException::class);
        FiscalPeriodState::lock('OPEN');
    }
}
