<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Support\ManualProductionReceiptContract;
use App\Modules\Wms\Support\WmsDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ProductionDocumentPdfController extends Controller
{
    public function order(Request $request, ProductionOrder $order, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        $this->assertOrderScope($request, $order);
        $order->load(['branch:id,code,name,tax_address', 'salesOrder:id,document_number', 'finishedItem:id,code,name', 'uom:id,code,name', 'bomRevision.bom:id,code', 'issueWarehouse:id,code,name', 'receiptWarehouse:id,code,name', 'materials.item:id,code,name', 'materials.uom:id,code,name']);
        $statusLabels = ['DRAFT' => 'ร่าง', 'RELEASED' => 'พร้อมผลิต', 'IN_PROGRESS' => 'กำลังผลิต', 'COMPLETED' => 'เสร็จแล้ว', 'CANCELLED' => 'ยกเลิกเอกสาร'];
        $dateFormat = (string) ($settings->value('date_format') ?: 'd/m/Y');
        $lines = $order->materials->map(fn ($line): array => [
            'line_number' => $line->line_number,
            'code' => $line->item?->code ?: '—',
            'description' => $line->item?->name ?: '—',
            'uom' => $line->uom?->code ?: '—',
            'quantity' => WmsDecimal::format($line->required_quantity),
            'value' => null,
        ])->all();

        return $this->renderInternal($request, $renderer, $settings, [
            'title' => 'ใบสั่งผลิต',
            'number' => $order->document_number,
            'date' => $order->created_at?->format($dateFormat) ?: '—',
            'status' => $statusLabels[$order->status] ?? $order->status,
            'watermark' => match ($order->status) { 'DRAFT' => 'ร่าง', 'CANCELLED' => 'ยกเลิกเอกสาร', default => null },
            'warehouse' => $order->issueWarehouse?->code.' · '.$order->issueWarehouse?->name,
            'metadata' => [
                ['label' => 'ประเภทการผลิต', 'value' => $order->order_type === 'MAKE_TO_ORDER' ? 'ผลิตตามคำสั่งขาย' : 'ผลิตเพื่อสต็อก'],
                ['label' => 'สินค้าสำเร็จรูป', 'value' => trim(($order->finishedItem?->code ?: '').' · '.($order->finishedItem?->name ?: '—'), ' ·')],
                ['label' => 'จำนวนตามแผน', 'value' => WmsDecimal::format($order->planned_quantity).' '.$order->uom?->code],
                ['label' => 'BOM', 'value' => trim(($order->bomRevision?->bom?->code ?: '—').' Rev '.($order->bomRevision?->revision_number ?: '' ))],
                ['label' => 'คลังเบิก', 'value' => trim(($order->issueWarehouse?->code ?: '').' · '.($order->issueWarehouse?->name ?: '—'), ' ·')],
                ['label' => 'คลังรับผลิต', 'value' => trim(($order->receiptWarehouse?->code ?: '').' · '.($order->receiptWarehouse?->name ?: '—'), ' ·')],
                ['label' => 'เริ่มแผน', 'value' => $order->planned_start_date?->format($dateFormat) ?: '—'],
                ['label' => 'เสร็จแผน', 'value' => $order->planned_finish_date?->format($dateFormat) ?: '—'],
                ['label' => 'กำหนดส่ง', 'value' => $order->required_delivery_at?->format($dateFormat) ?: ($order->required_delivery_date?->format($dateFormat) ?: '—')],
                ['label' => 'ข้อกำหนดลูกค้า', 'value' => $order->customer_specification ?: '—'],
            ],
            'references' => $order->salesOrder ? [['label' => 'ใบสั่งขาย', 'number' => $order->salesOrder->document_number]] : [],
            'lines' => $lines,
            'showValue' => false,
            'totalValue' => null,
            'notes' => $order->notes,
            'lineHeading' => 'รายการวัตถุดิบตามแผน',
        ]);
    }

    public function materialIssue(Request $request, ProductionOrder $order, IssueDocument $document, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        $this->assertOrderScope($request, $order);
        abort_unless(
            $document->issue_type === 'PRODUCTION'
            && (int) $document->branch_id === (int) $request->attributes->get('selectedBranch')->id
            && (int) $document->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id
            && (int) $order->issue_warehouse_id === (int) $document->warehouse_id
            && $order->events()->where('event_type', 'material_issue_created')->where('source_id', (string) $document->id)->exists(),
            404,
        );
        $document->load(['warehouse:id,code,name', 'lines.item:id,code,name', 'lines.uom:id,code,name']);
        $lines = $document->lines->map(fn ($line): array => [
            'line_number' => $line->line_number,
            'code' => $line->item?->code ?: '—',
            'description' => $line->item?->name ?: '—',
            'uom' => $line->uom?->code ?: '—',
            'quantity' => WmsDecimal::format($line->quantity),
            'value' => null,
        ])->all();

        return $this->renderInternal($request, $renderer, $settings, [
            'title' => 'ใบเบิกวัตถุดิบผลิต',
            'number' => $document->document_number,
            'date' => $document->document_date?->format((string) ($settings->value('date_format') ?: 'd/m/Y')) ?: '—',
            'status' => ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิกเอกสาร', 'REVERSED' => 'ยกเลิกเอกสารแล้ว'][$document->status] ?? $document->status,
            'watermark' => match ($document->status) { 'DRAFT' => 'ร่าง', 'VOID', 'REVERSED' => 'ยกเลิกเอกสาร', default => null },
            'warehouse' => trim(($document->warehouse?->code ?: '').' · '.($document->warehouse?->name ?: '—'), ' ·'),
            'metadata' => [['label' => 'เหตุผลการเบิก', 'value' => $document->reason ?: '—']],
            'references' => [['label' => 'ใบสั่งผลิต', 'number' => $order->document_number]],
            'lines' => $lines,
            'showValue' => false,
            'totalValue' => null,
            'notes' => null,
            'lineHeading' => 'รายการวัตถุดิบที่เบิก',
        ]);
    }

    public function finishedReceipt(Request $request, ProductionOrder $order, InventoryAdjustmentDocument $document, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        $this->assertOrderScope($request, $order);
        abort_unless(
            $document->document_context === ManualProductionReceiptContract::CONTEXT
            && (int) $document->branch_id === (int) $request->attributes->get('selectedBranch')->id
            && (int) $document->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id
            && (int) $order->receipt_warehouse_id === (int) $document->warehouse_id
            && $order->events()->where('event_type', 'material_issue_created')->where('source_id', (string) $document->source_issue_id)->exists(),
            404,
        );
        $document->load(['warehouse:id,code,name', 'lines.item:id,code,name', 'lines.uom:id,code,name']);
        $lines = $document->lines->map(fn ($line): array => [
            'line_number' => $line->line_number,
            'code' => $line->item?->code ?: '—',
            'description' => $line->item?->name ?: '—',
            'uom' => $line->uom?->code ?: '—',
            'quantity' => WmsDecimal::format($line->quantity),
            'value' => WmsDecimal::format($line->value),
        ])->all();
        $totalValue = $document->lines->reduce(fn (BigDecimal $sum, $line): BigDecimal => $sum->plus((string) $line->value), BigDecimal::zero())
            ->toScale(WmsDecimal::places(), RoundingMode::HALF_UP)->__toString();
        $status = $document->reversal_status === 'REVERSED' ? 'REVERSED' : $document->status;

        return $this->renderInternal($request, $renderer, $settings, [
            'title' => 'ใบรับสินค้าผลิตเสร็จ',
            'number' => $document->document_number,
            'date' => $document->document_date?->format((string) ($settings->value('date_format') ?: 'd/m/Y')) ?: '—',
            'status' => ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock และบัญชีแล้ว', 'VOID' => 'ยกเลิกเอกสาร', 'REVERSED' => 'ยกเลิกเอกสารแล้ว'][$status] ?? $status,
            'watermark' => match ($status) { 'DRAFT' => 'ร่าง', 'VOID', 'REVERSED' => 'ยกเลิกเอกสาร', default => null },
            'warehouse' => trim(($document->warehouse?->code ?: '').' · '.($document->warehouse?->name ?: '—'), ' ·'),
            'metadata' => [['label' => 'เหตุผลการรับผลิต', 'value' => $document->reason ?: '—']],
            'references' => [['label' => 'ใบเบิกวัตถุดิบต้นทาง', 'number' => IssueDocument::query()->whereKey($document->source_issue_id)->where('warehouse_id', $document->warehouse_id)->where('issue_type', 'PRODUCTION')->value('document_number') ?: '—'], ['label' => 'ใบสั่งผลิต', 'number' => $order->document_number]],
            'lines' => $lines,
            'showValue' => true,
            'totalValue' => WmsDecimal::format($totalValue),
            'notes' => null,
            'lineHeading' => 'รายการสินค้าสำเร็จรูปที่รับ',
        ]);
    }

    private function renderInternal(Request $request, DocumentPdfRenderer $renderer, GlobalSettings $settings, array $data): Response
    {
        $branch = $request->attributes->get('selectedBranch');
        $bytes = $renderer->renderView('Production::pdf.internal-document', $data + [
            'documentTitle' => $data['title'],
            'documentNumber' => $data['number'],
            'documentDate' => $data['date'],
            'statusLabel' => $data['status'],
            'logo' => $settings->logoDataUri(),
            'companyName' => (string) ($settings->value('company_name') ?: config('app.name')),
            'companyAddress' => (string) ($branch?->tax_address ?: $settings->value('company_address') ?: '—'),
            'branchLabel' => trim(($branch?->code ?: '').' · '.($branch?->name ?: ''), ' ·'),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($data['number']).'.pdf"',
        ]);
    }

    private function assertOrderScope(Request $request, ProductionOrder $order): void
    {
        $branch = $request->attributes->get('selectedBranch');
        $warehouse = $request->attributes->get('selectedWarehouse');
        abort_unless(
            (int) $order->branch_id === (int) $branch->id
            && in_array((int) $warehouse->id, [(int) $order->issue_warehouse_id, (int) $order->receipt_warehouse_id], true),
            404,
        );
    }
}
