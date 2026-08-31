<?php

namespace Tests\Unit;

use Tests\TestCase;

class AdvanceDepositSettlementSourceGateTest extends TestCase
{
    public function test_advance_source_requires_exact_posted_journal_identity_and_bank_balance(): void
    {
        $source = file_get_contents(base_path('app/Modules/Finance/Services/AdvanceDepositSettlementService.php'));

        $this->assertStringContainsString('if (! $source->journal_entry_id)', $source);
        $this->assertStringContainsString("->where('status', 'POSTED')->where('warehouse_id', \$warehouse->id)", $source);
        $this->assertStringContainsString("\$journal->source_type !== 'FINANCE'", $source);
        $this->assertStringContainsString('$journal->source_event !== $event', $source);
        $this->assertStringContainsString("\$event = \$partyType === 'CUSTOMER' ? 'customer_advance' : 'supplier_payment'", $source);
        $this->assertStringContainsString('$journal->source_id !== (string) $source->id', $source);
        $this->assertStringContainsString('$journal->source_reference !== $source->document_number', $source);
        $this->assertStringContainsString("\$totals['debit'] !== \$totals['credit']", $source);
        $this->assertStringContainsString("\$totals['debit'] !== \$amountCents", $source);
    }

    public function test_production_entry_path_posts_only_approved_unapplied_settlements_and_keeps_direction_guard(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Finance/Controllers/SettlementController.php'));
        $service = file_get_contents(base_path('app/Modules/Finance/Services/AdvanceDepositSettlementService.php'));

        $this->assertStringContainsString("in_array(\$settlement->status, ['APPROVED', 'POSTED'], true)", $controller);
        $this->assertStringContainsString("\$settlement->status === 'APPROVED'", $controller);
        $this->assertStringContainsString('postSettlementAsAdvance', $controller);
        $this->assertStringContainsString("\$source->status !== 'APPROVED' || \$source->allocationIntents()->exists()", $service);
        $this->assertStringContainsString("\$partyType = \$source->document_type === 'RECEIPT' ? 'CUSTOMER' : 'SUPPLIER'", $service);
        $this->assertStringContainsString('AdvanceDepositContract::assertPartyDirection', $service);
    }
}
