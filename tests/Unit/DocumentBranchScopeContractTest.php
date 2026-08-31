<?php

namespace Tests\Unit;

use App\Modules\Accounting\Controllers\AccountingReportController;
use App\Modules\Accounting\Controllers\JournalEntryController;
use App\Modules\Accounting\Services\AccountingReportService;
use App\Modules\Finance\Controllers\AdvanceDepositController as FinanceAdvanceDepositController;
use App\Modules\Finance\Controllers\FinanceReportController;
use App\Modules\Finance\Controllers\OpenItemController;
use App\Modules\Finance\Controllers\PaymentVoucherController;
use App\Modules\Finance\Controllers\SettlementController;
use App\Modules\Finance\Models\AdvanceDeposit;
use App\Modules\Finance\Requests\SaveSettlementRequest;
use App\Modules\Platform\Middleware\EnsureWarehouseSelected;
use App\Modules\Pos\Controllers\ReceiptController;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesDocument;
use App\Modules\Pos\Models\SalesIntake;
use App\Modules\Pos\Models\SalesOrder;
use App\Modules\Pos\Models\SalesQuotation;
use App\Modules\Pos\Models\SalesReturn;
use App\Modules\Pos\Models\SalesRfq;
use App\Modules\Pos\Requests\SavePhysicalSaleRequest;
use App\Modules\Pos\Requests\SavePosReceiptRequest;
use App\Modules\Wms\Controllers\InventoryAdjustmentController;
use App\Modules\Wms\Controllers\PurchaseDocumentController;
use App\Modules\Wms\Controllers\PurchaseOrderController;
use App\Modules\Wms\Controllers\PurchaseReceiptController;
use App\Modules\Wms\Controllers\PurchaseRequisitionController;
use App\Modules\Wms\Controllers\StockController;
use App\Modules\Wms\Controllers\StockCountController;
use App\Modules\Wms\Controllers\StockValuationController;
use App\Modules\Wms\Controllers\TransferController;
use App\Modules\Wms\Models\GoodsReceipt;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\PurchaseOrder;
use App\Modules\Wms\Models\PurchaseRequisition;
use App\Modules\Wms\Models\StockCountDocument;
use App\Modules\Wms\Requests\SavePurchaseOrderRequest;
use App\Modules\Wms\Services\InventoryReconciliationService;
use App\Modules\Wms\Services\TransferMovementService;
use PHPUnit\Framework\TestCase;

class DocumentBranchScopeContractTest extends TestCase
{
    public function test_pos_documents_and_customer_advance_snapshot_branch_from_warehouse(): void
    {
        foreach ([SalesDocument::class, SalesIntake::class, SalesRfq::class, SalesQuotation::class, SalesOrder::class, PhysicalSale::class, SalesReturn::class, AdvanceDeposit::class] as $class) {
            $model = new $class;

            self::assertContains('branch_id', $model->getFillable());
            self::assertContains('App\\Models\\Concerns\\HasDocumentBranch', class_uses_recursive($class));
        }
    }

    public function test_physical_sale_requires_a_branch_scoped_fulfillment_warehouse(): void
    {
        self::assertArrayHasKey('fulfillment_warehouse_id', (new SavePhysicalSaleRequest)->rules());
    }

    public function test_purchase_documents_snapshot_branch_from_their_warehouse(): void
    {
        foreach ([PurchaseDocument::class, PurchaseOrder::class, PurchaseRequisition::class, GoodsReceipt::class] as $class) {
            $model = new $class;

            self::assertContains('branch_id', $model->getFillable());
            self::assertContains('App\\Models\\Concerns\\HasDocumentBranch', class_uses_recursive($class));
        }
    }

    public function test_wms_headers_snapshot_branch_but_stock_ledger_stays_warehouse_based(): void
    {
        foreach ([InventoryAdjustmentDocument::class, StockCountDocument::class, IssueDocument::class, IssueReturn::class] as $class) {
            self::assertContains('App\\Models\\Concerns\\HasDocumentBranch', class_uses_recursive($class));
        }

        $transferController = file_get_contents((new \ReflectionClass(TransferController::class))->getFileName());
        $transferService = file_get_contents((new \ReflectionClass(TransferMovementService::class))->getFileName());
        self::assertStringContainsString('return $request->user()->warehouses()', $transferController);
        self::assertStringContainsString("->where('branch_id', Warehouse::query()->whereKey(\$sourceWarehouseId)->value('branch_id'))", $transferController);
        self::assertStringContainsString('assertSameBranch($header)', $transferService);
        self::assertStringContainsString("'warehouse_id' => \$transfer->destination_warehouse_id", $transferService);
    }

    public function test_purchasing_controllers_have_a_branch_scope_without_changing_legacy_wms_scope(): void
    {
        foreach ([PurchaseDocumentController::class, PurchaseOrderController::class, PurchaseReceiptController::class, PurchaseRequisitionController::class] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());

            self::assertStringContainsString("moduleRoutePrefix() === 'purchasing' ? 'branch_id' : 'warehouse_id'", $source);
        }
    }

    public function test_direct_purchasing_po_requires_an_authorized_branch_warehouse_while_pr_and_edits_keep_their_warehouse(): void
    {
        self::assertStringContainsString("'warehouse_id'", file_get_contents((new \ReflectionClass(SavePurchaseOrderRequest::class))->getFileName()));

        $controller = file_get_contents((new \ReflectionClass(PurchaseOrderController::class))->getFileName());
        self::assertStringContainsString("elseif (\$this->moduleRoutePrefix() === 'purchasing')", $controller);
        self::assertStringContainsString('$warehouse = $this->purchasingWarehouse($request, $values[\'warehouse_id\'] ?? null);', $controller);
        self::assertStringContainsString("->where('warehouses.branch_id', \$request->attributes->get('selectedBranch')->id)", $controller);
    }

    public function test_purchasing_goods_receipt_limits_sources_and_actions_to_authorized_branch_warehouses(): void
    {
        $base = file_get_contents((new \ReflectionClass(PurchaseReceiptController::class))->getFileName());
        $purchasing = file_get_contents((new \ReflectionClass(\App\Modules\Purchasing\Controllers\PurchaseReceiptController::class))->getFileName());

        self::assertStringContainsString("whereIn('warehouse_id', \$this->authorizedWarehouseIds(\$request))", $base);
        self::assertStringContainsString('in_array((int) $receipt->warehouse_id, $this->authorizedWarehouseIds($request), true)', $base);
        self::assertStringContainsString("where('branch_id', (int) \$request->attributes->get('selectedBranch')->id)", $purchasing);
    }

    public function test_pos_receipt_resolves_its_warehouse_from_the_receiving_bank_account(): void
    {
        self::assertStringContainsString('BankAccount::query()', file_get_contents((new \ReflectionClass(SavePosReceiptRequest::class))->getFileName()));
        self::assertStringContainsString("set('selectedWarehouse'", file_get_contents((new \ReflectionClass(SavePosReceiptRequest::class))->getFileName()));
        self::assertStringContainsString("set('selectedWarehouse'", file_get_contents((new \ReflectionClass(ReceiptController::class))->getFileName()));
    }

    public function test_finance_uses_the_selected_branch_for_visibility_but_keeps_the_bank_or_source_warehouse_for_posting(): void
    {
        $settlement = file_get_contents((new \ReflectionClass(SettlementController::class))->getFileName());
        $advance = file_get_contents((new \ReflectionClass(FinanceAdvanceDepositController::class))->getFileName());
        $voucher = file_get_contents((new \ReflectionClass(PaymentVoucherController::class))->getFileName());
        $report = file_get_contents((new \ReflectionClass(FinanceReportController::class))->getFileName());
        $openItems = file_get_contents((new \ReflectionClass(OpenItemController::class))->getFileName());
        $request = file_get_contents((new \ReflectionClass(SaveSettlementRequest::class))->getFileName());

        self::assertStringContainsString("where('warehouses.branch_id', \$branchId)", $settlement);
        self::assertStringContainsString("set('selectedWarehouse', \$warehouse)", $settlement);
        self::assertStringContainsString("where('branch_id', \$request->attributes->get('selectedBranch')->id)", $advance);
        self::assertStringContainsString("whereIn('finance_payment_vouchers.warehouse_id', \$this->authorizedWarehouseIds(\$request))", $voucher);
        self::assertStringContainsString("whereIn('b.warehouse_id', \$this->authorizedWarehouseIds(\$request))", $report);
        self::assertStringContainsString("whereIn('oi.warehouse_id', \$this->authorizedWarehouseIds(\$request))", $openItems);
        self::assertStringContainsString("set('selectedWarehouse', \$warehouse)", $request);
    }

    public function test_accounting_reports_aggregate_authorized_warehouses_in_the_selected_branch(): void
    {
        $controller = file_get_contents((new \ReflectionClass(AccountingReportController::class))->getFileName());
        $journalEntries = file_get_contents((new \ReflectionClass(JournalEntryController::class))->getFileName());
        $reports = file_get_contents((new \ReflectionClass(AccountingReportService::class))->getFileName());
        $inventory = file_get_contents((new \ReflectionClass(InventoryReconciliationService::class))->getFileName());

        self::assertStringContainsString('private function authorizedWarehouseIds', $controller);
        self::assertStringContainsString('trialBalanceQuery($period, $warehouseIds)', $controller);
        self::assertStringContainsString('whereIn(\'entries.warehouse_id\', $this->warehouseIds($warehouse))', $reports);
        self::assertStringContainsString('int|array $warehouseId', $inventory);
        self::assertStringContainsString("'warehouse_ids' => \$warehouseIds", $inventory);
        self::assertStringContainsString("whereIn('warehouse_id', \$this->authorizedWarehouseIds(\$request))", $journalEntries);
    }

    public function test_wms_warehouse_picker_is_limited_to_the_current_branch_and_user_permission(): void
    {
        $middleware = file_get_contents((new \ReflectionClass(EnsureWarehouseSelected::class))->getFileName());
        $stock = file_get_contents((new \ReflectionClass(StockController::class))->getFileName());
        $valuation = file_get_contents((new \ReflectionClass(StockValuationController::class))->getFileName());
        $transfer = file_get_contents((new \ReflectionClass(TransferController::class))->getFileName());
        $adjustment = file_get_contents((new \ReflectionClass(InventoryAdjustmentController::class))->getFileName());
        $count = file_get_contents((new \ReflectionClass(StockCountController::class))->getFileName());

        self::assertStringContainsString("\$request->isMethod('GET') && \$request->is('wms/*')", $middleware);
        self::assertStringContainsString("->where('branch_id', \$branch->id)", $middleware);
        self::assertStringContainsString("->where('branch_id', \$request->attributes->get('selectedBranch')->id)", $stock);
        self::assertStringContainsString("->where('branch_id', \$request->attributes->get('selectedBranch')->id)", $valuation);
        self::assertStringContainsString("->where('branch_id', \$request->attributes->get('selectedBranch')->id)", $transfer);
        self::assertStringContainsString("->where('branch_id', \$request->attributes->get('selectedBranch')->id)", $adjustment);
        self::assertStringContainsString("->where('branch_id', \$request->attributes->get('selectedBranch')->id)", $count);
    }
}
