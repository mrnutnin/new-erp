<?php

namespace Tests\Unit;

use App\Modules\Platform\Services\DocumentPdfRenderer;
use Tests\TestCase;

final class ProductionInternalDocumentPdfContractTest extends TestCase
{
    public function test_work_order_and_wms_pdf_routes_reuse_existing_view_permissions_and_validate_scope(): void
    {
        $root = base_path();
        $routes = file_get_contents($root.'/app/Modules/Production/Routes/web.php');
        $controller = file_get_contents($root.'/app/Modules/Production/Controllers/ProductionDocumentPdfController.php');
        $detail = file_get_contents($root.'/app/Modules/Production/Views/orders/show.blade.php');
        $template = file_get_contents($root.'/app/Modules/Production/Views/pdf/internal-document.blade.php');

        foreach ([
            "name('orders.pdf')",
            "name('orders.material-issues.pdf')",
            "name('orders.finished-receipts.pdf')",
            'permission:production.orders.view',
            'permission:wms.issues.view',
            'permission:wms.inventory-adjustments.view',
        ] as $contract) {
            self::assertStringContainsString($contract, $routes);
        }
        foreach ([
            'DocumentPdfRenderer $renderer',
            "renderView('Production::pdf.internal-document'",
            "'Content-Type' => 'application/pdf'",
            "'Content-Disposition' => 'inline; filename=",
            'selectedBranch',
            'selectedWarehouse',
            'material_issue_created',
            'ManualProductionReceiptContract::CONTEXT',
        ] as $contract) {
            self::assertStringContainsString($contract, $controller);
        }
        self::assertStringContainsString('production.orders.pdf', $detail);
        self::assertStringContainsString('production.orders.material-issues.pdf', $detail);
        self::assertStringContainsString('production.orders.finished-receipts.pdf', $detail);
        self::assertStringContainsString('วันที่เอกสาร', $detail);
        self::assertStringContainsString('INTERNAL', $template);
        self::assertStringContainsString('class="pdf-internal-document', $template);
        self::assertStringContainsString('.pdf-internal-document .pdf-product th', file_get_contents($root.'/app/Modules/Platform/Services/DocumentPdfRenderer.php'));
        self::assertStringContainsString('$watermark', $template);
    }

    public function test_shared_production_internal_template_renders_empty_and_multipage_documents(): void
    {
        $data = [
            'logo' => null,
            'companyName' => 'บริษัททดสอบ',
            'companyAddress' => 'กรุงเทพมหานคร',
            'branchLabel' => 'HQ · สำนักงานใหญ่',
            'documentTitle' => 'ใบรับสินค้าผลิตเสร็จ',
            'documentNumber' => 'FGRTEST0001',
            'documentDate' => '25/09/2026',
            'statusLabel' => 'ร่าง',
            'watermark' => 'ร่าง',
            'warehouse' => 'HQ-WH · คลังทดสอบ',
            'metadata' => [['label' => 'เหตุผล', 'value' => 'ทดสอบรับผลิต']],
            'references' => [['label' => 'ใบเบิกวัตถุดิบ', 'number' => 'ISSUETEST0001']],
            'lines' => [],
            'showValue' => true,
            'totalValue' => '40.00',
            'notes' => null,
            'lineHeading' => 'รายการสินค้าสำเร็จรูปที่รับ',
        ];

        foreach ([0, 1, 30, 100] as $count) {
            $data['lines'] = $count ? array_map(fn (int $number): array => [
                'line_number' => $number,
                'code' => 'FG-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'description' => 'สินค้าทดสอบรายการ '.$number,
                'uom' => 'PCS',
                'quantity' => '2.00',
                'value' => '40.00',
            ], range(1, $count)) : [];
            $pdf = app(DocumentPdfRenderer::class)->renderView('Production::pdf.internal-document', $data);
            self::assertStringStartsWith('%PDF-', $pdf);
            if ($count === 100) {
                self::assertGreaterThan(1, preg_match_all('/\\/Type\\s*\\/Page\\b/', $pdf));
            }
        }
    }
}
