<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Support\PurchaseRequisitionState;
use DomainException;
use PHPUnit\Framework\TestCase;

class PurchaseRequisitionStateTest extends TestCase
{
    public function test_requisition_transitions_are_explicit_and_safe(): void
    {
        $this->assertSame('SUBMITTED', PurchaseRequisitionState::submit('DRAFT'));
        $this->assertSame('APPROVED', PurchaseRequisitionState::approve('SUBMITTED'));
        $this->assertSame('REJECTED', PurchaseRequisitionState::reject('SUBMITTED'));
        $this->assertSame('VOID', PurchaseRequisitionState::void('APPROVED'));
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        PurchaseRequisitionState::approve('DRAFT');
    }
}
