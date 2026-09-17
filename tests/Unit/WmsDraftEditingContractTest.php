<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WmsDraftEditingContractTest extends TestCase
{
    public function test_transfer_and_issue_drafts_have_guarded_edit_flows(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/app/Modules/Wms/Routes/web.php');
        $transferController = file_get_contents($root.'/app/Modules/Wms/Controllers/TransferController.php');
        $transferService = file_get_contents($root.'/app/Modules/Wms/Services/TransferMovementService.php');
        $issueController = file_get_contents($root.'/app/Modules/Wms/Controllers/IssueReturnController.php');
        $productionController = file_get_contents($root.'/app/Modules/Wms/Controllers/ProductionMaterialIssueController.php');
        $issueService = file_get_contents($root.'/app/Modules/Wms/Services/IssueReturnService.php');
        $rbac = file_get_contents($root.'/database/seeders/RbacSeeder.php');
        $transferModel = file_get_contents($root.'/app/Modules/Wms/Models/Transfer.php');
        $transferForm = file_get_contents($root.'/app/Modules/Wms/Views/transfers/form.blade.php');
        $transferShow = file_get_contents($root.'/app/Modules/Wms/Views/transfers/show.blade.php');
        $migration = file_get_contents($root.'/database/migrations/2026_09_17_130000_add_note_to_wms_transfers.php');
        $installer = file_get_contents($root.'/app/Modules/Installer/Services/DatabasePreparationService.php');

        foreach (['wms.transfers.edit', 'wms.transfers.update', 'wms.issues.edit', 'wms.issues.update', 'wms.production.material-issues.edit', 'wms.production.material-issues.update'] as $route) {
            self::assertStringContainsString("name('".str_replace('wms.', '', $route)."')", $routes);
        }
        self::assertStringContainsString("'wms.transfers.update' => 'แก้ไขร่างโอนคลัง'", $rbac);
        self::assertStringContainsString("hasPermission('wms.transfers.update')", $transferController);
        self::assertStringContainsString("status !== 'DRAFT' || \$transfer->events()->exists()", $transferService);
        self::assertStringContainsString("hasPermission('wms.issues.update')", $issueController);
        self::assertStringContainsString("hasPermission('wms.issues.update')", $productionController);
        self::assertStringContainsString("if (\$document->status !== 'DRAFT')", $issueService);
        self::assertStringContainsString("'wms.issue.updated'", $issueService);
        self::assertStringContainsString("'note' => ['nullable', 'string', 'max:1000']", $transferController);
        self::assertStringContainsString("'note'", $transferModel);
        self::assertStringContainsString('name="note"', $transferForm);
        self::assertStringContainsString('$transfer->note', $transferShow);
        self::assertStringContainsString("'note' => filled(\$attributes['note']", $transferService);
        self::assertStringContainsString("\$table->text('note')->nullable()", $migration);
        self::assertStringContainsString("\$table->dropColumn('note')", $migration);
        self::assertStringContainsString("'wms_transfers' => ['note', 'deleted_at']", $installer);

        foreach ([
            'app/Modules/Wms/Views/transfers/index.blade.php',
            'app/Modules/Wms/Views/transfers/show.blade.php',
            'app/Modules/Wms/Views/issues/index.blade.php',
            'app/Modules/Wms/Views/issues/show.blade.php',
            'app/Modules/Wms/Views/production/material-issues/index.blade.php',
            'app/Modules/Wms/Views/production/material-issues/show.blade.php',
        ] as $view) {
            self::assertStringContainsString('bx-edit', file_get_contents($root.'/'.$view), $view);
        }

        foreach ([
            'app/Modules/Wms/Views/transfers/index.blade.php',
            'app/Modules/Wms/Views/issues/index.blade.php',
            'app/Modules/Wms/Views/production/material-issues/index.blade.php',
        ] as $view) {
            $contents = file_get_contents($root.'/'.$view);
            self::assertStringContainsString('bx-file-find', $contents, $view);
            self::assertStringNotContainsString('bx-show', $contents, $view);
        }
    }
}
