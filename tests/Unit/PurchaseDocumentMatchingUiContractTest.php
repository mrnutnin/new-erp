<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PurchaseDocumentMatchingUiContractTest extends TestCase
{
    public function test_matching_routes_are_view_only_and_keep_warehouse_scope(): void
    {
        foreach (['wms.purchase-documents.three-way-match', 'wms.purchase-documents.purchase-order-line-options', 'wms.purchase-documents.goods-receipt-line-options'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('auth', $route->middleware());
            $this->assertContains('program:wms', $route->middleware());
            $this->assertContains('warehouse', $route->middleware());
            $this->assertContains('permission:wms.purchase-documents.view', $route->middleware());
        }
        $delete = app('router')->getRoutes()->getByName('wms.purchase-documents.destroy');
        $this->assertNotNull($delete);
        $this->assertSame(['DELETE'], $delete->methods());
        $this->assertContains('permission:wms.purchase-documents.delete', $delete->middleware());
    }

    public function test_matching_views_keep_select2_human_dates_pastel_states_and_recovery_copy(): void
    {
        $form = file_get_contents(base_path('app/Modules/Wms/Views/purchase-documents/form.blade.php'));
        $show = file_get_contents(base_path('app/Modules/Wms/Views/purchase-documents/show.blade.php'));
        $controller = file_get_contents(base_path('app/Modules/Wms/Controllers/PurchaseDocumentController.php'));

        $this->assertStringContainsString('purchase-order-line-options', $form);
        $this->assertStringContainsString('goods-receipt-line-options', $form);
        $this->assertStringContainsString('supplier_id:$(\'#purchase-supplier\').val()', $form);
        $this->assertStringContainsString('$document->document_date->format($dateFormat)', $show);
        $this->assertStringContainsString("'APPROVAL_REQUIRED'=>'app-status-warning'", $show);
        $this->assertStringContainsString('แนวทางแก้ไข:', $show);
        $this->assertStringContainsString('$threeWayMatch[\'lines\']', $show);
        $this->assertStringContainsString('ลบร่างเอกสาร', $show);
        $this->assertStringContainsString('ประวัติเอกสาร', $show);
        $this->assertStringContainsString("config('erp.inventory.purchase_posting_enabled')", $show);
        $this->assertStringContainsString("abort_unless((bool) config('erp.inventory.purchase_posting_enabled', false), 404)", $controller);
    }
}
