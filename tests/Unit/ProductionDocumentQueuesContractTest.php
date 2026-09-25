<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionDocumentQueuesContractTest extends TestCase
{
    public function test_production_dashboard_drills_into_wms_document_workflows_without_changing_wms_authorization(): void
    {
        $root = dirname(__DIR__, 2);
        $entry = file_get_contents($root.'/app/Modules/Production/Controllers/EntryController.php');
        $dashboard = file_get_contents($root.'/app/Modules/Production/Views/dashboard.blade.php');
        $routes = file_get_contents($root.'/app/Modules/Production/Routes/web.php');
        $controller = file_get_contents($root.'/app/Modules/Production/Controllers/DocumentQueueController.php');
        $index = file_get_contents($root.'/app/Modules/Production/Views/document-queues/index.blade.php');
        $show = file_get_contents($root.'/app/Modules/Production/Views/document-queues/show.blade.php');

        foreach ([
            'where(\'warehouse_id\', $warehouse->id)',
            'where(\'branch_id\', $branch->id)',
            'where(\'issue_type\', \'PRODUCTION\')',
            'where(\'document_context\', \'PRODUCTION_RECEIPT\')',
            "'kind' => 'issue'",
            "'kind' => 'receipt'",
            "'status' => 'DRAFT'",
            "'status' => 'APPROVED'",
        ] as $contract) {
            self::assertStringContainsString($contract, $entry);
        }
        self::assertStringContainsString("route('production.document-queues.material-issues.index'", $dashboard);
        self::assertStringContainsString("route('production.document-queues.finished-receipts.index'", $dashboard);
        self::assertStringNotContainsString("route('programs.store')", $dashboard);

        foreach ([
            "permission:wms.issues.view",
            "permission:wms.issues.approve",
            "permission:wms.issues.post",
            "permission:wms.inventory-adjustments.view",
            "permission:wms.inventory-adjustments.approve",
            "permission:wms.inventory-adjustments.post",
        ] as $permission) {
            self::assertStringContainsString($permission, $routes);
        }
        foreach ([
            'DataTables::eloquent($query)',
            'where(\'warehouse_id\', $warehouse->id)',
            'where(\'branch_id\', $branch->id)',
            '$issues->approve(',
            '$issues->post(',
            '$receipts->approve(',
            '$posting->post(',
            'preflight($document->toArray())',
            '$document->issue_type === \'PRODUCTION\'',
            '$document->document_context === \'PRODUCTION_RECEIPT\'',
        ] as $contract) {
            self::assertStringContainsString($contract, $controller);
        }

        self::assertStringContainsString('serverSide: true', $index);
        self::assertStringContainsString('window.erpDataTableDefaults', $index);
        self::assertStringContainsString('window.erpExcelButton(tableElement)', $index);
        self::assertStringContainsString('$.fn.dataTable.render.text()', $index);
        self::assertStringContainsString('Swal.fire', $show);
        self::assertStringContainsString('postReadiness', $show);
        self::assertStringContainsString('ลง Stock และ GL', $show);
    }
}
