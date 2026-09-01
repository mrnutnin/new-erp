<?php

namespace App\Modules\Asset\Services;

use App\Modules\Wms\Models\PurchaseDocumentLine;
use Illuminate\Database\Eloquent\Builder;

final class PurchaseAssetSourceEligibilityService
{
    public const SOURCE_TYPE = 'PURCHASE_DOCUMENT';

    /**
     * Purchase Invoice lines that may become an Asset capitalization source.
     *
     * The future capitalization service owns allocation ceilings. A purchase
     * line therefore remains selectable after a partial allocation and exposes
     * the immutable source amount and quantity needed to enforce that ceiling.
     *
     * @return Builder<PurchaseDocumentLine>
     */
    public function eligibleLinesForBranch(int $branchId, bool $includeManualException = false): Builder
    {
        return PurchaseDocumentLine::query()
            ->select('purchase_document_lines.*')
            ->addSelect([
                'purchase_documents.id as source_document_id',
                'purchase_documents.document_number as source_document_number',
                'purchase_documents.document_date as source_document_date',
                'purchase_documents.supplier_id as source_supplier_id',
                'purchase_document_lines.id as source_line_id',
                'purchase_document_lines.line_number as source_line_number',
                'purchase_document_lines.quantity as source_quantity',
                'purchase_document_lines.unit_price as source_unit_price',
                'purchase_document_lines.net_amount as source_net_amount',
                'wms_items.default_asset_category_id as default_asset_category_id',
            ])
            ->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_document_lines.purchase_document_id')
            ->leftJoin('wms_items', 'wms_items.id', '=', 'purchase_document_lines.item_id')
            ->where('purchase_documents.branch_id', $branchId)
            ->where('purchase_documents.document_type', 'INVOICE')
            ->whereIn('purchase_documents.status', ['APPROVED', 'POSTED'])
            ->when(! $includeManualException, fn (Builder $query) => $query->where('wms_items.is_asset_capitalizable', true)->where('wms_items.is_active', true))
            ->with(['document.supplier', 'account', 'item.defaultAssetCategory'])
            ->orderByDesc('purchase_documents.document_date')
            ->orderBy('purchase_document_lines.line_number');
    }
}
