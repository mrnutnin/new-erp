<?php

namespace Tests\Unit;

use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\PhysicalSaleLine;
use PHPUnit\Framework\TestCase;

class PhysicalSaleModelContractTest extends TestCase
{
    public function test_physical_sale_uses_dedicated_tables_and_ordered_lines(): void
    {
        $sale = new PhysicalSale;
        $line = new PhysicalSaleLine;

        $this->assertSame('pos_physical_sales', $sale->getTable());
        $this->assertSame('pos_physical_sale_lines', $line->getTable());
        $this->assertContains('journal_entry_id', $sale->getFillable());
        $this->assertContains('cogs_journal_entry_id', $sale->getFillable());
        $this->assertContains('due_date', $sale->getFillable());
        $this->assertContains('withholding_tax_code_id', $sale->getFillable());
        $this->assertContains('withholding_amount', $sale->getFillable());
        $this->assertContains('stock_quantity', $line->getFillable());
    }
}
