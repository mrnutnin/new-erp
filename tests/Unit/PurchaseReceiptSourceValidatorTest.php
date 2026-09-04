<?php

namespace Tests\Unit;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\PurchaseReceiptSourceValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseReceiptSourceValidatorTest extends TestCase
{
    public function test_it_accepts_a_posted_inventory_purchase_journal_with_explicit_item_uom_linkage(): void
    {
        [$document, $journal, $movement, $allocation] = $this->fixtures();

        (new PurchaseReceiptSourceValidator)->assertReady($document, $journal, $movement, $allocation, [
            'item_id' => 4, 'uom_id' => 2,
        ]);

        $this->assertTrue(true);
    }

    public function test_it_blocks_when_purchase_item_uom_linkage_is_missing(): void
    {
        [$document, $journal, $movement, $allocation] = $this->fixtures();

        $this->expectException(ValidationException::class);
        (new PurchaseReceiptSourceValidator)->assertReady($document, $journal, $movement, $allocation);
    }

    public function test_it_blocks_non_inventory_purchase_journal_and_duplicate_allocation(): void
    {
        [$document, $journal, $movement, $allocation] = $this->fixtures();
        $journal->source_event = 'supplier_invoice.expense';

        $this->expectException(ValidationException::class);
        (new PurchaseReceiptSourceValidator)->assertReady($document, $journal, $movement, $allocation, ['item_id' => 4, 'uom_id' => 2]);
    }

    public function test_it_blocks_an_allocation_that_already_has_a_journal(): void
    {
        [$document, $journal, $movement, $allocation] = $this->fixtures();
        $allocation->journal_entry_id = 51;

        $this->expectException(ValidationException::class);
        (new PurchaseReceiptSourceValidator)->assertReady($document, $journal, $movement, $allocation, ['item_id' => 4, 'uom_id' => 2]);
    }

    private function fixtures(): array
    {
        $document = (new PurchaseDocument([
            'warehouse_id' => 7, 'document_type' => 'INVOICE', 'document_number' => 'PI-009',
            'document_date' => '2026-08-20', 'status' => 'POSTED', 'journal_entry_id' => 51,
        ]))->forceFill(['id' => 9]);
        $journal = (new JournalEntry([
            'warehouse_id' => 7, 'status' => 'POSTED', 'source_type' => 'PURCHASING',
            'source_event' => 'supplier_invoice.inventory', 'source_id' => '9', 'source_reference' => 'PI-009',
        ]))->forceFill(['id' => 51]);
        $movement = (new StockMovement([
            'warehouse_id' => 7, 'item_id' => 4, 'uom_id' => 2, 'movement_type' => 'RECEIPT', 'direction' => 'IN',
            'business_date' => '2026-08-21', 'source_type' => 'PURCHASING', 'source_id' => '9', 'source_reference' => 'PI-009',
        ]))->forceFill(['id' => 12]);
        $allocation = new CostAllocation([
            'stock_movement_id' => 12, 'warehouse_id' => 7, 'item_id' => 4, 'uom_id' => 2,
            'business_date' => '2026-08-21', 'status' => 'PENDING', 'journal_entry_id' => null,
        ]);

        return [$document, $journal, $movement, $allocation];
    }
}
