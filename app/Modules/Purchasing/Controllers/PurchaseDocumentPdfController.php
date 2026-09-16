<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequisition;
use App\Modules\Settings\Services\GlobalSettings;
use Brick\Math\BigDecimal;
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
        $document = $this->scope($request, $purchaseRequisition)->load([
            'warehouse.branch', 'supplier', 'purchaseOrder',
            'lines.item', 'lines.uom', 'createdBy', 'submittedBy', 'approvedBy',
        ]);
        $logoPath = $settings->value('logo_path');
        $bytes = $renderer->renderView('Purchasing::pdf.purchase-requisition', [
            'requisition' => $document,
            'logo' => $logoPath && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->path($logoPath) : null,
            'companyName' => (string) ($settings->value('company_name') ?: config('app.name')),
            'companyAddress' => (string) ($document->warehouse?->branch?->tax_address ?: $settings->value('company_address')),
            'companyTaxId' => (string) ($settings->value('tax_id') ?: ''),
            'companyTaxBranchCode' => $document->warehouse?->branch?->tax_branch_code,
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
            'decimalPlaces' => max(0, min(4, (int) ($settings->value('tax_decimal_places') ?? 2))),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($document->document_number).'.pdf"',
        ]);
    }

    public function order(Request $request, PurchaseOrder $purchaseOrder, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        $document = $this->scope($request, $purchaseOrder)->load([
            'warehouse.branch', 'supplier', 'paymentTerm', 'purchaseRequisition',
            'lines.item', 'lines.uom', 'lines.taxCode', 'createdBy', 'approvedBy',
        ]);
        $logoPath = $settings->value('logo_path');
        $bytes = $renderer->renderView('Purchasing::pdf.purchase-order', [
            'order' => $document,
            'logo' => $logoPath && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->path($logoPath) : null,
            'companyName' => (string) ($settings->value('company_name') ?: config('app.name')),
            'companyAddress' => (string) ($document->warehouse?->branch?->tax_address ?: $settings->value('company_address')),
            'companyTaxId' => (string) ($settings->value('tax_id') ?: ''),
            'companyTaxBranchCode' => $document->warehouse?->branch?->tax_branch_code,
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
            'decimalPlaces' => max(0, min(4, (int) ($settings->value('tax_decimal_places') ?? 2))),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($document->document_number).'.pdf"',
        ]);
    }

    public function receipt(Request $request, GoodsReceipt $purchaseReceipt, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        $document = $this->scope($request, $purchaseReceipt)->load([
            'warehouse.branch', 'supplier', 'purchaseOrder',
            'lines.item', 'lines.purchaseUom', 'lines.stockUom',
            'createdBy', 'approvedBy',
        ]);
        $logoPath = $settings->value('logo_path');
        $bytes = $renderer->renderView('Purchasing::pdf.goods-receipt', [
            'receipt' => $document,
            'logo' => $logoPath && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->path($logoPath) : null,
            'companyName' => (string) ($settings->value('company_name') ?: config('app.name')),
            'companyAddress' => (string) ($document->warehouse?->branch?->tax_address ?: $settings->value('company_address')),
            'companyTaxId' => (string) ($settings->value('tax_id') ?: ''),
            'companyTaxBranchCode' => $document->warehouse?->branch?->tax_branch_code,
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
            'decimalPlaces' => max(0, min(4, (int) ($settings->value('tax_decimal_places') ?? 2))),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($document->receipt_number).'.pdf"',
        ]);
    }

    public function purchase(Request $request, PurchaseDocument $purchaseDocument, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        $document = $this->scope($request, $purchaseDocument)->load([
            'warehouse.branch', 'paymentTerm', 'originalDocument',
            'lines.account', 'lines.item', 'lines.uom', 'lines.taxCode',
            'lines.purchaseOrderLine.purchaseOrder',
            'lines.receiptAllocations.goodsReceiptLine.goodsReceipt',
            'createdBy', 'approvedBy', 'postedBy',
        ]);
        $logoPath = $settings->value('logo_path');
        $sourceLines = $document->lines->flatMap(fn ($line) => $line->receiptAllocations->map(fn ($allocation) => $allocation->goodsReceiptLine))->filter();
        $bytes = $renderer->renderView('Purchasing::pdf.purchase-document', [
            'document' => $document,
            'referencePos' => $document->lines->map(fn ($line) => $line->purchaseOrderLine?->purchaseOrder)->filter()->unique('id')->values(),
            'referenceGrs' => $sourceLines->map(fn ($line) => $line->goodsReceipt)->filter()->unique('id')->values(),
            'logo' => $logoPath && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->path($logoPath) : null,
            'companyName' => (string) ($settings->value('company_name') ?: config('app.name')),
            'companyAddress' => (string) ($document->warehouse?->branch?->tax_address ?: $settings->value('company_address')),
            'companyTaxId' => (string) ($settings->value('tax_id') ?: ''),
            'companyTaxBranchCode' => $document->warehouse?->branch?->tax_branch_code,
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
            'decimalPlaces' => max(0, min(4, (int) ($settings->value('tax_decimal_places') ?? 2))),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($document->document_number).'.pdf"',
        ]);
    }

    public function landedCost(Request $request, LandedCost $landedCost, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        abort_unless((int) $landedCost->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
        $document = $landedCost->load([
            'warehouse.branch', 'lines.account', 'receipts.goodsReceipt.supplier',
            'allocations.item', 'allocations.uom', 'allocations.goodsReceiptLine.goodsReceipt',
            'createdBy', 'postedBy',
        ]);
        $targets = $document->allocations->groupBy('goods_receipt_line_id')->map(function ($allocations): array {
            $allocation = $allocations->first();
            $before = BigDecimal::of((string) ($allocation->goodsReceiptLine?->total_cost ?? '0'));
            $added = $allocations->reduce(
                fn (BigDecimal $sum, $row): BigDecimal => $sum->plus((string) $row->allocated_amount),
                BigDecimal::zero(),
            );

            return [
                'receipt_number' => $allocation->goodsReceiptLine?->goodsReceipt?->receipt_number,
                'item' => $allocation->item,
                'uom' => $allocation->uom,
                'quantity' => $allocation->goodsReceiptLine?->stock_quantity,
                'basis' => $allocation->basis_amount,
                'ratio' => $allocation->allocation_ratio,
                'before' => $before->__toString(),
                'added' => $added->__toString(),
                'after' => $before->plus($added)->__toString(),
            ];
        })->values();
        $logoPath = $settings->value('logo_path');
        $bytes = $renderer->renderView('Purchasing::pdf.landed-cost', [
            'document' => $document,
            'targets' => $targets,
            'logo' => $logoPath && Storage::disk('public')->exists($logoPath) ? Storage::disk('public')->path($logoPath) : null,
            'companyName' => (string) ($settings->value('company_name') ?: config('app.name')),
            'companyAddress' => (string) ($document->warehouse?->branch?->tax_address ?: $settings->value('company_address')),
            'companyTaxId' => (string) ($settings->value('tax_id') ?: ''),
            'companyTaxBranchCode' => $document->warehouse?->branch?->tax_branch_code,
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
            'decimalPlaces' => max(0, min(4, (int) ($settings->value('tax_decimal_places') ?? 2))),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($document->document_number).'.pdf"',
        ]);
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
