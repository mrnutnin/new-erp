<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PettyCashClearingContractTest extends TestCase
{
    public function test_clearing_has_scoped_workflow_audit_and_standard_ui(): void
    {
        $base = dirname(__DIR__, 2).'/app/Modules/Finance/';
        foreach (['Controllers/PettyCashClearingController.php', 'Services/PettyCashClearingService.php', 'Requests/SavePettyCashClearingRequest.php', 'Views/petty-cash-clearings/index.blade.php'] as $file) self::assertFileExists($base.$file);
        $service = file_get_contents($base.'Services/PettyCashClearingService.php');
        self::assertStringContainsString("where('status', 'POSTED')", $service);
        self::assertStringContainsString('AuditLogger', $service);
        self::assertStringContainsString('ยอดเงินจริงต่างจากยอดตามทะเบียน', $service);
        $index = file_get_contents($base.'Views/petty-cash-clearings/index.blade.php');
        self::assertStringContainsString('window.erpDataTableDefaults', $index);
        self::assertStringContainsString('window.erpExcelButton', $index);
    }
}
