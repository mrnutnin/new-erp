<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SalesReturnFoundationTest extends TestCase
{
    public function test_sales_return_is_source_linked_and_draft_only_in_mvp(): void
    {
        $controller = file_get_contents(__DIR__.'/../../app/Modules/Pos/Controllers/SalesReturnController.php');
        $migration = file_get_contents(__DIR__.'/../../database/migrations/2026_08_27_140000_create_pos_sales_returns_tables.php');

        $this->assertStringContainsString("where('status', 'POSTED')", $controller);
        $this->assertStringContainsString('ต้องเลือกเอกสาร HS/IV ที่ลงบัญชีแล้ว', $controller);
        $this->assertStringContainsString("whereIn('status', ['DRAFT', 'POSTED'])", $controller);
        $this->assertStringContainsString('public function saleOptions', $controller);
        $this->assertStringContainsString('public function sourceLineOptions', $controller);
        $this->assertStringContainsString('public function pdf', $controller);
        $this->assertStringContainsString('DocumentPdfRenderer', $controller);
        $this->assertStringContainsString('forPage($page, 31)', $controller);
        $this->assertStringNotContainsString('StockMovement', $controller);
        $this->assertStringNotContainsString('JournalEntry', $controller);
        $this->assertStringContainsString("->enum('status', ['DRAFT', 'POSTED', 'VOID'])", $migration);
        $this->assertStringContainsString("->foreignId('physical_sale_id')->constrained('pos_physical_sales')->restrictOnDelete()", $migration);
    }

    public function test_request_requires_reason_source_and_return_lines(): void
    {
        $request = file_get_contents(__DIR__.'/../../app/Modules/Pos/Requests/SaveSalesReturnRequest.php');
        $controller = file_get_contents(__DIR__.'/../../app/Modules/Pos/Controllers/SalesReturnController.php');

        $this->assertStringContainsString("'physical_sale_id' => ['required', 'integer', 'min:1']", $request);
        $this->assertStringContainsString("'reason' => ['required', 'string', 'min:3', 'max:500']", $request);
        $this->assertStringContainsString("'lines' => ['required', 'array', 'min:1', 'max:100']", $request);
        $this->assertStringContainsString("'lines.*.quantity' => [...WmsDecimal::rule(), 'gt:0']", $request);

        $form = file_get_contents(__DIR__.'/../../app/Modules/Pos/Views/sales-returns/form.blade.php');
        $routes = file_get_contents(__DIR__.'/../../app/Modules/Pos/Routes/web.php');
        $this->assertStringContainsString("route('pos.sales-returns.sale-options')", $form);
        $this->assertStringContainsString('quantityStep = {{ $quantityStep }}', $form);
        $this->assertStringNotContainsString('physical_sale_line_id" class="form-control"', $form);
        $this->assertStringNotContainsString('return-line-add', $form);
        $this->assertStringContainsString('return-line-include', $form);
        $this->assertStringContainsString("window.erpAjaxForm({ form: '#sales-return-form', redirect: true })", $form);
        $this->assertStringContainsString("'document_type' => \$physicalSale->document_type", $controller);
        $this->assertStringContainsString('sales-returns/source-lines/{physicalSale}', $routes);
        $this->assertStringContainsString('sales-returns/{salesReturn}/pdf', $routes);
    }
}
