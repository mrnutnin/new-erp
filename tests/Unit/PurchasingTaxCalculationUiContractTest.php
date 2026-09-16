<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Requests\SavePurchaseDocumentRequest;
use App\Modules\Purchasing\Requests\SavePurchaseOrderRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PurchasingTaxCalculationUiContractTest extends TestCase
{
    public static function taxCalculations(): array
    {
        return [
            'inclusive' => ['VAT_INCLUSIVE', 'VAT_IN', true],
            'exclusive' => ['VAT_EXCLUSIVE', 'VAT_IN', false],
            'none' => ['NONE', 'NONE_VAT', false],
        ];
    }

    #[DataProvider('taxCalculations')]
    public function test_purchase_requests_map_the_shared_tax_calculation(string $input, string $treatment, bool $includesVat): void
    {
        foreach ([SavePurchaseOrderRequest::class, SavePurchaseDocumentRequest::class] as $requestClass) {
            $request = $requestClass::create('/', 'POST', ['tax_calculation' => $input]);
            $method = new \ReflectionMethod($request, 'prepareForValidation');
            $method->invoke($request);

            self::assertSame($input, $request->input('tax_calculation'));
            self::assertSame($treatment, $request->input('tax_treatment'));
            self::assertSame($includesVat, $request->boolean('prices_include_vat'));
        }
    }

    public function test_purchase_forms_use_the_same_three_user_facing_options(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['purchase-orders/form.blade.php', 'purchase-documents/form.blade.php'] as $view) {
            $source = file_get_contents($base.'/app/Modules/Purchasing/Views/'.$view);

            self::assertStringContainsString('name="tax_calculation"', $source);
            self::assertStringContainsString('value="VAT_INCLUSIVE"', $source);
            self::assertStringContainsString('>รวมภาษี</option>', $source);
            self::assertStringContainsString('value="VAT_EXCLUSIVE"', $source);
            self::assertStringContainsString('>ภาษีนอก</option>', $source);
            self::assertStringContainsString('value="NONE"', $source);
            self::assertStringContainsString('>ไม่มีภาษี</option>', $source);
            self::assertStringNotContainsString('name="prices_include_vat"', $source);
        }
    }

    public function test_purchase_order_json_is_prepared_outside_the_blade_json_directive(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Purchasing/Views/purchase-orders/form.blade.php');

        self::assertStringContainsString('@json($taxCodeOptions)', $source);
        self::assertStringNotContainsString('@json($taxCodes->map->only(', $source);
    }

    public function test_purchase_order_uses_line_total_as_input_and_tax_code_as_an_overridable_default(): void
    {
        $base = dirname(__DIR__, 2);
        $form = file_get_contents($base.'/app/Modules/Purchasing/Views/purchase-orders/form.blade.php');
        $controller = file_get_contents($base.'/app/Modules/Purchasing/Controllers/PurchaseOrderController.php');
        $optionController = file_get_contents($base.'/app/Modules/Purchasing/Controllers/PurchaseDocumentController.php');

        self::assertStringContainsString('name="lines[{{$i}}][line_amount]"', $form);
        self::assertStringContainsString('js-po-unit-price', $form);
        self::assertStringContainsString('readonly tabindex="-1"', $form);
        self::assertStringContainsString('id="po-default-tax-code"', $form);
        self::assertStringContainsString('แก้เฉพาะบรรทัดได้', $form);
        self::assertStringContainsString('สินค้าและรายละเอียด', $form);
        self::assertStringContainsString('placeholder="รายละเอียดรายการ"', $form);
        self::assertStringNotContainsString('<th style="min-width:200px">รายละเอียด</th>', $form);
        self::assertStringContainsString('WmsDecimal::places()', $form);
        self::assertStringContainsString('quantity.attr({step:quantityStep, min:quantityStep})', $form);
        self::assertStringContainsString('quantityValue.toFixed(quantityDecimals)', $form);
        self::assertStringContainsString("[name\$=\"[description]\"]').prop('required', false)", $form);
        self::assertStringContainsString("'lines.*.description' => ['nullable', 'string', 'max:500']", file_get_contents($base.'/app/Modules/Purchasing/Requests/SavePurchaseOrderRequest.php'));
        self::assertStringContainsString(".on('select2:select'", $form);
        self::assertStringContainsString('new Option(item.uom_text, item.uom_id, true, true)', $form);
        self::assertStringContainsString("with('baseUom:id,code,name,is_active')", $optionController);
        self::assertStringContainsString("'uom_id' => \$item->baseUom?->is_active ? \$item->base_uom_id : null", $optionController);
        self::assertStringContainsString("except(['discount_amount', 'net_amount', 'line_amount'])", $controller);
        self::assertStringContainsString("->dividedBy((string) \$line['quantity']", $controller);
    }

    public function test_purchase_order_request_keeps_legacy_unit_price_payload_compatible(): void
    {
        $request = SavePurchaseOrderRequest::create('/', 'POST', [
            'tax_calculation' => 'NONE',
            'lines' => [['quantity' => '3', 'unit_price' => '33.3333', 'description' => null]],
        ]);
        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->invoke($request);

        self::assertSame('100.00', $request->input('lines.0.line_amount'));
        self::assertSame('', $request->input('lines.0.description'));
    }

    public function test_credit_note_loads_and_locks_the_source_invoice_tax_profile(): void
    {
        $base = dirname(__DIR__, 2);
        $controller = file_get_contents($base.'/app/Modules/Purchasing/Controllers/PurchaseDocumentController.php');
        $form = file_get_contents($base.'/app/Modules/Purchasing/Views/purchase-documents/form.blade.php');

        self::assertStringContainsString("\$request->filled('original_document_id')", $controller);
        self::assertStringContainsString("'tax_treatment' => \$original->tax_treatment", $controller);
        self::assertStringContainsString("'prices_include_vat' => \$original->prices_include_vat", $controller);
        self::assertStringContainsString("'account_id', 'tax_code_id', 'quantity'", $controller);
        self::assertStringContainsString('Tax Code ของใบลดหนี้ต้องตรงกับบรรทัดในใบตั้งหนี้ซื้อที่อ้างอิง', $controller);

        self::assertStringContainsString('function lockOriginalTaxProfile()', $form);
        self::assertStringContainsString("creditCreateUrl+'&original_document_id='", $form);
        self::assertStringContainsString("\$calculation.prop('disabled',true)", $form);
        self::assertStringContainsString("\$tax.prop('disabled',true)", $form);
        self::assertStringContainsString("\$original.prop('disabled',true)", $form);
        self::assertStringContainsString("name:name,value:\$tax.val()||''", $form);
    }
}
