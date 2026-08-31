<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\InventoryJournalLineMapper;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryJournalLineMapperTest extends TestCase
{
    public function test_maps_exact_item_line_by_account_subledger_and_value(): void
    {
        $result = InventoryJournalLineMapper::map(
            ['account_id' => 20], ['id' => 202, 'item_id' => 4, 'uom_id' => 2, 'warehouse_id' => 8, 'business_date' => '2026-08-21', 'direction' => 'IN'],
            ['id' => 303, 'stock_movement_id' => 202, 'warehouse_id' => 8, 'item_id' => 4, 'uom_id' => 2, 'value' => '100.00', 'revision' => 0],
            collect([
                ['id' => 11, 'account_id' => 20, 'subledger_type' => 'ITEM', 'subledger_id' => '9', 'debit' => '100.00', 'credit' => '0.00'],
                ['id' => 12, 'account_id' => 20, 'subledger_type' => 'ITEM', 'subledger_id' => '4', 'debit' => '100.00', 'credit' => '0.00'],
            ]),
        );

        $this->assertSame(12, $result['journal_entry_line_id']);
        $this->assertSame(hash('sha256', '303|12|0'), $result['identity_key']);
    }

    public function test_rejects_duplicate_exact_matches(): void
    {
        $this->expectException(ValidationException::class);
        InventoryJournalLineMapper::map(
            ['account_id' => 20], ['id' => 202, 'item_id' => 4, 'uom_id' => 2, 'warehouse_id' => 8, 'business_date' => '2026-08-21', 'direction' => 'IN'], ['id' => 303, 'stock_movement_id' => 202, 'warehouse_id' => 8, 'item_id' => 4, 'uom_id' => 2, 'value' => '100.00', 'revision' => 0],
            collect([
                ['id' => 12, 'account_id' => 20, 'subledger_type' => 'ITEM', 'subledger_id' => '4', 'debit' => '100.00', 'credit' => '0.00'],
                ['id' => 13, 'account_id' => 20, 'subledger_type' => 'ITEM', 'subledger_id' => '4', 'debit' => '100.00', 'credit' => '0.00'],
            ]),
        );
    }

    public function test_line_token_disambiguates_same_account_item_and_amount(): void
    {
        $result = InventoryJournalLineMapper::map(
            ['id' => 10, 'account_id' => 20],
            ['id' => 202, 'item_id' => 4, 'uom_id' => 2, 'warehouse_id' => 8, 'business_date' => '2026-08-21', 'direction' => 'IN'],
            ['id' => 303, 'stock_movement_id' => 202, 'warehouse_id' => 8, 'item_id' => 4, 'uom_id' => 2, 'value' => '100.00', 'revision' => 0],
            collect([
                ['id' => 12, 'account_id' => 20, 'subledger_type' => 'ITEM', 'subledger_id' => '4', 'debit' => '100.00', 'credit' => '0.00', 'description' => '[purchase-line:11]'],
                ['id' => 13, 'account_id' => 20, 'subledger_type' => 'ITEM', 'subledger_id' => '4', 'debit' => '100.00', 'credit' => '0.00', 'description' => '[purchase-line:10]'],
            ]),
        );

        $this->assertSame(13, $result['journal_entry_line_id']);
    }

    public function test_rejects_amount_or_subledger_mismatch(): void
    {
        $this->expectException(ValidationException::class);
        InventoryJournalLineMapper::map(
            ['account_id' => 20], ['id' => 202, 'item_id' => 4, 'uom_id' => 2, 'warehouse_id' => 8, 'business_date' => '2026-08-21', 'direction' => 'IN'], ['id' => 303, 'stock_movement_id' => 202, 'warehouse_id' => 8, 'item_id' => 4, 'uom_id' => 2, 'value' => '100.00', 'revision' => 0],
            collect([['id' => 12, 'account_id' => 20, 'subledger_type' => 'ITEM', 'subledger_id' => '4', 'debit' => '99.99', 'credit' => '0.00']]),
        );
    }

    public function test_rejects_zero_allocation_before_matching(): void
    {
        $this->expectException(ValidationException::class);
        InventoryJournalLineMapper::map(
            ['account_id' => 20], ['id' => 202, 'item_id' => 4, 'uom_id' => 2, 'warehouse_id' => 8, 'business_date' => '2026-08-21', 'direction' => 'IN'], ['id' => 303, 'stock_movement_id' => 202, 'warehouse_id' => 8, 'item_id' => 4, 'uom_id' => 2, 'value' => '0.00', 'revision' => 0],
            collect([['id' => 12, 'account_id' => 20, 'subledger_type' => 'ITEM', 'subledger_id' => '4', 'debit' => '0.00', 'credit' => '0.00']]),
        );
    }

    public function test_same_input_is_deterministic_for_retry(): void
    {
        $args = [
            ['account_id' => 20], ['id' => 202, 'item_id' => 4, 'uom_id' => 2, 'warehouse_id' => 8, 'business_date' => '2026-08-21', 'direction' => 'IN'], ['id' => 303, 'stock_movement_id' => 202, 'warehouse_id' => 8, 'item_id' => 4, 'uom_id' => 2, 'value' => '100.004', 'revision' => 2],
            [['id' => 12, 'account_id' => 20, 'subledger_type' => 'ITEM', 'subledger_id' => '4', 'debit' => '100.00', 'credit' => '0.00']],
        ];
        $this->assertSame(InventoryJournalLineMapper::map(...$args), InventoryJournalLineMapper::map(...$args));
    }

    public function test_rejects_context_date_or_revision_mismatch(): void
    {
        $this->expectException(ValidationException::class);
        InventoryJournalLineMapper::map(
            ['account_id' => 20],
            ['id' => 202, 'item_id' => 4, 'uom_id' => 2, 'warehouse_id' => 8, 'business_date' => '2026-08-21', 'direction' => 'IN'],
            ['id' => 303, 'stock_movement_id' => 202, 'warehouse_id' => 8, 'item_id' => 4, 'uom_id' => 2, 'value' => '100.00', 'revision' => 0],
            collect([['id' => 12, 'account_id' => 20, 'subledger_type' => 'ITEM', 'subledger_id' => '4', 'debit' => '100.00', 'credit' => '0.00']]),
            ['business_date' => '2026-08-22', 'revision' => 1],
        );
    }
}
