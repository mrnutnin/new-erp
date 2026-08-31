<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleAdvanceDepositCancellationContractTest extends TestCase
{
    public function test_full_hs_cancellation_restores_advance_deposit_without_a_second_journal_reversal(): void
    {
        $base = dirname(__DIR__, 2);
        $cancellation = file_get_contents($base.'/app/Modules/Pos/Services/PhysicalSaleCancellationService.php');
        $applications = file_get_contents($base.'/app/Modules/Finance/Services/AdvanceDepositApplicationService.php');

        self::assertStringContainsString('reversePhysicalSaleApplications($sale, $reversal, $date, $reason, $actor)', $cancellation);
        self::assertStringContainsString('reversePhysicalSaleApplications', $applications);
        self::assertStringContainsString("'reversal_journal_entry_id' => \$reversalJournal->id", $applications);
        self::assertStringContainsString("'status' => AdvanceDepositContract::status", $applications);
    }
}
