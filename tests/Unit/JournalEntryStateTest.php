<?php

namespace Tests\Unit;

use App\Modules\Accounting\Support\JournalEntryState;
use DomainException;
use PHPUnit\Framework\TestCase;

class JournalEntryStateTest extends TestCase
{
    public function test_it_enforces_manual_journal_lifecycle(): void
    {
        $this->assertSame('VALIDATED', JournalEntryState::validate('DRAFT'));
        $this->assertSame('POSTED', JournalEntryState::post('VALIDATED'));
        $this->assertSame('REVERSED', JournalEntryState::reverse('POSTED'));
    }

    public function test_it_rejects_skipping_or_repeating_a_transition(): void
    {
        $this->expectException(DomainException::class);
        JournalEntryState::post('DRAFT');
    }
}
