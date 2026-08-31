<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PurchasingBranchWarehouseScopeContractTest extends TestCase
{
    public function test_canonical_purchasing_branch_queries_are_limited_to_authorized_warehouses(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'PurchaseRequisitionController.php',
            'PurchaseOrderController.php',
            'PurchaseDocumentController.php',
        ] as $file) {
            $controller = file_get_contents($root.'/app/Modules/Wms/Controllers/'.$file);

            self::assertStringContainsString('authorizedWarehouseIds($request)', $controller);
        }

        foreach ([
            'PurchaseRequisitionController.php',
            'PurchaseOrderController.php',
            'PurchaseDocumentController.php',
        ] as $file) {
            $controller = file_get_contents($root.'/app/Modules/Purchasing/Controllers/'.$file);

            self::assertStringContainsString("where('branch_id', (int) \$request->attributes->get('selectedBranch')->id)", $controller);
        }
    }

    public function test_purchasing_pdf_requires_both_selected_branch_and_authorized_warehouse(): void
    {
        $root = dirname(__DIR__, 2);
        $pdf = file_get_contents($root.'/app/Modules/Purchasing/Controllers/PurchaseDocumentPdfController.php');

        self::assertStringContainsString("where('branch_id', (int) \$request->attributes->get('selectedBranch')->id)", $pdf);
        self::assertStringContainsString('in_array((int) $model->warehouse_id, $warehouseIds, true)', $pdf);
    }
}
