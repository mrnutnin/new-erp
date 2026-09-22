<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Services\PhysicalSalePostingService;
use App\Modules\Pos\Services\PhysicalSaleCancellationService;
use App\Modules\Production\Controllers\OrderController;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomLine;
use App\Modules\Production\Models\BomRevision;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Services\ManualProductionReceiptPostingService;
use App\Modules\Wms\Controllers\ProductionFinishedReceiptController;
use App\Modules\Wms\Controllers\ProductionIssueReturnController;
use App\Modules\Wms\Services\ProductionFinishedReceiptDocumentService;
use App\Modules\Wms\Services\ProductionFinishedReceiptReversalService;
use App\Modules\Wms\Services\ProductionScrapReceiptService;
use App\Modules\Wms\Services\IssueReturnService;
use App\Modules\Wms\Services\StockMovementService;
use App\Modules\Wms\Services\StockReservationService;
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
        $party = DB::table('parties')->where('is_active', true)->orderBy('id')->first(['id', 'code', 'name']);
        $accountedItem = DB::table('wms_items')->where('is_active', true)->whereNotNull('inventory_account_id')->whereNotNull('cogs_account_id')->whereNotNull('sales_account_id')->orderBy('id')->first(['inventory_account_id', 'cogs_account_id', 'sales_account_id']);
        $settingsBefore = DB::table('company_settings')->where('id', 1)->first();
        if (! $actor || ! $baseWarehouse || ! $categoryId || ! $uomId || ! $party || ! $accountedItem || ! $settingsBefore) {
            $this->markTestSkipped('ต้องมี User, Warehouse, Category, UOM, Customer, Item accounts และ Company Setting');
        }

        DB::beginTransaction();
        try {
            DocumentSequence::query()->firstOrCreate(
                ['warehouse_id' => null, 'document_type' => 'PRODUCTION_ORDER'],
                ['name' => 'ใบสั่งผลิต', 'prefix' => 'WO', 'number_format' => '{PREFIX}{BRANCH}{YYMM}{NUMBER:6}', 'reset_rule' => 'MONTHLY', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'created_by' => $actor->id],
            );
            DocumentSequence::query()->firstOrCreate(
                ['warehouse_id' => null, 'document_type' => 'PRODUCTION_SCRAP_RECEIPT'],
                ['name' => 'ใบรับเศษผลิต', 'prefix' => 'PSR', 'number_format' => '{PREFIX}{BRANCH}{YYMM}{NUMBER:6}', 'reset_rule' => 'MONTHLY', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'created_by' => $actor->id],
            );
            $stamp = substr((string) hrtime(true), -10);
            $warehouse = Warehouse::query()->create(['branch_id' => $baseWarehouse->branch_id, 'code' => 'PR-FIFO-'.$stamp, 'name' => 'Production FIFO Test', 'is_active' => true]);
            $item = Item::query()->create([
                'category_id' => $categoryId, 'code' => 'PR-FIFO-'.$stamp, 'name' => 'Production FIFO Test Item',
                'item_type' => 'GOODS', 'base_uom' => 'TEST', 'base_uom_id' => $uomId,
                'is_stock_item' => true, 'inventory_account_id' => $accountedItem->inventory_account_id, 'cogs_account_id' => $accountedItem->cogs_account_id, 'sales_account_id' => $accountedItem->sales_account_id, 'is_active' => true, 'created_by' => $actor->id,
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

            $bom = Bom::query()->create([
                'branch_id' => $warehouse->branch_id, 'code' => 'PR-FIFO-'.$stamp, 'name' => 'Production FIFO Test BOM',
                'finished_item_id' => $item->id, 'base_uom_id' => $uomId, 'is_active' => true, 'created_by' => $actor->id,
            ]);
            $revision = BomRevision::query()->create([
                'bom_id' => $bom->id, 'revision_number' => 1, 'status' => 'ACTIVE', 'effective_from' => today(),
                'activated_at' => now(), 'activated_by' => $actor->id, 'created_by' => $actor->id,
            ]);
            BomLine::query()->create(['bom_revision_id' => $revision->id, 'line_number' => 1, 'component_item_id' => $item->id, 'uom_id' => $uomId, 'quantity' => '10.00000000']);
            $timestamps = ['created_at' => now(), 'updated_at' => now()];
            $rfqId = DB::table('sales_rfqs')->insertGetId([
                'warehouse_id' => $warehouse->id, 'document_number' => 'RFQ-FIFO-'.$stamp, 'party_id' => $party->id,
                'party_code' => $party->code, 'party_name' => $party->name, 'document_date' => today(), 'status' => 'WAIT',
                'created_by' => $actor->id, ...$timestamps,
            ]);
            $rfqLineId = DB::table('sales_rfq_lines')->insertGetId([
                'sales_rfq_id' => $rfqId, 'line_number' => 1, 'item_id' => $item->id, 'uom_id' => $uomId,
                'description' => 'Production FIFO Test Item', 'quantity' => '1.0000', ...$timestamps,
            ]);
            $quotationId = DB::table('sales_quotations')->insertGetId([
                'warehouse_id' => $warehouse->id, 'sales_rfq_id' => $rfqId, 'party_id' => $party->id,
                'document_number' => 'QT-FIFO-'.$stamp, 'party_code' => $party->code, 'party_name' => $party->name,
                'document_date' => today(), 'status' => 'ACCEPTED', 'accepted_by' => $actor->id, 'accepted_at' => now(),
                'created_by' => $actor->id, ...$timestamps,
            ]);
            $quotationLineId = DB::table('sales_quotation_lines')->insertGetId([
                'sales_quotation_id' => $quotationId, 'source_rfq_line_id' => $rfqLineId, 'line_number' => 1,
                'item_id' => $item->id, 'uom_id' => $uomId, 'description' => 'Production FIFO Test Item',
                'quantity' => '1.0000', 'unit_price' => '1.00', 'line_total' => '1.00', ...$timestamps,
            ]);
            $salesOrderId = DB::table('sales_orders')->insertGetId([
                'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id, 'sales_quotation_id' => $quotationId, 'party_id' => $party->id,
                'document_number' => 'SO-FIFO-'.$stamp, 'party_code' => $party->code, 'party_name' => $party->name,
                'document_date' => today(), 'required_delivery_date' => today(), 'status' => 'CONFIRMED',
                'subtotal' => '1.00', 'total_amount' => '1.00', 'created_by' => $actor->id, ...$timestamps,
            ]);
            $salesOrderLineId = DB::table('sales_order_lines')->insertGetId([
                'sales_order_id' => $salesOrderId, 'source_quotation_line_id' => $quotationLineId, 'line_number' => 1,
                'item_id' => $item->id, 'uom_id' => $uomId, 'description' => 'Production FIFO Test Item',
                'quantity' => '1.0000', 'unit_price' => '1.00', 'line_total' => '1.00', ...$timestamps,
            ]);
            $orders = app(ProductionOrderService::class);
            $request = Request::create('/wms/production/material-issues', 'POST');
            $request->attributes->set('selectedWarehouse', $warehouse);
            $productionOrder = $orders->createFromSalesOrderLine($salesOrderLineId, $warehouse, $actor, $request);
            $productionOrder = $orders->release($productionOrder, $warehouse, $actor, $request);

            $request = Request::create('/wms/production/material-issues', 'POST');
            $request->attributes->set('selectedWarehouse', $warehouse);
            $audit = app(AuditLogger::class);
            $issueService = app(IssueReturnService::class);
            $issue = $orders->createMaterialIssue($productionOrder, $warehouse, $actor, $request, $issueService);
            $issue = $issueService->approve($issue, $actor, $audit, $request);
            $issue = $issueService->post($issue, $warehouse, $actor, $audit, $request)->load('lines.movement');
            self::assertSame('IN_PROGRESS', $productionOrder->fresh()->status);
            self::assertNull($productionOrder->fresh()->started_at);
            $startRequest = Request::create('/production/shop-floor/'.$productionOrder->id.'/start', 'POST');
            $startRequest->setUserResolver(fn () => $actor);
            $startRequest->attributes->set('selectedWarehouse', $warehouse);
            $startedOrder = $orders->startFromShopFloor($productionOrder, $warehouse, $actor, $startRequest);
            self::assertNotNull($startedOrder->started_at);
            $returnRequest = Request::create('/production/orders/'.$productionOrder->id.'/material-return', 'POST');
            $returnRequest->setUserResolver(fn () => $actor);
            $returnRequest->attributes->set('selectedWarehouse', $warehouse);
            $returnRequest->attributes->set('selectedBranch', $warehouse->branch);
            $returnController = app(OrderController::class);
            $returnController->createMaterialReturn($returnRequest, $productionOrder, $issueService, app(DocumentSequenceService::class), $audit);
            $returnController->createMaterialReturn($returnRequest, $productionOrder, $issueService, app(DocumentSequenceService::class), $audit);
            $returnKey = 'production-order:'.$productionOrder->id.':material-return:issue:'.$issue->id;
            self::assertSame(1, IssueReturn::query()->where('idempotency_key', $returnKey)->count());
            $draftReturn = IssueReturn::query()->where('idempotency_key', $returnKey)->firstOrFail();
            $draftReturn->lines()->delete();
            $draftReturn->delete();
            $sourceLine = $issue->lines->first();
            $scrapReportRequest = Request::create('/production/orders/'.$productionOrder->id.'/scrap/non-recoverable', 'POST', [
                'uom_id' => $uomId, 'quantity' => '1.00000000', 'reason' => 'FIFO integration non recoverable scrap',
            ]);
            $scrapReportRequest->attributes->set('selectedWarehouse', $warehouse);
            $reportedScrap = app(ProductionOrderService::class)->reportNonRecoverableScrap($productionOrder, $warehouse, $actor, $scrapReportRequest);
            self::assertSame('REPORTED', $reportedScrap->status);
            $sourceAllocations = CostAllocation::query()->where('stock_movement_id', $sourceLine->stock_movement_id)->where('status', '!=', 'REVERSED')->orderBy('id')->get();
            self::assertSame(['6.00000000', '4.00000000'], $sourceAllocations->pluck('quantity')->map(fn ($value): string => (string) $value)->all());
            $sourceTotal = $sourceAllocations->reduce(fn (BigDecimal $sum, CostAllocation $allocation): BigDecimal => $sum->plus(BigDecimal::of((string) $allocation->value)->abs()), BigDecimal::zero());

            $sourceConsumptions = $sourceAllocations->map(fn (CostAllocation $allocation): array => [
                'issue_document_id' => $issue->id, 'issue_line_id' => $sourceLine->id, 'source_allocation_id' => $allocation->id,
                'source_allocation_revision' => $allocation->revision, 'consumed_quantity' => (string) $allocation->quantity,
                'consumed_value' => (string) BigDecimal::of((string) $allocation->value)->abs(),
            ])->values()->all();
            $documentService = app(ProductionFinishedReceiptDocumentService::class);
            $receiptValues = [
                'document_date' => today()->toDateString(), 'reason' => 'FIFO integration finished receipt',
                'source_issue_ids' => [$issue->id],
                'lines' => [['item_id' => $item->id, 'uom_id' => $uomId, 'quantity' => '1.00000000', 'value' => $sourceTotal->__toString()]],
                'source_consumptions' => $sourceConsumptions,
            ];
            $document = $documentService->create([...$receiptValues, 'idempotency_key' => 'production-receipt-fifo-'.$stamp], $warehouse, $actor, $request);
            $document = $documentService->update($document, [...$receiptValues, 'idempotency_key' => 'production-receipt-fifo-'.$stamp], $actor, $request);
            $draftToDelete = $documentService->create([...$receiptValues, 'idempotency_key' => 'production-receipt-fifo-delete-'.$stamp], $warehouse, $actor, $request);
            $draftToDelete->refresh();
            self::assertSame('DRAFT', $draftToDelete->status);
            $deleteRequest = Request::create('/wms/production/finished-receipts/'.$draftToDelete->id, 'DELETE');
            $deleteRequest->setUserResolver(fn () => $actor);
            $deleteRequest->attributes->set('selectedWarehouse', $warehouse);
            $deleteRequest->attributes->set('selectedBranch', $warehouse->branch);
            app(ProductionFinishedReceiptController::class)->destroy($deleteRequest, $draftToDelete, app(AuditLogger::class));
            self::assertSoftDeleted('wms_inventory_adjustment_documents', ['id' => $draftToDelete->id]);
            $document = $documentService->approve($document, $actor, $request);
            $preflight = app(ManualProductionReceiptPostingService::class)->preflight($document->toArray());
            if (! $preflight['ready']) {
                $this->markTestSkipped('Production Receipt FIFO ยังไม่พร้อม: '.collect($preflight['blockers'])->pluck('message')->implode(' '));
            }
            $posted = app(ManualProductionReceiptPostingService::class)->post($document, $warehouse, $actor, $request);
            self::assertSame('POSTED', $posted->status);
            self::assertSame($sourceTotal->toScale(8)->__toString(), $posted->lines->first()->value);
            self::assertSame('COMPLETED', $productionOrder->fresh()->status);
            self::assertSame('1.00000000', (string) $productionOrder->fresh()->completed_quantity);
            $reservation = DB::table('wms_stock_reservations')->where('source_type', 'SALES_ORDER_LINE')->where('source_id', (string) $salesOrderLineId)->first();
            self::assertNotNull($reservation);
            self::assertSame('OPEN', $reservation->status);
            self::assertSame('1.00000000', (string) $reservation->quantity);

            $sale = PhysicalSale::query()->create([
                'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id, 'document_type' => 'IV',
                'document_number' => 'IV-FIFO-'.$stamp, 'source_type' => 'SALES_ORDER', 'source_id' => $salesOrderId,
                'party_id' => $party->id, 'party_code' => $party->code, 'party_name' => $party->name,
                'document_date' => today(), 'due_date' => today(), 'tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false,
                'subtotal' => '1.00', 'tax_base' => '1.00', 'tax_amount' => '0.00', 'total_amount' => '1.00', 'status' => 'DRAFT', 'created_by' => $actor->id,
            ]);
            $sale->lines()->create([
                'line_number' => 1, 'source_line_id' => $salesOrderLineId, 'item_id' => $item->id,
                'sale_uom_id' => $uomId, 'stock_uom_id' => $uomId, 'quantity' => '1.00000000', 'uom_factor' => '1.00000000',
                'stock_quantity' => '1.00000000', 'unit_price' => '1.0000', 'tax_rate' => '0.0000', 'tax_base' => '1.00', 'tax_amount' => '0.00', 'line_total' => '1.00',
                'conversion_snapshot' => ['factor' => '1.00000000'],
            ]);
            $postedSale = app(PhysicalSalePostingService::class)->post($sale, today()->toDateString(), $warehouse, $actor, $request);
            self::assertSame('POSTED', $postedSale->status);
            self::assertSame('CONSUMED', DB::table('wms_stock_reservations')->where('id', $reservation->id)->value('status'));
            self::assertSame(1, DB::table('wms_stock_reservation_consumptions')->where('stock_reservation_id', $reservation->id)->count());

            $cancelledSale = app(PhysicalSaleCancellationService::class)->cancel($postedSale, $warehouse, today()->toDateString(), 'FIFO integration POS cancellation', $actor, $request);
            self::assertSame('VOID', $cancelledSale->status);
            $reversedReceipt = app(ProductionFinishedReceiptReversalService::class)->reverse($posted, today()->toDateString(), 'FIFO integration finished receipt reversal', $actor, $request);
            self::assertSame('REVERSED', $reversedReceipt->status);
            self::assertSame('IN_PROGRESS', $productionOrder->fresh()->status);

            $return = $issueService->createReturn([
                'issue_document_id' => $issue->id,
                'document_date' => today()->toDateString(),
                'reason' => 'FIFO integration material return',
                'lines' => [['issue_line_id' => $sourceLine->id, 'quantity' => '1.00000000']],
            ], $warehouse, $actor, app(DocumentSequenceService::class), $audit, $request);
            $return = $issueService->approve($return, $actor, $audit, $request);
            $return = $issueService->postReturn($return, $warehouse, $actor, $audit, $request);
            self::assertSame('POSTED', $return->status);
            $return = $issueService->reverseReturn($return, $actor, 'FIFO integration material return reversal', $audit, $request);
            self::assertSame('REVERSED', $return->status);

            $draftReturnToCancel = $issueService->createReturn([
                'issue_document_id' => $issue->id, 'document_date' => today()->toDateString(),
                'reason' => 'FIFO integration draft return cancellation',
                'lines' => [['issue_line_id' => $sourceLine->id, 'quantity' => '1.00000000']],
            ], $warehouse, $actor, app(DocumentSequenceService::class), $audit, $request);
            $draftReturnToCancel->refresh();
            $cancelReturnRequest = Request::create('/wms/production/issue-returns/'.$draftReturnToCancel->id.'/cancel', 'POST', ['reason' => 'draft return cancellation']);
            $cancelReturnRequest->setUserResolver(fn () => $actor);
            $cancelReturnRequest->attributes->set('selectedWarehouse', $warehouse);
            $cancelReturnRequest->attributes->set('selectedBranch', $warehouse->branch);
            app(ProductionIssueReturnController::class)->cancel($cancelReturnRequest, $draftReturnToCancel, $audit);
            self::assertSame('VOID', $draftReturnToCancel->refresh()->status);

            $draftReturnToDelete = $issueService->createReturn([
                'issue_document_id' => $issue->id, 'document_date' => today()->toDateString(),
                'reason' => 'FIFO integration draft return deletion',
                'lines' => [['issue_line_id' => $sourceLine->id, 'quantity' => '1.00000000']],
            ], $warehouse, $actor, app(DocumentSequenceService::class), $audit, $request);
            $draftReturnToDelete->refresh();
            $deleteReturnRequest = Request::create('/wms/production/issue-returns/'.$draftReturnToDelete->id, 'DELETE');
            $deleteReturnRequest->setUserResolver(fn () => $actor);
            $deleteReturnRequest->attributes->set('selectedWarehouse', $warehouse);
            $deleteReturnRequest->attributes->set('selectedBranch', $warehouse->branch);
            app(ProductionIssueReturnController::class)->destroy($deleteReturnRequest, $draftReturnToDelete, $audit);
            self::assertSoftDeleted('wms_issue_returns', ['id' => $draftReturnToDelete->id]);

            $scrapRequest = Request::create('/production/orders/'.$productionOrder->id.'/scrap', 'POST', [
                'scrap_item_id' => $item->id, 'uom_id' => $uomId, 'quantity' => '1.00000000',
                'recovery_total_value' => '1.00000000', 'reason' => 'FIFO integration recoverable scrap',
            ]);
            $scrapRequest->attributes->set('selectedWarehouse', $warehouse);
            $scrap = app(ProductionOrderService::class)->createRecoverableScrapReceipt($productionOrder, $warehouse, $actor, $scrapRequest);
            $scrapService = app(ProductionScrapReceiptService::class);
            $scrap = $scrapService->approve($scrap, $actor, $scrapRequest);
            $scrap = $scrapService->post($scrap, $warehouse, $actor, $scrapRequest);
            self::assertSame('POSTED', $scrap->status);
            $scrap = $scrapService->reverse($scrap, today()->toDateString(), 'FIFO integration recoverable scrap reversal', $actor, $scrapRequest);
            self::assertSame('REVERSED', $scrap->status);

            $draftScrapRequest = Request::create('/production/orders/'.$productionOrder->id.'/recoverable-scrap-receipt', 'POST', [
                'scrap_item_id' => $item->id, 'uom_id' => $uomId, 'quantity' => '1.00000000',
                'recovery_total_value' => '1.00000000', 'reason' => 'FIFO integration draft scrap deletion',
            ]);
            $draftScrapRequest->setUserResolver(fn () => $actor);
            $draftScrapRequest->attributes->set('selectedWarehouse', $warehouse);
            $draftScrapRequest->attributes->set('selectedBranch', $warehouse->branch);
            $draftScrap = app(ProductionOrderService::class)->createRecoverableScrapReceipt($productionOrder, $warehouse, $actor, $draftScrapRequest);
            $draftScrap->refresh();
            app(OrderController::class)->deleteRecoverableScrapReceipt($draftScrapRequest, $productionOrder, $draftScrap, $scrapService);
            self::assertSoftDeleted('wms_inventory_adjustment_documents', ['id' => $draftScrap->id]);
            self::assertDatabaseMissing('production_order_scraps', ['status' => 'DRAFT', 'reason' => 'FIFO integration draft scrap deletion']);

            $reversedIssue = $issueService->reverseIssue($issue, $actor, 'FIFO integration material issue reversal', $audit, $request);
            self::assertSame('REVERSED', $reversedIssue->status);
            self::assertSame('RELEASED', $productionOrder->fresh()->status);
            $cancelRequest = Request::create('/production/orders/'.$productionOrder->id.'/cancel', 'POST', ['reason' => 'FIFO integration WO cancellation']);
            $cancelRequest->attributes->set('selectedWarehouse', $warehouse);
            $cancelledOrder = app(ProductionOrderService::class)->cancel($productionOrder, $warehouse, $actor, $cancelRequest, app(StockReservationService::class));
            self::assertSame('CANCELLED', $cancelledOrder->status);

            $draftRequest = Request::create('/production/orders', 'POST');
            $draftRequest->attributes->set('selectedWarehouse', $warehouse);
            $draftOrder = app(ProductionOrderService::class)->createMakeToStock([
                'bom_revision_id' => $revision->id, 'planned_quantity' => '1.00000000', 'planned_start_date' => today()->toDateString(),
            ], $warehouse, $actor, $draftRequest);
            $draftOrder = app(ProductionOrderService::class)->updateDraft($draftOrder, [
                'bom_revision_id' => $revision->id, 'planned_quantity' => '2.00000000', 'planned_start_date' => today()->toDateString(),
            ], $warehouse, $actor, $draftRequest);
            self::assertSame('2.00000000', (string) $draftOrder->planned_quantity);
            app(ProductionOrderService::class)->deleteDraft($draftOrder, $warehouse, $actor, $draftRequest);
            self::assertSoftDeleted('production_orders', ['id' => $draftOrder->id]);

            $audits = AuditLog::query()->where('user_id', $actor->id)->whereIn('action', [
                'production.order.created', 'production.order.released', 'wms.issue.created', 'production.order.updated', 'production.order.deleted', 'production.order.non_recoverable_scrap_reported', 'production.order.recoverable_scrap_receipt_created', 'wms.issue.posted', 'wms.issue.reversed', 'wms.issue_return.created', 'wms.issue_return.approved', 'wms.issue_return.posted', 'wms.issue_return.reversed', 'wms.production_issue_return.cancelled', 'wms.production_issue_return.deleted', 'wms.production_scrap_receipt.approved', 'wms.production_scrap_receipt.posted', 'wms.production_scrap_receipt.reversed', 'wms.production_scrap_receipt.deleted', 'wms.production_finished_receipt.created', 'wms.production_finished_receipt.updated', 'wms.production_finished_receipt.deleted', 'wms.production_finished_receipt.approved', 'wms.inventory_adjustment.posted',
                'wms.production_finished_receipt.reversed', 'production.order.cancelled', 'pos.physical-sale.posted', 'pos.physical-sale.cancelled',
            ])->get()->keyBy(fn (AuditLog $audit): string => $audit->getRawOriginal('action'));
            self::assertCount(28, $audits);
            self::assertSame((string) $draftScrap->id, (string) $audits['wms.production_scrap_receipt.deleted']->subject_id);
            self::assertSame('VOID', $audits['wms.production_issue_return.cancelled']->new_values['status']);
            self::assertSame((string) $draftReturnToDelete->id, (string) $audits['wms.production_issue_return.deleted']->subject_id);
            self::assertSame((string) $document->id, (string) $audits['wms.production_finished_receipt.updated']->subject_id);
            self::assertSame((string) $draftToDelete->id, (string) $audits['wms.production_finished_receipt.deleted']->subject_id);
            self::assertSame((string) $document->id, (string) AuditLog::query()->where('action', 'wms.production_finished_receipt.created')->where('subject_id', $document->id)->value('subject_id'));
            self::assertSame('APPROVED', $audits['wms.production_finished_receipt.approved']->new_values['status']);
            self::assertSame((string) $reportedScrap->id, (string) $audits['production.order.non_recoverable_scrap_reported']->subject_id);
            self::assertSame('REPORTED', $audits['production.order.non_recoverable_scrap_reported']->new_values['status']);
            self::assertSame('APPROVED', $audits['wms.issue_return.approved']->new_values['status']);
            self::assertSame((string) $scrap->id, (string) AuditLog::query()->where('action', 'production.order.recoverable_scrap_receipt_created')->where('subject_id', $scrap->id)->value('subject_id'));
            self::assertSame((string) $draftOrder->id, (string) $audits['production.order.updated']->subject_id);
            self::assertSame((string) $draftOrder->id, (string) $audits['production.order.deleted']->subject_id);
            self::assertSame('2.00000000', (string) $audits['production.order.updated']->new_values['planned_quantity']);
            self::assertSame('APPROVED', $audits['wms.production_scrap_receipt.approved']->new_values['status']);
            self::assertSame('POSTED', $audits['wms.production_scrap_receipt.posted']->new_values['status']);
            self::assertSame('REVERSED', $audits['wms.production_scrap_receipt.reversed']->new_values['status']);
            self::assertSame((string) $return->id, (string) AuditLog::query()->where('action', 'wms.issue_return.created')->where('subject_id', $return->id)->value('subject_id'));
            self::assertSame('POSTED', $audits['wms.issue_return.posted']->new_values['status']);
            self::assertSame('REVERSED', $audits['wms.issue_return.reversed']->new_values['status']);
            self::assertSame((string) $productionOrder->id, (string) $audits['production.order.cancelled']->subject_id);
            self::assertSame('CANCELLED', $audits['production.order.cancelled']->new_values['status']);
            self::assertSame((string) $productionOrder->id, (string) AuditLog::query()->where('action', 'production.order.created')->where('subject_id', $productionOrder->id)->value('subject_id'));
            self::assertSame((string) $productionOrder->id, (string) $audits['production.order.released']->subject_id);
            self::assertSame((string) $issue->id, (string) $audits['wms.issue.created']->subject_id);
            self::assertSame((int) $actor->id, (int) $audits['wms.issue.created']->user_id);
            self::assertSame('POSTED', $audits['wms.issue.posted']->new_values['status']);
            self::assertSame('POSTED', $audits['wms.inventory_adjustment.posted']->new_values['status']);
            self::assertSame('POSTED', $audits['pos.physical-sale.cancelled']->old_values['status']);
            self::assertSame('VOID', $audits['pos.physical-sale.cancelled']->new_values['status']);
            self::assertSame('REVERSED', $audits['wms.production_finished_receipt.reversed']->new_values['status']);
            self::assertSame('REVERSED', $audits['wms.issue.reversed']->new_values['status']);
            self::assertSame('FIFO integration finished receipt reversal', $audits['wms.production_finished_receipt.reversed']->new_values['reversal_reason']);
            self::assertSame('FIFO integration material issue reversal', $audits['wms.issue.reversed']->new_values['reversal_reason']);

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
            ->with('lines.costAllocations.movement')
            ->where('issue_type', 'PRODUCTION')
            ->where('status', 'POSTED')
            ->where('warehouse_id', $warehouse?->id)
            ->orderByDesc('id')
            ->first();
        if (! $actor || ! $warehouse || ! $source || $source->lines->isEmpty()) {
            $this->markTestSkipped('ต้องมี User, Warehouse และใบเบิก Production ที่ Posted สำหรับทดสอบ');
        }

        $sourceTotal = $source->lines->flatMap(fn ($line) => $line->costAllocations)
            ->where('status', '!=', 'REVERSED')
            ->sum(fn ($allocation): float => abs((float) $allocation->value));
        if ($sourceTotal <= 0 || ! $source->lines->first()->costAllocations->first()?->movement) {
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
