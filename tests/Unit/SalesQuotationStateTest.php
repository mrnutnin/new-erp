<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\SalesQuotationState;
use DomainException;
use PHPUnit\Framework\TestCase;

class SalesQuotationStateTest extends TestCase
{
    public function test_valid_transitions(): void
    {
        self::assertSame('SENT', SalesQuotationState::send('DRAFT'));
        self::assertSame('ACCEPTED', SalesQuotationState::accept('SENT'));
        self::assertSame('REJECTED', SalesQuotationState::reject('SENT'));
        self::assertSame('CANCELLED', SalesQuotationState::cancel('DRAFT'));
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        SalesQuotationState::accept('DRAFT');
    }
}
