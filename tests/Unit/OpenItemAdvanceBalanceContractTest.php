<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class OpenItemAdvanceBalanceContractTest extends TestCase
{
    public function test_advance_applications_reduce_shared_open_item_balance(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Finance/Services/OpenItemService.php');
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Finance/Controllers/OpenItemController.php');

        $this->assertStringContainsString('finance_advance_deposit_applications', $source);
        $this->assertStringContainsString('JournalBalance::add($allocated, $advanceApplied)', $source);
        $this->assertStringContainsString("'allocation_date' => \$application->application_date", $source);
        $this->assertStringContainsString('finance_advance_deposit_applications', $controller);
    }
}
