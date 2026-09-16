<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TransferOutgoingUiContractTest extends TestCase
{
    public function test_outgoing_transfer_keeps_searchable_ajax_table_and_detail_only_workflow_actions(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Wms/Controllers/TransferController.php');
        $index = file_get_contents($root.'/app/Modules/Wms/Views/transfers/index.blade.php');
        $detail = file_get_contents($root.'/app/Modules/Wms/Views/transfers/show.blade.php');

        foreach (['DataTables::eloquent($query)', "filterColumn('source_label'", "filterColumn('destination_label'", "filterColumn('status_label'", "filterColumn('destination_receipt_search'", "'VOID' => 'app-status-danger'", "->addColumn('can_delete'"] as $needle) {
            self::assertStringContainsString($needle, $controller);
        }

        foreach (['window.erpDataTableDefaults', 'window.erpExcelButton(table)', 'erpAjaxDelete', 'confirmButtonText: \'ลบร่าง\'', 'btn-app-danger'] as $needle) {
            self::assertStringContainsString($needle, $index);
        }

        self::assertStringNotContainsString('js-transfer-dispatch', $index);
        self::assertStringNotContainsString('js-transfer-void', $index);
        self::assertStringContainsString('กลับหน้ารายการ', $detail);
        self::assertStringContainsString('ยกเลิกเอกสาร', $detail);
        self::assertStringContainsString('ลบร่าง', $detail);
    }
}
