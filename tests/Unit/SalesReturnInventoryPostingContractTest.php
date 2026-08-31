<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesReturnInventoryPostingContractTest extends TestCase
{
    public function test_it_uses_locked_source_quantity_trusted_return_cost_and_one_atomic_journal(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Services/SalesReturnInventoryPostingService.php');
        self::assertStringContainsString('postWithinTransaction', $source);
        self::assertStringContainsString('lockForUpdate()', $source);
        self::assertStringContainsString('assertReturnQuantity', $source);
        self::assertStringContainsString("->where('status', 'POSTED')", $source);
        self::assertStringContainsString('partialLineage', $source);
        self::assertStringContainsString("'parent_allocation_id' => \$source->id", $source);
        self::assertStringContainsString("'source_cost_allocation_id' => \$row['sourceAllocation']->id", $source);
        self::assertStringContainsString("'direction' => 'IN'", $source);
        self::assertStringContainsString("'unit_cost_trusted' => true", $source);
        self::assertStringContainsString('$this->journals->postWithinTransaction(', $source);
        self::assertStringContainsString('$this->allocations->linkJournalLineWithinTransaction(', $source);
    }
}
