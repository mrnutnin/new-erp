<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\BackdatedMovementGate;
use DomainException;
use PHPUnit\Framework\TestCase;

class BackdatedMovementGateTest extends TestCase
{
    public function test_open_period_status_is_allowed(): void
    {
        BackdatedMovementGate::assertStatusOpen('OPEN');
        $this->assertTrue(true);
    }

    public function test_soft_closed_period_status_is_rejected_with_recovery_guidance(): void
    {
        try {
            BackdatedMovementGate::assertStatusOpen('SOFT_CLOSE');
            $this->fail('Expected a domain exception.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('เปิดงวดบัญชี', $exception->getMessage());
        }
    }

    public function test_locked_period_status_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        BackdatedMovementGate::assertStatusOpen('LOCKED');
    }
}
