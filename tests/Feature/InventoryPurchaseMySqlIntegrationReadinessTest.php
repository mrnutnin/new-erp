<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Wms\Models\CostRevaluationBatch;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Services\GoodsReceiptInventoryService;
use App\Modules\Wms\Services\InventoryPurchaseProductionAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\InventoryPurchaseIntegrationFixture;
use Tests\TestCase;

/**
 * Opt-in local-MySQL contract placeholder. The normal PHPUnit suite uses an
 * in-memory SQLite database and must never mutate the developer's ERP data.
 * Run this only in a dedicated local integration process after its preflight
 * fixture has been approved; it is intentionally skipped by default.
 */
final class InventoryPurchaseMySqlIntegrationReadinessTest extends TestCase
{
    public function test_local_mysql_inventory_purchase_posting_rolls_back_the_full_chain(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('1', env('ERP_RUN_MYSQL_INTEGRATION'));
        InventoryPurchaseIntegrationFixture::assertReady();
        $actor = User::query()->first();
        if (! $actor) {
            $this->markTestSkipped('ต้องมี User fixture ใน dedicated MySQL DB');
        }
        $before = [
            'journals' => DB::table('journal_entries')->count(),
            'movements' => DB::table('wms_stock_movements')->count(),
            'allocations' => DB::table('wms_cost_allocations')->count(),
            'links' => DB::table('wms_cost_allocation_journal_lines')->count(),
        ];
        $previous = config('erp.inventory.purchase_posting_enabled');
        $previousAutoTrigger = config('erp.inventory.revaluation_auto_trigger_enabled');
        DB::beginTransaction();
        try {
            $document = InventoryPurchaseIntegrationFixture::createApprovedPurchase($actor);
            $warehouse = Warehouse::query()->findOrFail($document->warehouse_id);
            $receipt = $document->lines->firstOrFail()->receiptAllocations->firstOrFail()->goodsReceiptLine->goodsReceipt;
            try {
                app(GoodsReceiptInventoryService::class)->postApprovedWithinTransaction($receipt, $actor->id);
                $this->fail('Goods Receipt must not own purchased stock');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('stock_owner', $exception->errors());
            }
            $this->assertSame(0, DB::table('wms_stock_movements')->where('source_type', 'GOODS_RECEIPT')->where('source_id', (string) $receipt->id)->count());
            $baselineBlockers = InventoryPurchaseIntegrationFixture::baselineBlockers((int) $document->warehouse_id);
            if ($baselineBlockers !== []) {
                $this->markTestSkipped('Baseline reconciliation ของ Warehouse fixture ยังไม่สะอาด: '.implode('; ', $baselineBlockers));
            }
            config(['erp.inventory.purchase_posting_enabled' => true]);
            config(['erp.inventory.revaluation_auto_trigger_enabled' => true]);
            $posted = app(InventoryPurchaseProductionAdapter::class)->post($document, $warehouse, $actor, null, true);
            $this->assertSame('POSTED', $posted->status);
            $this->assertNotNull($posted->journal_entry_id);
            $this->assertSame(0.0, (float) DB::table('journal_entry_lines')->where('journal_entry_id', $posted->journal_entry_id)->sum(DB::raw('debit - credit')));
            $movementIds = DB::table('wms_stock_movements')->where('source_type', 'PURCHASING')->where('source_id', (string) $document->id)->pluck('id');
            $this->assertGreaterThan(0, $movementIds->count());
            $this->assertSame(0, DB::table('wms_stock_movements')->where('source_type', 'GOODS_RECEIPT')->where('source_id', (string) $receipt->id)->count());
            $allocationIds = DB::table('wms_cost_allocations')->whereIn('stock_movement_id', $movementIds)->where('status', '!=', 'REVERSED')->pluck('id');
            $this->assertGreaterThan(0, $allocationIds->count());
            $this->assertSame(0, DB::table('wms_cost_allocations')->whereIn('id', $allocationIds)->where(fn ($q) => $q->where('cost_status', '!=', 'FINAL')->orWhere('status', '!=', 'POSTED'))->count());
            $this->assertSame($allocationIds->count(), DB::table('wms_cost_allocation_journal_lines')->whereIn('allocation_id', $allocationIds)->count());
            $this->assertGreaterThan($before['journals'], DB::table('journal_entries')->count());
            $this->assertGreaterThan($before['movements'], DB::table('wms_stock_movements')->count());
            $this->assertGreaterThan($before['allocations'], DB::table('wms_cost_allocations')->count());
            $this->assertGreaterThan($before['links'], DB::table('wms_cost_allocation_journal_lines')->count());
            $this->assertTrue(CostRevaluationBatch::query()->where('source_document_type', 'PURCHASE_DOCUMENT')->where('source_document_id', $posted->id)->exists());
        } finally {
            DB::rollBack();
            config(['erp.inventory.purchase_posting_enabled' => $previous]);
            config(['erp.inventory.revaluation_auto_trigger_enabled' => $previousAutoTrigger]);
        }

        $this->assertSame($before, [
            'journals' => DB::table('journal_entries')->count(),
            'movements' => DB::table('wms_stock_movements')->count(),
            'allocations' => DB::table('wms_cost_allocations')->count(),
            'links' => DB::table('wms_cost_allocation_journal_lines')->count(),
        ]);
    }

    public function test_purchase_invoice_preflight_blocks_a_legacy_goods_receipt_stock_owner(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->firstOrFail();
        DB::beginTransaction();
        try {
            $document = InventoryPurchaseIntegrationFixture::createApprovedPurchase($actor);
            $receiptLine = $document->lines->firstOrFail()->receiptAllocations->firstOrFail()->goodsReceiptLine;
            StockMovement::query()->create([
                'warehouse_id' => $document->warehouse_id,
                'item_id' => $receiptLine->item_id,
                'uom_id' => $receiptLine->stock_uom_id,
                'movement_type' => 'RECEIPT',
                'direction' => 'IN',
                'status' => 'POSTED',
                'quantity' => $receiptLine->stock_quantity,
                'base_quantity' => $receiptLine->stock_quantity,
                'business_date' => $receiptLine->goodsReceipt->business_date,
                'source_type' => 'GOODS_RECEIPT',
                'source_id' => (string) $receiptLine->goods_receipt_id,
                'source_reference' => $receiptLine->goodsReceipt->receipt_number,
                'idempotency_key' => "test:legacy-goods-receipt:{$receiptLine->id}",
                'metadata' => ['goods_receipt_line_id' => $receiptLine->id],
                'posted_at' => now(),
                'created_by' => $actor->id,
            ]);

            $result = app(InventoryPurchaseProductionAdapter::class)->preflight($document, null, true);

            $this->assertFalse($result['production_ready']);
            $this->assertContains('duplicate_purchase_stock_owner', $result['blockers']);
            $this->assertArrayHasKey('stock_owner', $result['errors']);
            $this->assertSame(0, DB::table('wms_stock_movements')->where('source_type', 'PURCHASING')->where('source_id', (string) $document->id)->count());
        } finally {
            DB::rollBack();
        }
    }
}
