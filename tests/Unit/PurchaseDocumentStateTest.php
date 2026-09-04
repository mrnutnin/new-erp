<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Support\PurchaseDocumentState;
use DomainException;
use PHPUnit\Framework\TestCase;

class PurchaseDocumentStateTest extends TestCase
{
    public function test_it_approves_draft_and_voids_unposted_documents(): void
    {
        $this->assertSame('APPROVED', PurchaseDocumentState::approve('DRAFT'));
        $this->assertSame('VOID', PurchaseDocumentState::void('DRAFT'));
        $this->assertSame('VOID', PurchaseDocumentState::void('APPROVED'));
        $this->assertSame('POSTED', PurchaseDocumentState::post('APPROVED'));
    }

    public function test_it_rejects_posted_or_invalid_transitions(): void
    {
        $this->expectException(DomainException::class);
        PurchaseDocumentState::void('POSTED');
    }

    public function test_only_approved_document_can_be_posted(): void
    {
        $this->expectException(DomainException::class);
        PurchaseDocumentState::post('DRAFT');
    }
}
