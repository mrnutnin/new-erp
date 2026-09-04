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
            $controller = file_get_contents($root.'/app/Modules/Purchasing/Controllers/'.$file);

            self::assertStringContainsString('authorizedWarehouseIds($request)', $controller);
        }

        foreach ([
            'PurchaseRequisitionController.php',
            'PurchaseOrderController.php',
            'PurchaseDocumentController.php',
        ] as $file) {
            $controller = file_get_contents($root.'/app/Modules/Purchasing/Controllers/'.$file);

            self::assertTrue(
                str_contains($controller, "where('branch_id', (int) \$request->attributes->get('selectedBranch')->id)")
                || str_contains($controller, "moduleRoutePrefix() === 'purchasing' ? 'branch_id' : 'warehouse_id'"),
                $file.' must resolve the selected Purchasing branch scope',
            );
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
