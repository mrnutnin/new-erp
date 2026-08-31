<?php

namespace Tests\Unit;

use App\Modules\Accounting\Support\ChartOfAccountsTemplate;
use PHPUnit\Framework\TestCase;

class ChartOfAccountsTemplateTest extends TestCase
{
    public function test_template_has_versioned_accounts_examples_and_dictionary_sheets(): void
    {
        $sheets = ChartOfAccountsTemplate::sheets();

        $this->assertSame(['Accounts', 'Examples', 'Data Dictionary', '_meta'], array_column($sheets, 'title'));
        $this->assertSame(ChartOfAccountsTemplate::HEADERS, $sheets[0]['headings']);
        $this->assertSame(5, count($sheets[1]['rows']));
        $this->assertSame('COA-1.0', ChartOfAccountsTemplate::VERSION);
    }
}
