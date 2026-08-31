<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\SalesDocumentState;
use DomainException;
use PHPUnit\Framework\TestCase;

class SalesDocumentStateTest extends TestCase
{
    public function test_draft_can_be_approved_and_draft_or_approved_can_be_voided(): void
    {
        $this->assertSame('APPROVED', SalesDocumentState::approve('DRAFT'));
        $this->assertSame('VOID', SalesDocumentState::void('DRAFT'));
        $this->assertSame('VOID', SalesDocumentState::void('APPROVED'));
        $this->assertSame('POSTED', SalesDocumentState::post('APPROVED'));
    }

    public function test_posted_document_cannot_be_voided_in_this_wave(): void
    {
        $this->expectException(DomainException::class);
        SalesDocumentState::void('POSTED');
    }

    public function test_only_approved_document_can_be_posted(): void
    {
        $this->expectException(DomainException::class);
        SalesDocumentState::post('DRAFT');
    }
}
