<?php

namespace Tests\Unit;

use App\Models\Party;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class PurchasingPurchaseOrderPdfContractTest extends TestCase
{
    public function test_purchase_order_pdf_uses_standard_a4_layout_and_is_permissioned(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Purchasing/Routes/web.php'));
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseDocumentPdfController.php'));
        $pdf = file_get_contents(base_path('app/Modules/Purchasing/Views/pdf/purchase-order.blade.php'));

        self::assertStringContainsString("Route::get('/purchase-orders/{purchaseOrder}/pdf'", $routes);
        self::assertStringContainsString('permission:purchasing.purchase-orders.print', $routes);
        self::assertStringContainsString("renderView('Purchasing::pdf.purchase-order'", $controller);
        self::assertStringContainsString('warehouse.branch', $controller);
        self::assertStringContainsString('supplier', $controller);
        self::assertStringContainsString('paymentTerm', $controller);
        self::assertStringContainsString('createdBy', $controller);
        self::assertStringContainsString('approvedBy', $controller);
        self::assertStringContainsString('lines.taxCode', $controller);
        self::assertStringContainsString('rawurlencode($document->document_number)', $controller);
        self::assertStringContainsString("'Content-Disposition' => 'inline; filename=\"'", $controller);

        foreach (['ใบสั่งซื้อ / PURCHASE ORDER', 'pdf-product', 'pdf-total-summary', 'pdf-signatures', 'ผู้ขาย / Supplier', 'อ้างอิง PR', 'รวมภาษี', 'ภาษีนอก', 'ไม่มีภาษี', 'มูลค่าก่อน VAT', 'ภาษีมูลค่าเพิ่ม', 'ร่าง — ยังไม่ใช่เอกสารที่อนุมัติแล้ว', 'เอกสารนี้เป็นใบสั่งซื้อ ไม่ใช่ใบกำกับภาษี'] as $expected) {
            self::assertStringContainsString($expected, $pdf);
        }
    }

    public function test_purchase_order_records_immutable_vat_snapshots(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_16_090000_add_vat_snapshots_to_purchase_orders.php'));
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseOrderController.php'));
        $request = file_get_contents(base_path('app/Modules/Purchasing/Requests/SavePurchaseOrderRequest.php'));

        foreach (['tax_treatment', 'prices_include_vat', 'tax_code_id', 'tax_rate', 'tax_base', 'tax_amount', 'gross_amount'] as $field) {
            self::assertStringContainsString($field, $migration);
            self::assertStringContainsString($field, $controller);
        }
        self::assertStringContainsString("DB::raw('line_total')", $migration);
        self::assertStringContainsString('PurchaseDocumentCalculator::calculate', $controller);
        self::assertStringContainsString("Rule::in(['NONE_VAT', 'VAT_IN'])", $request);
        self::assertStringContainsString('required_if:tax_treatment,VAT_IN', $request);
    }

    public function test_purchase_order_pdf_actions_open_in_a_new_tab(): void
    {
        $index = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-orders/index.blade.php'));
        $show = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-orders/show.blade.php'));

        self::assertStringContainsString('target="_blank"', $index);
        self::assertStringContainsString('target="_blank"', $show);
    }

    public function test_purchase_order_detail_displays_every_saved_header_field(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseOrderController.php'));
        $show = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-orders/show.blade.php'));

        self::assertStringContainsString("'supplier', 'warehouse', 'paymentTerm', 'purchaseRequisition'", $controller);
        foreach (['Supplier', 'คลังรับสินค้า', 'เงื่อนไขชำระเงิน', 'อ้างอิง PR', 'วันที่เอกสาร', 'คาดรับสินค้า', 'การคำนวณภาษี', 'Tax Code ที่ใช้', 'หมายเหตุ'] as $label) {
            self::assertStringContainsString($label, $show);
        }
    }

    public function test_purchase_order_pdf_renders_vat_snapshot_totals(): void
    {
        $order = new PurchaseOrder;
        $order->forceFill([
            'document_number' => 'PO-TEST-VAT-001',
            'document_date' => Carbon::parse('2026-09-16'),
            'expected_date' => Carbon::parse('2026-09-20'),
            'supplier_code' => 'SUP-001',
            'supplier_name' => 'ผู้ขายทดสอบ',
            'status' => 'APPROVED',
            'tax_treatment' => 'VAT_IN',
            'prices_include_vat' => false,
            'subtotal' => '100.00',
            'tax_amount' => '7.00',
            'total_amount' => '107.00',
        ]);
        $order->setRelation('supplier', (new Party)->forceFill(['name' => 'ผู้ขายทดสอบ']));
        $order->setRelation('paymentTerm', null);
        $order->setRelation('purchaseRequisition', null);
        $order->setRelation('createdBy', null);
        $order->setRelation('approvedBy', null);

        $line = (new PurchaseOrderLine)->forceFill([
            'line_number' => 1,
            'description' => 'สินค้าทดสอบ VAT',
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'line_total' => '100.00',
            'tax_rate' => '7.0000',
            'tax_base' => '100.00',
            'tax_amount' => '7.00',
            'gross_amount' => '107.00',
        ]);
        $line->setRelation('item', null);
        $line->setRelation('uom', null);
        $line->setRelation('taxCode', (new TaxCode)->forceFill(['code' => 'VAT7-IN']));
        $order->setRelation('lines', collect([$line]));

        $html = view('Purchasing::pdf.purchase-order', [
            'order' => $order,
            'logo' => null,
            'companyName' => 'บริษัททดสอบ จำกัด',
            'companyAddress' => 'กรุงเทพมหานคร',
            'companyTaxId' => '0100000000001',
            'companyTaxBranchCode' => '00000',
            'dateFormat' => 'd/m/Y',
            'decimalPlaces' => 2,
        ])->render();

        self::assertStringContainsString('ภาษีนอก', $html);
        self::assertStringContainsString('7.00', $html);
        self::assertStringContainsString('107.00', $html);
    }
}
