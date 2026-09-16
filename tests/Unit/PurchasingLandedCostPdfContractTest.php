<?php

namespace Tests\Unit;

use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\LandedCostLine;
use App\Modules\Purchasing\Models\LandedCostReceipt;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class PurchasingLandedCostPdfContractTest extends TestCase
{
    public function test_landed_cost_uses_the_internal_allocation_pdf_contract(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Purchasing/Routes/web.php'));
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseDocumentPdfController.php'));
        $pdf = file_get_contents(base_path('app/Modules/Purchasing/Views/pdf/landed-cost.blade.php'));
        $rbac = file_get_contents(base_path('database/seeders/RbacSeeder.php'));

        self::assertStringContainsString("Route::get('/landed-costs/{landedCost}/pdf'", $routes);
        self::assertStringContainsString('permission:purchasing.landed-costs.print', $routes);
        self::assertStringContainsString('purchasing.landed-costs.print', $rbac);
        self::assertStringContainsString("renderView('Purchasing::pdf.landed-cost'", $controller);
        self::assertStringContainsString("groupBy('goods_receipt_line_id')", $controller);
        self::assertStringContainsString('rawurlencode($document->document_number)', $controller);

        foreach (['ใบสรุปต้นทุนแฝง', 'เอกสารภายใน', 'วิธีปันส่วน', 'ค่าใช้จ่ายที่นำมาปันส่วน', 'ผลการปันส่วนตามรายการรับสินค้า', 'มูลค่าก่อน', 'ต้นทุนเพิ่ม', 'มูลค่าหลัง', 'pdf-product', 'pdf-footer', 'pdf-signatures'] as $expected) {
            self::assertStringContainsString($expected, $pdf);
        }
    }

    public function test_landed_cost_pdf_actions_open_in_a_new_tab(): void
    {
        $index = file_get_contents(base_path('app/Modules/Purchasing/Views/landed-costs/index.blade.php'));
        $show = file_get_contents(base_path('app/Modules/Purchasing/Views/landed-costs/show.blade.php'));

        self::assertStringContainsString('target="_blank"', $index);
        self::assertStringContainsString('target="_blank"', $show);
        self::assertStringContainsString('bx bx-printer', $index);
        self::assertStringContainsString('bx bx-printer', $show);
    }

    public function test_landed_cost_pdf_renders_zero_one_and_many_targets(): void
    {
        foreach ([0, 1, 30, 100] as $count) {
            $pdf = app(DocumentPdfRenderer::class)->renderView('Purchasing::pdf.landed-cost', $this->pdfData($count));

            self::assertStringStartsWith('%PDF-', $pdf, "PDF with {$count} targets must render");
        }
    }

    /** @return array<string, mixed> */
    private function pdfData(int $count): array
    {
        $warehouse = new Warehouse(['code' => 'HQ-WH', 'name' => 'คลังสำนักงานใหญ่']);
        $document = new LandedCost([
            'document_number' => 'LC-TEST-0001',
            'business_date' => Carbon::parse('2026-09-16'),
            'status' => 'DRAFT',
            'allocation_basis' => 'VALUE',
            'currency_code' => 'THB',
            'total_amount' => (string) ($count * 10),
        ]);
        $document->setRelation('warehouse', $warehouse);
        $document->setRelation('createdBy', null);
        $document->setRelation('postedBy', null);
        $document->setRelation('lines', $count === 0 ? collect() : collect([
            (new LandedCostLine(['expense_source_type' => 'MANUAL', 'amount' => (string) ($count * 10), 'description' => 'ค่าขนส่งและค่าใช้จ่ายนำเข้า']))->setRelation('account', new Account(['code' => '510100', 'name' => 'ค่าขนส่งสินค้า'])),
        ]));
        $receipt = (new LandedCostReceipt(['selected_value' => (string) ($count * 100), 'allocated_amount' => (string) ($count * 10)]))
            ->setRelation('goodsReceipt', new GoodsReceipt(['receipt_number' => 'GR-TEST-0001']));
        $document->setRelation('receipts', $count === 0 ? collect() : collect([$receipt]));

        $targets = Collection::times($count, fn (int $index): array => [
            'receipt_number' => 'GR-TEST-0001',
            'item' => new Item(['code' => 'ITEM-'.$index, 'name' => 'สินค้าทดสอบภาษาไทยรายการที่ '.$index]),
            'uom' => new Uom(['code' => 'PCS']),
            'quantity' => '1',
            'basis' => '100',
            'ratio' => $count > 0 ? (string) (1 / $count) : '0',
            'before' => '100',
            'added' => '10',
            'after' => '110',
        ]);

        return [
            'document' => $document,
            'targets' => $targets,
            'logo' => null,
            'companyName' => 'บริษัท ทดสอบระบบ จำกัด',
            'companyAddress' => '99 ถนนทดสอบ แขวงทดสอบ เขตทดสอบ กรุงเทพมหานคร 10000',
            'companyTaxId' => '0100000000000',
            'companyTaxBranchCode' => '00000',
            'dateFormat' => 'd/m/Y',
            'decimalPlaces' => 2,
        ];
    }
}
