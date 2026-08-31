<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdvanceDepositRefundContractTest extends TestCase
{
    public function test_refund_reverses_only_unused_ai_to_every_original_tender_account(): void
    {
        $service = file_get_contents(__DIR__.'/../../app/Modules/Pos/Services/AdvanceDepositRefundService.php');
        self::assertStringContainsString('reverseWithinTransaction', $service);
        self::assertStringContainsString("['POSTED', 'PARTIAL']", $service);
        self::assertStringContainsString("applications()->whereNull('reversed_at')->exists()", $service);
        self::assertStringContainsString('tenders()->lockForUpdate()->get()', $service);
        self::assertStringContainsString("'refund_bank_account_id' => \$tenders->count() === 1 ? \$tenders->first()->bank_account_id : null", $service);
        self::assertStringNotContainsString("pluck('bank_account_id')->unique()->count() !== 1", $service);
        self::assertStringContainsString("'status' => 'VOID'", $service);
        self::assertStringContainsString('pos.advance-deposit.refunded', $service);
    }
}
