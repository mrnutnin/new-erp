<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PettyCashBladeUiContractTest extends TestCase
{
    public function test_petty_cash_views_keep_the_required_ui_contract(): void
    {
        $base = dirname(__DIR__, 2).'/app/Modules/Finance/Views/petty-cash/';
        $index = file_get_contents($base.'index.blade.php');
        $form = file_get_contents($base.'form.blade.php');
        $show = file_get_contents($base.'show.blade.php');

        $this->assertStringContainsString('window.erpDataTableDefaults', $index);
        $this->assertStringContainsString("route('finance.petty-cash.data')", $index);
        $this->assertStringNotContainsString('petty-cash-fund-modal', $index);
        $this->assertStringContainsString('วงเงินสดย่อย', $index);
        $this->assertStringContainsString('window.erpAjaxForm', $form);
        $this->assertStringContainsString('expenseCategoryOptions', $form);
        $this->assertStringContainsString('sequenceOptions', $form);
        $this->assertStringContainsString("route('finance.petty-cash.post'", $show);
        $this->assertStringContainsString('js-petty-cash-reason', $show);
    }
}
