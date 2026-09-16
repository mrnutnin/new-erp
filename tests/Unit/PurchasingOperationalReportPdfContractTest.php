<?php

namespace Tests\Unit;

use App\Modules\Platform\Services\DocumentPdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class PurchasingOperationalReportPdfContractTest extends TestCase
{
    public function test_operational_report_is_filtered_scoped_and_opens_in_a_new_tab(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Purchasing/Routes/web.php'));
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/OperationalReportController.php'));
        $index = file_get_contents(base_path('app/Modules/Purchasing/Views/reports/index.blade.php'));
        $pdf = file_get_contents(base_path('app/Modules/Purchasing/Views/pdf/operational-report.blade.php'));

        self::assertStringContainsString("Route::get('/reports/pdf'", $routes);
        self::assertStringContainsString('permission:purchasing.reports.view', $routes);
        self::assertStringContainsString("'date_from' => ['required', 'date_format:Y-m-d']", $controller);
        self::assertStringContainsString("whereIn('warehouse_id', \$warehouseIds)", $controller);
        self::assertStringContainsString("renderView('Purchasing::pdf.operational-report'", $controller);
        self::assertStringContainsString('target="_blank"', $index);
        self::assertStringContainsString('name="date_from"', $index);
        self::assertStringContainsString('name="date_to"', $index);
        foreach (['รายงานปฏิบัติการจัดซื้อ', 'ช่วงวันที่เอกสาร', 'วันที่สร้างรายงาน', 'สร้างโดย', 'ไม่ใช่ใบกำกับภาษี'] as $expected) {
            self::assertStringContainsString($expected, $pdf);
        }
    }

    public function test_operational_report_pdf_renders_empty_and_populated_sections(): void
    {
        foreach ([collect(), collect([(object) ['status' => 'APPROVED', 'document_count' => 3, 'total_amount' => '1500.00']])] as $rows) {
            $bytes = app(DocumentPdfRenderer::class)->renderView('Purchasing::pdf.operational-report', $this->data($rows));

            self::assertStringStartsWith('%PDF-', $bytes);
        }
    }

    private function data(Collection $rows): array
    {
        return [
            'sections' => collect([['title' => 'ใบสั่งซื้อ (PO)', 'rows' => $rows]]),
            'dateFrom' => CarbonImmutable::parse('2026-09-01'),
            'dateTo' => CarbonImmutable::parse('2026-09-30'),
            'generatedAt' => CarbonImmutable::parse('2026-09-16 12:00:00'),
            'generatedBy' => 'System Administrator',
            'warehouses' => collect([(object) ['code' => 'HQ-WH', 'name' => 'คลังสำนักงานใหญ่']]),
            'logo' => null,
            'companyName' => 'บริษัท ทดสอบระบบ จำกัด',
            'companyAddress' => 'กรุงเทพมหานคร',
            'companyTaxId' => '0100000000000',
            'companyTaxBranchCode' => '00000',
            'branchName' => 'สำนักงานใหญ่',
            'dateFormat' => 'd/m/Y',
        ];
    }
}
