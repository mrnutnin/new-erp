<?php

namespace Tests\Unit;

use App\Modules\Accounting\Support\JournalBalance;
use PHPUnit\Framework\TestCase;

class JournalBalanceTest extends TestCase
{
    public function test_it_totals_money_as_integer_cents(): void
    {
        $this->assertSame(['debit' => 10010, 'credit' => 10010], JournalBalance::totals([
            ['debit' => '100.10', 'credit' => '0.00'],
            ['debit' => '0.00', 'credit' => '60.05'],
            ['debit' => '0.00', 'credit' => '40.05'],
        ]));
        $this->assertSame('9999999999999999.99', JournalBalance::decimal('9999999999999999.99'));
        $this->assertSame('140.15', JournalBalance::add('100.10', '40.05'));
        $this->assertSame('60.05', JournalBalance::subtract('100.10', '40.05'));
    }
}
