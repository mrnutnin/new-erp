<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SettlementMixedAdvanceContractTest extends TestCase
{
    public function test_receipt_surplus_creates_advance_and_blocks_reversal_after_application(): void
    {
        $root = dirname(__DIR__, 2);
        $posting = file_get_contents($root.'/app/Modules/Finance/Services/SettlementPostingService.php');
        $reversal = file_get_contents($root.'/app/Modules/Finance/Services/SettlementReversalService.php');

        $this->assertStringContainsString('$advanceAmount !== \'0.00\' && $settlement->document_type !== \'RECEIPT\'', $posting);
        $this->assertStringContainsString("'instrument_type' => 'ADVANCE'", $posting);
        $this->assertStringContainsString('$advance->applications()->whereNull(\'reversed_at\')->exists()', $reversal);
    }
}
