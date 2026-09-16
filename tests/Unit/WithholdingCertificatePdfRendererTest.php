<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Modules\Accounting\Services\WithholdingCertificatePdfRenderer;
use Tests\TestCase;

final class WithholdingCertificatePdfRendererTest extends TestCase
{
    public function test_it_renders_the_official_revenue_department_template_as_one_a4_page(): void
    {
        $row = (object) [
            'settlement_date' => '2026-09-16', 'tax_base' => '1000.00', 'tax_amount' => '30.00',
            'party_name' => 'บริษัทคู่ค้า จำกัด', 'tax_id' => '0105559999999',
            'party_address' => 'กรุงเทพมหานคร', 'branch_name' => 'สำนักงานใหญ่',
        ];
        $company = new CompanySetting(['company_name' => 'บริษัททดสอบ จำกัด', 'tax_id' => '0105551111111']);

        $pdf = app(WithholdingCertificatePdfRenderer::class)->render(
            $row, $company, 'PND53', 'WT2026000001', 'กรุงเทพมหานคร', 'สามสิบบาทถ้วน', [
                'image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            ]
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(1, preg_match_all('~/Type /Page\b~', $pdf));
        self::assertFileExists(resource_path('pdf/forms/rd-withholding-certificate-50-tawi.pdf'));
    }
}
