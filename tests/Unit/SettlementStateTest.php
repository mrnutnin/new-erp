<?php

namespace Tests\Unit;

use App\Modules\Finance\Support\SettlementState;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettlementStateTest extends TestCase
{
    public function test_it_approves_a_draft_and_voids_an_unposted_document(): void
    {
        $this->assertSame('APPROVED', SettlementState::approve('DRAFT'));
        $this->assertSame('VOID', SettlementState::void('DRAFT'));
        $this->assertSame('VOID', SettlementState::void('APPROVED'));
    }

    public function test_only_approved_settlement_can_be_posted(): void
    {
        $this->assertSame('POSTED', SettlementState::post('APPROVED'));

        $this->expectException(DomainException::class);
        SettlementState::post('DRAFT');
    }

    public function test_only_posted_settlement_can_be_reversed(): void
    {
        $this->assertSame('VOID', SettlementState::reverse('POSTED'));

        $this->expectException(DomainException::class);
        SettlementState::reverse('APPROVED');
    }

    #[DataProvider('invalidTransitions')]
    public function test_it_rejects_invalid_or_posted_transitions(string $transition, string $status): void
    {
        $this->expectException(DomainException::class);
        SettlementState::{$transition}($status);
    }

    public static function invalidTransitions(): array
    {
        return [
            ['approve', 'APPROVED'],
            ['approve', 'POSTED'],
            ['void', 'POSTED'],
            ['void', 'VOID'],
        ];
    }
}
