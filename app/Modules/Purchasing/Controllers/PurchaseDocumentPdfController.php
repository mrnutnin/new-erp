<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequisition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Purchasing-owned PDF surface.
 *
 * The document models remain shared during the staged split, but rendering
 * belongs to Purchasing so its canonical routes no longer inherit WMS code.
 */
class PurchaseDocumentPdfController extends Controller
{
    public function requisition(Request $request, PurchaseRequisition $purchaseRequisition, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        $document = $this->scope($request, $purchaseRequisition)->load(['supplier', 'lines.item', 'lines.uom']);

        return $this->pdf($renderer, 'ใบขอซื้อ', $document->document_number, $document->document_date, $document->status, $document->supplier?->name, $document->description, $document->lines->map(fn ($line) => [$line->item?->code.' · '.$line->item?->name, $line->description, $line->uom?->code, $line->quantity, null])->all(), $settings);
    }

    public function order(Request $request, PurchaseOrder $purchaseOrder, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        $document = $this->scope($request, $purchaseOrder)->load(['lines.item', 'lines.uom']);

        return $this->pdf($renderer, 'ใบสั่งซื้อ', $document->document_number, $document->document_date, $document->status, $document->supplier_name, $document->description, $document->lines->map(fn ($line) => [$line->item?->code.' · '.$line->item?->name, $line->description, $line->uom?->code, $line->quantity, $line->line_total])->all(), $settings);
    }

    public function receipt(Request $request, GoodsReceipt $purchaseReceipt, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        $document = $this->scope($request, $purchaseReceipt)->load(['supplier', 'purchaseOrder', 'lines.item', 'lines.purchaseUom', 'lines.stockUom']);

        return $this->pdf($renderer, 'ใบรับสินค้า', $document->receipt_number, $document->business_date, $document->status, $document->supplier?->name, 'PO: '.($document->purchaseOrder?->document_number ?: '-'), $document->lines->map(fn ($line) => [$line->item?->code.' · '.$line->item?->name, 'รับ '.$line->purchase_quantity.' '.$line->purchaseUom?->code, $line->stockUom?->code, $line->stock_quantity, $line->total_cost])->all(), $settings);
    }

    public function purchase(Request $request, PurchaseDocument $purchaseDocument, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        $document = $this->scope($request, $purchaseDocument)->load(['lines.account']);

        return $this->pdf($renderer, $document->document_type === 'CREDIT_NOTE' ? 'ใบลดหนี้ซื้อ' : 'ใบซื้อเชื่อ', $document->document_number, $document->document_date, $document->status, $document->supplier_name, $document->description, $document->lines->map(fn ($line) => [$line->account?->code.' · '.$line->account?->name, $line->description, null, $line->quantity, $line->gross_amount])->all(), $settings);
    }

    private function pdf(DocumentPdfRenderer $renderer, string $title, string $number, $date, string $status, ?string $party, ?string $description, array $rows, GlobalSettings $settings): Response
    {
        $logoPath = $settings->value('logo_path');
        $company = [
            'name' => (string) ($settings->value('company_name') ?: config('app.name')),
            'address' => (string) ($settings->value('company_address') ?: ''),
            'logo' => $logoPath && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->path($logoPath) : null,
        ];
        $bytes = $renderer->renderView('Purchasing::pdf.purchase-document', compact('title', 'number', 'date', 'status', 'party', 'description', 'rows', 'company') + ['dateFormat' => (string) $settings->value('date_format')]);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.rawurlencode($number).'.pdf"']);
    }

    private function scope(Request $request, object $model): object
    {
        $warehouseIds = $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
        abort_unless((int) $model->branch_id === (int) $request->attributes->get('selectedBranch')->id && in_array((int) $model->warehouse_id, $warehouseIds, true), 404);

        return $model;
    }
}
