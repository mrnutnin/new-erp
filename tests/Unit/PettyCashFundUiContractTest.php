<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PettyCashFundUiContractTest extends TestCase
{
    public function test_fund_settings_have_separate_list_and_edit_pages(): void
    {
        $base = dirname(__DIR__, 2).'/app/Modules/Finance/Views/petty-cash-funds/';
        $this->assertStringContainsString('window.erpDataTableDefaults', file_get_contents($base.'index.blade.php'));
        $this->assertStringContainsString('js-delete-fund', file_get_contents($base.'index.blade.php'));
        $this->assertStringContainsString('window.erpAjaxForm', file_get_contents($base.'form.blade.php'));
        $this->assertStringContainsString('name="name"', file_get_contents($base.'form.blade.php'));
        $this->assertStringContainsString('redirect:', file_get_contents($base.'form.blade.php'));
        $this->assertStringContainsString("}}});});</script>", file_get_contents($base.'form.blade.php'));
        $this->assertStringContainsString('ประวัติเอกสาร', file_get_contents($base.'form.blade.php'));
        $this->assertStringContainsString('$auditLogs', file_get_contents($base.'form.blade.php'));
        $this->assertStringContainsString('petty-cash-fund-filter-title', file_get_contents($base.'index.blade.php'));
        $this->assertStringContainsString('window.erpExcelButton', file_get_contents($base.'index.blade.php'));
    }
}
