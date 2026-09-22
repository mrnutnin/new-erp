<?php

namespace Tests\Unit;

use App\Modules\Accounting\Support\PostingEvent;
use App\Modules\Wms\Support\ProductionScrapReceiptPostingContract;
use App\Modules\Wms\Support\ProductionScrapReceiptReversalContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ProductionScrapReceiptContractTest extends TestCase
{
    public function test_posting_plan_receives_valued_scrap_from_wip_deterministically(): void
    {
        $document = $this->document();
        $first = ProductionScrapReceiptPostingContract::plan($document);
        $second = ProductionScrapReceiptPostingContract::plan($document);

        self::assertSame($first, $second);
        self::assertSame('production.scrap_receipt', $first['event_code']);
        self::assertSame('WMS_PRODUCTION_SCRAP_RECEIPT', $first['source_type']);
        self::assertSame(['SCRAP_INVENTORY', 'WIP'], $first['journal_roles']);
        self::assertSame(['debit_role' => 'SCRAP_INVENTORY', 'credit_role' => 'WIP', 'amount' => '50.00000000'], $first['journal_intent']);
        self::assertSame('IN', $first['movement_intents'][0]['direction']);
        self::assertSame('FINAL', $first['allocation_intents'][0]['cost_status']);
        self::assertSame('ORIGINAL_JOURNAL', PostingEvent::contract('production.scrap_receipt')['reversal']);
    }

    public function test_shared_wms_contract_also_accepts_manual_production_issue_source(): void
    {
        $document = $this->document();
        unset($document['production_order_id']);
        $document['source_issue_id'] = 88;

        $plan = ProductionScrapReceiptPostingContract::plan($document);

        self::assertNull($plan['production_order_id']);
        self::assertSame(88, $plan['source_issue_id']);
    }

    public function test_recovery_value_cannot_exceed_available_wip(): void
    {
        $document = $this->document();
        $document['available_wip_value'] = '49';

        $this->expectException(ValidationException::class);
        ProductionScrapReceiptPostingContract::plan($document);
    }

    public function test_reversal_plan_keeps_original_stock_cost_and_journal_links(): void
    {
        $document = $this->document();
        $document['status'] = 'POSTED';
        $document['journal_entry_id'] = 901;
        $document['reversal_revision'] = 0;
        $document['lines'] = [
            ['id' => 11, 'status' => 'POSTED', 'stock_movement_id' => 101, 'cost_allocation_id' => 201],
            ['id' => 12, 'status' => 'POSTED', 'stock_movement_id' => 102, 'cost_allocation_id' => 202],
        ];

        $plan = ProductionScrapReceiptReversalContract::plan($document, '2026-09-18', 'รับเศษผิดรายการ ทดสอบ');

        self::assertSame(901, $plan['original_journal_entry_id']);
        self::assertSame(101, $plan['movement_reversals'][0]['source_stock_movement_id']);
        self::assertSame(201, $plan['movement_reversals'][0]['source_cost_allocation_id']);
        self::assertSame('ORIGINAL_JOURNAL', $plan['journal_reversal']);
        self::assertSame('reversal:production-scrap-receipt:7:revision:1', $plan['source_id']);
    }

    public function test_installer_seeds_scrap_mapping_as_versioned_default(): void
    {
        $seeder = file_get_contents(base_path('database/seeders/ProductionFinishedReceiptAccountMappingSeeder.php'));
        $migration = file_get_contents(base_path('database/migrations/2026_09_18_030000_seed_production_scrap_receipt_account_mappings.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/SystemDefaultOrchestrator.php'));

        self::assertStringContainsString("'production.scrap_receipt'", $seeder);
        self::assertStringContainsString("'SCRAP_INVENTORY' => '14500'", $seeder);
        self::assertStringContainsString("['event_code' => 'production.scrap_receipt'", $migration);
        self::assertStringContainsString("'wms.production_finished_receipt_mapping' => '1.1'", $installer);
    }

    private function document(): array
    {
        return [
            'id' => 7,
            'production_order_id' => 8,
            'warehouse_id' => 9,
            'document_number' => 'PSR-0001',
            'document_date' => '2026-09-18',
            'document_context' => 'PRODUCTION_SCRAP_RECEIPT',
            'status' => 'APPROVED',
            'reason' => 'รับเศษโลหะกลับเข้าคลัง',
            'available_wip_value' => '100',
            'lines' => [
                ['id' => 11, 'item_id' => 21, 'uom_id' => 31, 'quantity' => '2', 'value' => '20', 'source_material_line_id' => 41],
                ['id' => 12, 'item_id' => 22, 'uom_id' => 32, 'quantity' => '3', 'value' => '30'],
            ],
        ];
    }
}
