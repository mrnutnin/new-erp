<?php

namespace Tests\Unit;

use App\Modules\Pos\Requests\SavePhysicalSaleRequest;
use PHPUnit\Framework\TestCase;

class PhysicalSaleDraftRequestTest extends TestCase
{
    public function test_invalid_tax_calculation_is_preserved_for_validation(): void
    {
        $request = SavePhysicalSaleRequest::create('/', 'POST', ['tax_calculation' => 'INVALID']);
        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->invoke($request);

        self::assertSame('INVALID', $request->input('tax_calculation'));
        self::assertSame('VAT_OUT', $request->input('tax_treatment'));
        self::assertTrue($request->boolean('prices_include_vat'));
    }

    public function test_draft_request_contract_requires_source_and_supported_document_type(): void
    {
        $rules = (new SavePhysicalSaleRequest)->rules();

        $this->assertContains('required', $rules['document_type']);
        $this->assertContains('required', $rules['source_type']);
        $this->assertContains('required', $rules['source_id']);
        $this->assertContains('integer', $rules['source_id']);
        $this->assertContains('min:1', $rules['source_id']);
        $this->assertContains('after_or_equal:document_date', $rules['posting_date']);
        $this->assertContains('after_or_equal:document_date', $rules['due_date']);
        $this->assertContains('required', $rules['tax_treatment']);
        $this->assertContains('required', $rules['prices_include_vat']);
        $this->assertContains('required', $rules['tax_calculation']);
    }

    public function test_draft_tax_treatment_ui_and_storage_contract_are_declared(): void
    {
        $base = dirname(__DIR__, 2);
        $request = file_get_contents($base.'/app/Modules/Pos/Requests/SavePhysicalSaleRequest.php');
        $controller = file_get_contents($base.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');
        $view = file_get_contents($base.'/app/Modules/Pos/Views/physical-sales/form.blade.php');

        self::assertStringContainsString("'VAT_EXCLUSIVE' => ['tax_treatment' => 'VAT_OUT', 'prices_include_vat' => false]", $request);
        self::assertStringContainsString("'NONE' => ['tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false]", $request);
        self::assertStringContainsString("'tax_calculation' => \$taxCalculation", $request);
        self::assertStringContainsString("default => ['tax_treatment' => 'VAT_OUT', 'prices_include_vat' => true]", $request);
        self::assertStringContainsString("'tax_treatment' => \$values['tax_treatment']", $controller);
        self::assertStringContainsString("'prices_include_vat' => \$values['prices_include_vat']", $controller);
        self::assertStringContainsString('name="tax_calculation"', $view);
        self::assertStringContainsString('value="VAT_INCLUSIVE"', $view);
        self::assertStringContainsString('value="VAT_EXCLUSIVE"', $view);
        self::assertStringContainsString('value="NONE"', $view);
        self::assertStringContainsString('name="tax_code_id"', $view);
        self::assertStringContainsString('Tax Code ภาษีขาย', $view);
        self::assertStringContainsString('syncVatCode', $view);
        self::assertStringContainsString("\$calculation.val() === 'NONE'", $view);
        self::assertStringContainsString("\$code.prop('disabled', noneVat).prop('required', !noneVat)", $view);
    }
}
