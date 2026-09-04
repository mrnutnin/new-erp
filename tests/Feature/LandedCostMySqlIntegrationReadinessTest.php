<?php

namespace Tests\Feature;

use App\Modules\Purchasing\Services\LandedCostService;
use App\Modules\Purchasing\Services\LandedCostPostingService;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostRecalculationRequest;
use App\Modules\Wms\Services\GoodsReceiptInventoryService;
use App\Modules\Accounting\Models\Account;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryPurchaseIntegrationFixture;
use Tests\TestCase;

final class LandedCostMySqlIntegrationReadinessTest extends TestCase
{
    public function test_multiple_posted_receipts_allocate_landed_cost_per_receipt(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = \App\Models\User::query()->findOrFail(1);
        $expense = Account::query()->whereHas('type', fn ($query) => $query->where('code', 'EXPENSE'))->where('is_active', true)->where('is_postable', true)->firstOrFail();
        DB::beginTransaction();
        try {
            $chain = InventoryPurchaseIntegrationFixture::createProcurementChain($actor);
            $firstLine = $chain['receipt']->lines()->firstOrFail();
            $firstLine->update([
                'purchase_quantity' => 5, 'stock_quantity' => 50, 'total_cost' => 500, 'stock_unit_cost' => 10,
                'conversion_snapshot' => [
                    'purchase_uom_id' => $firstLine->purchase_uom_id, 'stock_uom_id' => $firstLine->stock_uom_id,
                    'factor' => '10', 'business_date' => $chain['receipt']->business_date->format('Y-m-d'),
                ],
            ]);
            $second = GoodsReceipt::query()->create([
                'warehouse_id' => $chain['foundation']['warehouse']->id, 'purchase_order_id' => $chain['order']->id,
                'supplier_id' => $chain['foundation']['supplier']->id, 'receipt_number' => 'GR-INT-'.strtoupper(bin2hex(random_bytes(5))),
                'idempotency_key' => 'integration:'.strtoupper(bin2hex(random_bytes(5))), 'business_date' => $chain['receipt']->business_date,
                'status' => 'APPROVED', 'description' => 'Second dedicated integration receipt', 'approved_by' => $actor->id,
                'approved_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $second->lines()->create([
                'purchase_order_line_id' => $chain['order']->lines()->firstOrFail()->id, 'item_id' => $chain['foundation']['item']->id,
                'purchase_uom_id' => $chain['foundation']['purchaseUom']->id, 'stock_uom_id' => $chain['foundation']['stockUom']->id,
                'purchase_quantity' => 5, 'factor' => 10, 'stock_quantity' => 50, 'total_cost' => 500, 'stock_unit_cost' => 10,
                'rounding_delta' => 0, 'conversion_snapshot' => [
                    'purchase_uom_id' => $chain['foundation']['purchaseUom']->id, 'stock_uom_id' => $chain['foundation']['stockUom']->id,
                    'factor' => '10', 'business_date' => $chain['receipt']->business_date->format('Y-m-d'),
                ],
            ]);

            $inventory = app(GoodsReceiptInventoryService::class);
            $inventory->postApprovedWithinTransaction($chain['receipt']->fresh('lines'), $actor->id);
            $inventory->postApprovedWithinTransaction($second->fresh('lines'), $actor->id);

            $document = app(LandedCostService::class)->createDraft([
                'warehouse_id' => $chain['foundation']['warehouse']->id, 'document_number' => 'LC-INT-'.strtoupper(bin2hex(random_bytes(5))),
                'business_date' => $chain['receipt']->business_date->format('Y-m-d'), 'allocation_basis' => 'VALUE',
                'receipt_ids' => [$chain['receipt']->id, $second->id],
                'lines' => [['account_id' => $expense->id, 'amount' => '100.00', 'description' => 'Multi-receipt freight']],
                'idempotency_key' => 'landed-cost-multi-integration:'.bin2hex(random_bytes(8)),
            ], $actor);

            self::assertCount(2, $document->receipts);
            self::assertCount(2, $document->allocations);
            self::assertSame(['50.00000000', '50.00000000'], $document->allocations->sortBy('goods_receipt_line_id')->pluck('allocated_amount')->values()->all());
            self::assertSame(['50.00000000', '50.00000000'], $document->receipts->sortBy('goods_receipt_id')->pluck('allocated_amount')->values()->all());
        } finally {
            DB::rollBack();
        }
    }

    public function test_goods_receipt_to_landed_cost_draft_and_lifecycle_are_atomic(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = \App\Models\User::query()->findOrFail(1);
        $expense = Account::query()->whereHas('type', fn ($query) => $query->where('code', 'EXPENSE'))->where('is_active', true)->where('is_postable', true)->firstOrFail();
        DB::beginTransaction();
        try {
            $chain = InventoryPurchaseIntegrationFixture::createProcurementChain($actor);
            $receiptLine = $chain['receipt']->lines()->firstOrFail();
            $receiptLine->update(['conversion_snapshot' => [
                'purchase_uom_id' => $receiptLine->purchase_uom_id,
                'stock_uom_id' => $receiptLine->stock_uom_id,
                'factor' => (string) $receiptLine->factor,
                'business_date' => $chain['receipt']->business_date->format('Y-m-d'),
            ]]);
            $movements = app(GoodsReceiptInventoryService::class)->postApprovedWithinTransaction($chain['receipt'], $actor->id);
            self::assertCount(1, $movements);

            $document = app(LandedCostService::class)->createDraft([
                'warehouse_id' => $chain['foundation']['warehouse']->id,
                'document_number' => 'LC-INT-'.strtoupper(bin2hex(random_bytes(5))),
                'business_date' => $chain['receipt']->business_date->format('Y-m-d'),
                'allocation_basis' => 'VALUE',
                'receipt_ids' => [$chain['receipt']->id],
                'lines' => [['account_id' => $expense->id, 'amount' => '37.50', 'description' => 'Integration freight']],
                'idempotency_key' => 'landed-cost-integration:'.bin2hex(random_bytes(8)),
            ], $actor);

            self::assertSame('DRAFT', $document->status);
            self::assertSame('37.50000000', (string) $document->total_amount);
            self::assertCount(1, $document->receipts);
            self::assertCount(1, $document->allocations);
            self::assertSame('37.50000000', (string) $document->allocations->first()->allocated_amount);

            $document = app(LandedCostService::class)->submit($document, $actor);
            self::assertSame('SUBMITTED', $document->status);
            $document = app(LandedCostService::class)->approve($document, $actor);
            self::assertSame('APPROVED', $document->status);

            $posted = app(LandedCostPostingService::class)->postApproved($document, ['period_open' => true, 'reconciliation_ready' => true], $actor);
            self::assertSame('POSTED', $posted->status);
            $landedAllocation = $posted->allocations()->with('wmsCostAllocation')->sole();
            self::assertSame('POSTED', $landedAllocation->status);
            self::assertNotNull($landedAllocation->wms_cost_allocation_id);
            self::assertSame('RECOST', $landedAllocation->wmsCostAllocation->allocation_type);
            self::assertSame('POSTED', $landedAllocation->wmsCostAllocation->status);
            self::assertNotNull($landedAllocation->wmsCostAllocation->journal_entry_id);
            self::assertTrue(CostRecalculationRequest::query()->where('idempotency_key', "landed-cost:{$posted->id}:movement:{$movements[0]->id}")->exists());

            $retry = app(LandedCostPostingService::class)->postApproved($posted, ['period_open' => true, 'reconciliation_ready' => true], $actor);
            self::assertSame('POSTED', $retry->status);
            self::assertSame(1, $posted->allocations()->count());
            self::assertSame(1, CostAllocation::query()->where('allocation_type', 'RECOST')->where('parent_allocation_id', $landedAllocation->wmsCostAllocation->parent_allocation_id)->count());
        } finally {
            DB::rollBack();
        }
    }
}
