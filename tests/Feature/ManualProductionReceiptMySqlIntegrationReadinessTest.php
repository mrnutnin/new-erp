<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Services\ManualProductionReceiptPostingService;
use App\Modules\Wms\Services\ProductionFinishedReceiptReversalService;
use App\Modules\Wms\Services\IssueReturnService;
use App\Modules\Wms\Services\StockMovementService;
use Brick\Math\BigDecimal;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Rollback-only proof for Manual Finished Receipt Gate C on local MySQL. */
final class ManualProductionReceiptMySqlIntegrationReadinessTest extends TestCase
{
    public function test_fifo_multi_layer_material_issue_to_finished_receipt_flow(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->orderBy('id')->first();
        $baseWarehouse = Warehouse::query()->where('is_active', true)->with('branch')->orderBy('id')->first();
        $categoryId = DB::table('wms_item_categories')->orderBy('id')->value('id');
        $uomId = DB::table('wms_uoms')->where('is_active', true)->orderBy('id')->value('id');
        $settingsBefore = DB::table('company_settings')->where('id', 1)->first();
        if (! $actor || ! $baseWarehouse || ! $categoryId || ! $uomId || ! $settingsBefore) {
            $this->markTestSkipped('ต้องมี User, Warehouse, Category, UOM และ Company Setting');
        }

        DB::beginTransaction();
        try {
            $stamp = substr((string) hrtime(true), -10);
            $warehouse = Warehouse::query()->create(['branch_id' => $baseWarehouse->branch_id, 'code' => 'PR-FIFO-'.$stamp, 'name' => 'Production FIFO Test', 'is_active' => true]);
            $item = Item::query()->create([
                'category_id' => $categoryId, 'code' => 'PR-FIFO-'.$stamp, 'name' => 'Production FIFO Test Item',
                'item_type' => 'GOODS', 'base_uom' => 'TEST', 'base_uom_id' => $uomId,
                'is_stock_item' => true, 'is_active' => true, 'created_by' => $actor->id,
            ]);
            DB::table('company_settings')->where('id', 1)->update([
                'inventory_costing_method' => 'FIFO',
                'allow_negative_stock' => false,
                'settings_version' => ((int) $settingsBefore->settings_version) + 1,
            ]);
            app(GlobalSettings::class)->forget((int) $settingsBefore->settings_version);

            $movementService = app(StockMovementService::class);
            foreach ([['6', '10', 'receipt-a'], ['4', '20', 'receipt-b']] as [$quantity, $unitCost, $source]) {
                $movement = $movementService->recordIntent([
                    'warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'uom_id' => $uomId,
                    'movement_type' => 'RECEIPT', 'direction' => 'IN', 'quantity' => $quantity, 'base_quantity' => $quantity,
                    'business_date' => today()->toDateString(), 'source_type' => 'TEST', 'source_id' => $source,
                    'source_reference' => $source, 'idempotency_key' => 'production-fifo:'.$source,
                    'metadata' => ['unit_cost' => $unitCost, 'unit_cost_trusted' => true], 'created_by' => $actor->id,
                ]);
                $movementService->post($movement);
            }

            $request = Request::create('/wms/production/material-issues', 'POST');
            $request->attributes->set('selectedWarehouse', $warehouse);
            $audit = app(AuditLogger::class);
            $issueService = app(IssueReturnService::class);
            $issue = $issueService->createIssue([
                'document_date' => today()->toDateString(), 'reason' => 'FIFO integration material issue', 'issue_type' => 'PRODUCTION',
                'lines' => [['item_id' => $item->id, 'uom_id' => $uomId, 'quantity' => '10.00000000']],
            ], $warehouse, $actor, app(DocumentSequenceService::class), $audit, $request);
            $issue = $issueService->approve($issue, $actor, $audit, $request);
            $issue = $issueService->post($issue, $warehouse, $actor, $audit, $request)->load('lines.movement');
            $sourceLine = $issue->lines->first();
            $sourceAllocations = CostAllocation::query()->where('stock_movement_id', $sourceLine->stock_movement_id)->where('status', '!=', 'REVERSED')->orderBy('id')->get();
            self::assertSame(['6.00000000', '4.00000000'], $sourceAllocations->pluck('quantity')->map(fn ($value): string => (string) $value)->all());
            $sourceTotal = $sourceAllocations->reduce(fn (BigDecimal $sum, CostAllocation $allocation): BigDecimal => $sum->plus(BigDecimal::of((string) $allocation->value)->abs()), BigDecimal::zero());

            $document = InventoryAdjustmentDocument::query()->create([
                'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id, 'document_number' => 'PR-FIFO-'.$stamp,
                'document_date' => today()->toDateString(), 'direction' => 'GAIN', 'document_context' => 'PRODUCTION_RECEIPT',
                'source_issue_id' => $issue->id, 'status' => 'APPROVED', 'reason' => 'FIFO integration finished receipt',
                'idempotency_key' => 'production-receipt-fifo-'.$stamp, 'created_by' => $actor->id, 'approved_by' => $actor->id,
            ]);
            $document->lines()->create([
                'warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'uom_id' => $uomId, 'line_number' => 1,
                'direction' => 'GAIN', 'status' => 'APPROVED', 'quantity' => 1, 'value' => $sourceTotal->__toString(),
                'business_date' => today()->toDateString(), 'reason' => 'FIFO integration finished receipt',
                'idempotency_key' => 'production-receipt-line-fifo-'.$stamp, 'created_by' => $actor->id, 'approved_by' => $actor->id,
            ]);
            $document = $document->fresh('lines');
            $preflight = app(ManualProductionReceiptPostingService::class)->preflight($document->toArray());
            if (! $preflight['ready']) {
                $this->markTestSkipped('Production Receipt FIFO ยังไม่พร้อม: '.collect($preflight['blockers'])->pluck('message')->implode(' '));
            }
            $posted = app(ManualProductionReceiptPostingService::class)->post($document, $warehouse, $actor, $request);
            self::assertSame('POSTED', $posted->status);
            self::assertSame($sourceTotal->toScale(8)->__toString(), $posted->lines->first()->value);
        } finally {
            DB::rollBack();
            DB::table('company_settings')->where('id', 1)->update((array) $settingsBefore);
            app(GlobalSettings::class)->forget((int) $settingsBefore->settings_version);
        }
    }

    public function test_avg_manual_material_issue_to_finished_receipt_flow(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        if (app(GlobalSettings::class)->value('inventory_costing_method') !== 'AVG') {
            $this->markTestSkipped('local company ยังไม่ได้ตั้ง Costing Method เป็น AVG');
        }

        $actor = User::query()->orderBy('id')->first();
        $warehouse = Warehouse::query()->where('is_active', true)->with('branch')->orderBy('id')->first();
        $itemBalance = $warehouse ? StockBalance::query()->where('warehouse_id', $warehouse->id)->where('available', '>=', 1)->orderBy('item_id')->first() : null;
        $sequence = $warehouse ? DocumentSequence::query()->where('document_type', 'INVENTORY_ISSUE')->where('is_active', true)->first() : null;
        if (! $actor || ! $warehouse || ! $itemBalance || ! $sequence) {
            $this->markTestSkipped('ต้องมี User, Warehouse, Stock พร้อมใช้ และ sequence INVENTORY_ISSUE');
        }

        DB::beginTransaction();
        try {
            $request = Request::create('/wms/production/material-issues', 'POST');
            $request->attributes->set('selectedWarehouse', $warehouse);
            $audit = app(AuditLogger::class);
            $issueService = app(IssueReturnService::class);
            $issue = $issueService->createIssue([
                'document_date' => today()->toDateString(),
                'reason' => 'AVG integration material issue',
                'issue_type' => 'PRODUCTION',
                'lines' => [[
                    'item_id' => $itemBalance->item_id,
                    'uom_id' => $itemBalance->uom_id,
                    'quantity' => '1.00000000',
                ]],
            ], $warehouse, $actor, app(DocumentSequenceService::class), $audit, $request);
            $issue = $issueService->approve($issue, $actor, $audit, $request);
            $issue = $issueService->post($issue, $warehouse, $actor, $audit, $request)->load('lines.allocation.movement');
            $sourceLine = $issue->lines->first();
            $sourceTotal = (string) abs((float) $sourceLine->allocation->value);

            $document = InventoryAdjustmentDocument::query()->create([
                'warehouse_id' => $warehouse->id,
                'branch_id' => $warehouse->branch_id,
                'document_number' => 'PR-AVG-'.bin2hex(random_bytes(5)),
                'document_date' => today()->toDateString(),
                'direction' => 'GAIN',
                'document_context' => 'PRODUCTION_RECEIPT',
                'source_issue_id' => $issue->id,
                'status' => 'APPROVED',
                'reason' => 'AVG integration finished receipt',
                'idempotency_key' => 'production-receipt-avg-'.bin2hex(random_bytes(5)),
                'created_by' => $actor->id,
                'approved_by' => $actor->id,
            ]);
            $document->lines()->create([
                'warehouse_id' => $warehouse->id, 'item_id' => $sourceLine->item_id, 'uom_id' => $sourceLine->uom_id,
                'line_number' => 1, 'direction' => 'GAIN', 'status' => 'APPROVED', 'quantity' => 1,
                'value' => $sourceTotal, 'business_date' => today()->toDateString(), 'reason' => 'AVG integration finished receipt',
                'idempotency_key' => 'production-receipt-line-avg-'.bin2hex(random_bytes(5)), 'created_by' => $actor->id, 'approved_by' => $actor->id,
            ]);

            $document = $document->fresh('lines');
            $preflight = app(ManualProductionReceiptPostingService::class)->preflight($document->toArray());
            if (! $preflight['ready']) {
                $this->markTestSkipped('Production Receipt posting ยังไม่พร้อม: '.collect($preflight['blockers'])->pluck('message')->implode(' '));
            }
            $posted = app(ManualProductionReceiptPostingService::class)->post($document, $warehouse, $actor, $request);

            self::assertSame('POSTED', $posted->status);
            self::assertSame('POSTED', $posted->lines->first()->allocation->fresh()->status);
            self::assertSame('FINAL', $posted->lines->first()->allocation->fresh()->cost_status);
            self::assertSame($issue->id, $posted->source_issue_id);
        } finally {
            DB::rollBack();
        }
    }

    public function test_finished_receipt_reconciles_stock_allocation_and_gl_before_commit(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->orderBy('id')->first();
        $warehouse = Warehouse::query()->where('is_active', true)->with('branch')->orderBy('id')->first();
        $source = IssueDocument::query()
            ->with('lines.allocation.movement')
            ->where('issue_type', 'PRODUCTION')
            ->where('status', 'POSTED')
            ->where('warehouse_id', $warehouse?->id)
            ->orderByDesc('id')
            ->first();
        if (! $actor || ! $warehouse || ! $source || $source->lines->isEmpty()) {
            $this->markTestSkipped('ต้องมี User, Warehouse และใบเบิก Production ที่ Posted สำหรับทดสอบ');
        }

        $sourceTotal = $source->lines->sum(fn ($line): float => abs((float) ($line->allocation?->value ?? 0)));
        if ($sourceTotal <= 0 || ! $source->lines->first()->allocation?->movement) {
            $this->markTestSkipped('ใบเบิกต้นทางต้องมี Final Cost Allocation และ Posted Movement');
        }

        $line = $source->lines->first();
        $before = StockBalance::query()->where([
            'warehouse_id' => $warehouse->id,
            'item_id' => $line->item_id,
            'uom_id' => $line->uom_id,
        ])->first();
        $beforeOnHand = (string) ($before?->on_hand ?? '0');
        $beforeValue = (string) ($before?->inventory_value ?? '0');

        DB::beginTransaction();
        try {
            $document = InventoryAdjustmentDocument::query()->create([
                'warehouse_id' => $warehouse->id,
                'branch_id' => $warehouse->branch_id,
                'document_number' => 'PR-MYSQL-'.bin2hex(random_bytes(5)),
                'document_date' => today()->toDateString(),
                'direction' => 'GAIN',
                'document_context' => 'PRODUCTION_RECEIPT',
                'source_issue_id' => $source->id,
                'status' => 'APPROVED',
                'reason' => 'Gate C integration test',
                'idempotency_key' => 'production-receipt-gate-c-'.bin2hex(random_bytes(5)),
                'created_by' => $actor->id,
                'approved_by' => $actor->id,
            ]);
            $document->lines()->create([
                'warehouse_id' => $warehouse->id,
                'item_id' => $line->item_id,
                'uom_id' => $line->uom_id,
                'line_number' => 1,
                'direction' => 'GAIN',
                'status' => 'APPROVED',
                'quantity' => 1,
                'value' => $sourceTotal,
                'business_date' => today()->toDateString(),
                'reason' => 'Gate C integration test',
                'idempotency_key' => 'production-receipt-line-gate-c-'.bin2hex(random_bytes(5)),
                'created_by' => $actor->id,
                'approved_by' => $actor->id,
            ]);

            $request = Request::create('/wms/production/finished-receipts', 'POST');
            $request->attributes->set('selectedWarehouse', $warehouse);
            $posted = app(ManualProductionReceiptPostingService::class)->post($document->fresh('lines'), $warehouse, $actor, $request);

            self::assertSame('POSTED', $posted->status);
            self::assertSame(1, $posted->lines()->whereNotNull('stock_movement_id')->whereNotNull('cost_allocation_id')->count());
            self::assertSame(1, DB::table('journal_entries')->where('source_type', 'WMS_PRODUCTION_RECEIPT')->where('source_id', (string) $posted->id)->count());

            $after = StockBalance::query()->where([
                'warehouse_id' => $warehouse->id,
                'item_id' => $line->item_id,
                'uom_id' => $line->uom_id,
            ])->firstOrFail();
            self::assertSame('1.00000000', number_format((float) $after->on_hand - (float) $beforeOnHand, 8, '.', ''));
            self::assertSame(number_format($sourceTotal, 2, '.', ''), number_format((float) $after->inventory_value - (float) $beforeValue, 2, '.', ''));

            $sourceJournalId = $posted->lines()->firstOrFail()->allocation()->value('journal_entry_id');
            $reversed = app(ProductionFinishedReceiptReversalService::class)->reverse(
                $posted,
                today()->toDateString(),
                'Integration Gate C reversal test',
                $actor,
                $request,
            );
            self::assertSame('REVERSED', $reversed->status);
            self::assertSame('REVERSED', $reversed->reversal_status);
            self::assertSame(1, $reversed->lines()->whereNotNull('reversal_movement_id')->whereNotNull('reversal_allocation_id')->count());
            self::assertSame('REVERSED', DB::table('journal_entries')->where('id', $sourceJournalId)->value('status'));
            self::assertSame('POSTED', DB::table('journal_entries')->where('source_event', 'journal.reversal')->where('source_id', 'like', 'reversal:production-finished-receipt:%')->value('status'));

            $afterReversal = StockBalance::query()->where([
                'warehouse_id' => $warehouse->id,
                'item_id' => $line->item_id,
                'uom_id' => $line->uom_id,
            ])->firstOrFail();
            self::assertSame(number_format((float) $beforeOnHand, 8, '.', ''), number_format((float) $afterReversal->on_hand, 8, '.', ''));
            self::assertSame(number_format((float) $beforeValue, 2, '.', ''), number_format((float) $afterReversal->inventory_value, 2, '.', ''));
            $retry = app(ProductionFinishedReceiptReversalService::class)->reverse(
                $reversed,
                today()->toDateString(),
                'Integration Gate C reversal test',
                $actor,
                $request,
            );
            self::assertSame($reversed->id, $retry->id);
            self::assertSame(1, DB::table('journal_entries')->where('source_event', 'journal.reversal')->where('source_id', 'like', 'reversal:production-finished-receipt:'.$posted->id.':%')->count());
        } finally {
            DB::rollBack();
        }
    }
}
