<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PettyCashClearingPolicyContractTest extends TestCase
{
    public function test_variance_policy_has_dedicated_roles_and_repeatable_mock_data(): void
    {
        $event = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Accounting/Support/PostingEvent.php');
        self::assertStringContainsString('petty_cash_clearing', $event);
        self::assertStringContainsString('PETTY_CASH_VARIANCE_GAIN', $event);
        self::assertStringContainsString('PETTY_CASH_VARIANCE_LOSS', $event);
        self::assertFileExists(dirname(__DIR__, 2).'/database/seeders/FinancePettyCashMockSeeder.php');
    }
}
