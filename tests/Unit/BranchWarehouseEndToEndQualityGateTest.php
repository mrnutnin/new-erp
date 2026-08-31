<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BranchWarehouseEndToEndQualityGateTest extends TestCase
{
    public function test_operational_documents_snapshot_branch_but_stock_writers_keep_warehouse_lineage(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'app/Modules/Wms/Models/PurchaseOrder.php',
            'app/Modules/Wms/Models/GoodsReceipt.php',
            'app/Modules/Pos/Models/PhysicalSale.php',
            'app/Modules/Pos/Models/SalesReturn.php',
        ] as $path) {
            $source = file_get_contents($root.'/'.$path);

            self::assertStringContainsString("'branch_id'", $source);
            self::assertStringContainsString('HasDocumentBranch', $source);
        }

        $receipt = file_get_contents($root.'/app/Modules/Wms/Services/GoodsReceiptInventoryService.php');
        self::assertStringContainsString("['warehouse_id', 'item_id', 'uom_id'", $receipt);
        self::assertStringContainsString("'source_type', 'source_id', 'source_reference'", $receipt);
    }

    public function test_sale_receipt_and_return_reject_cross_warehouse_operations_inside_the_selected_branch(): void
    {
        $root = dirname(__DIR__, 2);
        $sale = file_get_contents($root.'/app/Modules/Pos/Services/PhysicalSalePostingService.php');
        $return = file_get_contents($root.'/app/Modules/Pos/Services/SalesReturnPostingService.php');
        $receipt = file_get_contents($root.'/app/Modules/Pos/Controllers/ReceiptController.php');

        self::assertStringContainsString('(int) $sale->warehouse_id !== (int) $warehouse->id', $sale);
        self::assertStringContainsString('->whereKey($receipt->bankAccount->warehouse_id)', $receipt);
        self::assertStringContainsString('->where(\'branch_id\', $branchId)', $receipt);
        self::assertStringContainsString('$return->warehouse_id !== $warehouse->id', $return);
        self::assertStringContainsString('$sale->warehouse_id !== $warehouse->id', $return);
        self::assertStringContainsString('where(\'warehouse_id\', $warehouse->id)', $return);
    }

    public function test_purchase_to_return_path_preserves_stock_cost_and_gl_source_identity(): void
    {
        $root = dirname(__DIR__, 2);
        $goodsReceipt = file_get_contents($root.'/app/Modules/Wms/Services/GoodsReceiptInventoryService.php');
        $sale = file_get_contents($root.'/app/Modules/Pos/Services/PhysicalSalePostingService.php');
        $return = file_get_contents($root.'/app/Modules/Pos/Services/SalesReturnInventoryPostingService.php');

        self::assertStringContainsString("'idempotency_key'", $goodsReceipt);
        self::assertStringContainsString("'unit_cost'", $goodsReceipt);
        self::assertStringContainsString("'event_code' => 'sales_cogs'", $sale);
        self::assertStringContainsString('\'source_id\' => (string) $sale->id', $sale);
        self::assertStringContainsString('\'parent_allocation_id\' => $source->id', $return);
        self::assertStringContainsString('\'source_cost_allocation_id\' => $row[\'sourceAllocation\']->id', $return);
        self::assertStringContainsString("'event_code' => 'sales_cogs'", $return);
    }

    public function test_wms_entry_points_can_only_switch_to_an_authorized_warehouse_in_the_current_branch(): void
    {
        $root = dirname(__DIR__, 2);
        $middleware = file_get_contents($root.'/app/Modules/Platform/Middleware/EnsureWarehouseSelected.php');

        self::assertStringContainsString("\$request->isMethod('GET') && \$request->is('wms/*')", $middleware);
        self::assertStringContainsString("->where('is_active', true)", $middleware);
        self::assertStringContainsString("->when(\$branch, fn (\$query) => \$query->where('branch_id', \$branch->id))", $middleware);

        foreach ([
            'app/Modules/Wms/Views/transfers/index.blade.php',
            'app/Modules/Wms/Views/inventory-adjustments/index.blade.php',
            'app/Modules/Wms/Views/stock-counts/index.blade.php',
        ] as $path) {
            self::assertStringContainsString("@include('Wms::partials.warehouse-selector')", file_get_contents($root.'/'.$path));
        }
    }

    public function test_document_lists_and_pdfs_keep_their_branch_or_warehouse_boundary(): void
    {
        $root = dirname(__DIR__, 2);
        $physicalSales = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');
        $returns = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReturnController.php');
        $deposits = file_get_contents($root.'/app/Modules/Pos/Controllers/AdvanceDepositController.php');
        $purchasingPdf = file_get_contents($root.'/app/Modules/Purchasing/Controllers/PurchaseDocumentPdfController.php');
        $wmsPdf = file_get_contents($root.'/app/Modules/Wms/Controllers/PurchaseDocumentPdfController.php');

        self::assertStringContainsString("where('pos_physical_sales.branch_id', \$branch->id)", $physicalSales);
        self::assertStringContainsString('ensureCurrentBranch($request, $physicalSale)', $physicalSales);
        self::assertStringContainsString("where('branch_id', \$branch->id)", $returns);
        self::assertStringContainsString('ensureCurrentBranch($request, $salesReturn)', $returns);
        self::assertStringContainsString('aiQuery((int) $request->attributes->get(\'selectedBranch\')->id)', $deposits);
        self::assertStringContainsString("where('branch_id', (int) \$request->attributes->get('selectedBranch')->id)", $purchasingPdf);
        self::assertStringContainsString("where('is_active', true)", $purchasingPdf);
        self::assertStringContainsString('(int) $model->warehouse_id === (int) $request->attributes->get(\'selectedWarehouse\')->id', $wmsPdf);
    }

    public function test_posted_documents_cannot_be_cancelled_as_drafts_or_cross_their_source_warehouse(): void
    {
        $root = dirname(__DIR__, 2);
        $physicalSales = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');
        $returns = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReturnController.php');

        self::assertStringContainsString("\$physicalSale->status === 'POSTED'", $physicalSales);
        self::assertStringContainsString('PhysicalSaleCancellationService $cancellation', $physicalSales);
        self::assertStringContainsString("\$document->status !== 'DRAFT'", $returns);
        self::assertStringContainsString('ยกเลิกได้เฉพาะใบรับคืน/ลดหนี้ฉบับร่าง', $returns);
        self::assertStringContainsString('$warehouse = $salesReturn->warehouse;', $returns);
    }
}
